<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerMatchReview;
use App\Models\CustomerPhone;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerIdentity;
use App\Services\CustomerLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $this->brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'name' => 'Centre', 'code' => 'C1']);
    $this->other = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'name' => 'Gare', 'code' => 'G1']);
    $this->admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Admin groupe')->value('id'),
    ]);
    Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Boissons']);
});

function importLines($test, array $lines): ImportBatch
{
    $csv = implode("\n", array_map(fn ($l) => implode(';', $l), [['Date', 'Magasin', 'Ticket', 'Téléphone', 'Nom', 'Famille', 'Montant'], ...$lines]))."\n";
    $test->actingAs($test->admin)->post('/sales-imports', ['file' => UploadedFile::fake()->createWithContent('v'.uniqid().'.csv', $csv)]);
    $batch = ImportBatch::latest('id')->firstOrFail();
    $test->actingAs($test->admin)->post("/sales-imports/{$batch->id}/mapping", ['mapping' => $batch->mapping]);
    $test->actingAs($test->admin)->post("/sales-imports/{$batch->id}/confirm");

    return $batch->refresh();
}

it('keeps the phone history and consents of a customer', function () {
    $contact = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24162605054', 'consent_status' => 'opt_in']);
    $customer = $contact->customer;

    expect(CustomerPhone::where('customer_id', $customer->id)->where('is_primary', true)->value('phone_e164'))->toBe('24162605054')
        ->and(CustomerConsent::where('customer_id', $customer->id)->where('store_id', $this->store->id)->value('status'))->toBe('opt_in');

    app(CustomerLifecycle::class)->changePhone($customer, '24174000000');

    $phones = CustomerPhone::where('customer_id', $customer->id)->get()->keyBy('phone_e164');
    expect($phones['24174000000']->is_primary)->toBeTrue()
        ->and($phones['24162605054']->is_primary)->toBeFalse()
        ->and($phones['24162605054']->valid_to)->not->toBeNull();

    $contact->update(['consent_status' => 'opt_out']);
    expect(CustomerConsent::where('customer_id', $customer->id)->value('status'))->toBe('opt_out');
});

it('does not attach a known number with a very different name and queues it for review', function () {
    importLines($this, [['01/09/2026', 'C1', 'T1', '62605054', 'Awa Ndong', 'Boissons', '1000']]);
    $customer = Customer::sole();

    // Même numéro, même personne (nom proche) : rattachement direct.
    importLines($this, [['02/09/2026', 'C1', 'T2', '62605054', 'AWA N.', 'Boissons', '500']]);
    expect(CustomerMatchReview::count())->toBe(0)->and($customer->fresh()->total_spent)->toBe(1500);

    // Même numéro, nom sans rapport : pas de fusion automatique.
    importLines($this, [['03/09/2026', 'G1', 'T3', '62605054', 'Jean Moussavou', 'Boissons', '9000']]);
    $review = CustomerMatchReview::sole();
    $held = Purchase::where('receipt_number', 'T3')->sole();

    expect($review->status)->toBe('pending')
        ->and($review->incoming_name)->toBe('Jean Moussavou')
        ->and($held->contact_id)->toBeNull()
        ->and($held->buyer_name)->toBe('Jean Moussavou')
        ->and($customer->fresh()->total_spent)->toBe(1500)
        ->and(Customer::count())->toBe(1);

    $this->actingAs($this->admin)->get('/customers/a-verifier')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Customers/Reviews')->where('pendingCount', 1)->where('reviews.data.0.purchases.0.amount', 9000));

    // Décision : même personne → rattachée, journalisée.
    $this->actingAs($this->admin)->post("/customers/a-verifier/{$review->id}", ['decision' => 'attached'])->assertSessionHas('success');
    expect($held->fresh()->contact_id)->not->toBeNull()
        ->and($customer->fresh()->total_spent)->toBe(10500)
        ->and(AuditLog::where('action', 'customer.review')->exists())->toBeTrue();
});

it('marks the number as shared when the user keeps the sale separate', function () {
    importLines($this, [['01/09/2026', 'C1', 'T1', '62605054', 'Awa Ndong', 'Boissons', '1000']]);
    importLines($this, [['03/09/2026', 'C1', 'T3', '62605054', 'Jean Moussavou', 'Boissons', '9000']]);
    $review = CustomerMatchReview::sole();

    $this->actingAs($this->admin)->post("/customers/a-verifier/{$review->id}", ['decision' => 'separate']);

    $customer = Customer::sole();
    expect(Purchase::where('receipt_number', 'T3')->value('contact_id'))->toBeNull()
        ->and($customer->hasSharedPhone())->toBeTrue()
        ->and($customer->total_spent)->toBe(1000);
});

it('merges two customers with their sales and splits them back, both audited', function () {
    $a = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24162605054', 'name' => 'Awa Ndong']);
    $b = Contact::factory()->create(['store_id' => $this->other->id, 'phone_e164' => '24166000000', 'name' => 'Awa Ndong (bureau)', 'consent_status' => 'opt_in']);
    Purchase::create(['contact_id' => $a->id, 'store_id' => $this->store->id, 'amount' => 1000, 'purchased_at' => today()->subDays(10)]);
    Purchase::create(['contact_id' => $b->id, 'store_id' => $this->other->id, 'amount' => 4000, 'purchased_at' => today()->subDays(5)]);
    $keep = $a->customer;
    $duplicate = $b->customer;

    $this->actingAs($this->admin)->post("/customers/{$keep->id}/merge", ['source_id' => $duplicate->id])->assertRedirect("/customers/{$keep->id}");

    $keep->refresh();
    expect(Customer::find($duplicate->id))->toBeNull()
        ->and($keep->total_spent)->toBe(5000)
        ->and($keep->purchases_count)->toBe(2)
        ->and($keep->previous_phones)->toContain('24166000000')
        ->and(CustomerConsent::where('customer_id', $keep->id)->where('store_id', $this->other->id)->value('status'))->toBe('opt_in')
        ->and(AuditLog::where('action', 'customer.merged')->sole()->payload['phone'])->toBe('24166000000');

    $this->actingAs($this->admin)->post("/customers/{$keep->id}/split", ['contact_ids' => [$b->id]])->assertRedirect();

    $split = Customer::where('phone_e164', '24166000000')->sole();
    expect($split->total_spent)->toBe(4000)
        ->and($keep->fresh()->total_spent)->toBe(1000)
        ->and(AuditLog::where('action', 'customer.split')->exists())->toBeTrue();
});

it('refuses merges to roles without the right', function () {
    $a = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24162605054']);
    $b = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24166000000']);
    $marketing = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Marketing')->value('id'),
    ]);

    $this->actingAs($marketing)->post("/customers/{$a->customer_id}/merge", ['source_id' => $b->customer_id])->assertForbidden();
    expect(Customer::count())->toBe(2);
});

it('compares names without accents, case or initials', function (string $a, string $b, bool $same) {
    expect(CustomerIdentity::namesMatch($a, $b))->toBe($same);
})->with([
    ['Awa Ndong', 'AWA N.', true],
    ['Élodie Mba', 'elodie mba', true],
    ['Dépôt Mbolo', 'DEPOT MBOLO SARL', true],
    ['Awa Ndong', 'Jean Moussavou', false],
]);
