<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    $this->organization = Organization::factory()->create();
    $this->adidas = Store::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $this->mango = Store::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mango']);
});

function buy(Contact $contact, int $amount, int $daysAgo): Purchase
{
    return Purchase::factory()->create([
        'contact_id' => $contact->id,
        'store_id' => $contact->store_id,
        'amount' => $amount,
        'purchased_at' => now()->subDays($daysAgo),
    ]);
}

it('links contacts of every store to one customer per phone number, most recent name wins', function () {
    $a = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha M.', 'gender' => 'F']);
    $b = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha Mba']);
    // Nom par défaut de l'import d'achats : ne remplace pas le vrai nom.
    $c = Contact::factory()->create(['store_id' => Store::factory()->create(['organization_id' => $this->organization->id])->id, 'phone_e164' => '24177451288', 'name' => 'Client 24177451288']);

    expect($a->customer_id)->toBe($b->customer_id)->toBe($c->customer_id);
    expect(Customer::count())->toBe(1);
    $customer = Customer::first();
    expect($customer->name)->toBe('Aïcha Mba');
    expect($customer->gender)->toBe('F');

    // Même numéro dans une autre organisation : une autre fiche.
    Contact::factory()->create(['store_id' => Store::factory()->create()->id, 'phone_e164' => '24177451288']);
    expect(Customer::count())->toBe(2);
});

it('keeps a manually confirmed identity', function () {
    $contact = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha']);
    $contact->customer->update(['name' => 'Aïcha Mba Obame', 'identity_confirmed_at' => now()]);

    Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24177451288', 'name' => 'A. M.']);

    expect($contact->customer->fresh()->name)->toBe('Aïcha Mba Obame');
});

it('computes counters and the lifecycle stage from purchases in every store', function () {
    $a = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288', 'consent_status' => 'unknown']);
    $customer = $a->customer->fresh();
    expect($customer->lifecycle_stage)->toBe(Customer::STAGE_CONTACT);

    $a->update(['consent_status' => 'opt_in']);
    expect($customer->fresh()->lifecycle_stage)->toBe(Customer::STAGE_PROSPECT);

    buy($a, 20000, 10);
    $b = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24177451288']);
    buy($b, 30000, 2);

    $customer->refresh();
    expect($customer->purchases_count)->toBe(2);
    expect($customer->total_spent)->toBe(50000);
    expect($customer->first_purchase_at->toDateString())->toBe('2026-09-20');
    expect($customer->last_purchase_at->toDateString())->toBe('2026-09-28');
    expect($customer->lifecycle_stage)->toBe(Customer::STAGE_NEW);

    expect($customer->stageChanges()->orderBy('id')->pluck('to_stage')->all())
        ->toBe([Customer::STAGE_CONTACT, Customer::STAGE_PROSPECT, Customer::STAGE_NEW]);
});

it('computes the VIP status store by store', function () {
    // Adidas : Aïcha est la meilleure acheteuse. Mango : elle achète peu.
    $aichaAdidas = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288']);
    $aichaMango = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24177451288']);
    buy($aichaAdidas, 500000, 10);
    buy($aichaMango, 5000, 10);

    $mangoBuyers = collect(range(1, 9))->map(function ($i) {
        $contact = Contact::factory()->create(['store_id' => $this->mango->id]);
        buy($contact, 10000 * $i, 5);

        return $contact;
    });

    $this->artisan('crm:refresh-lifecycle')->assertSuccessful();

    expect($aichaAdidas->fresh()->is_vip)->toBeTrue();
    expect($aichaAdidas->fresh()->vip_since)->not->toBeNull();
    expect($aichaMango->fresh()->is_vip)->toBeFalse();
    // Mango : 10 acheteurs, top 10 % = le meilleur (90 000).
    expect($mangoBuyers->last()->fresh()->is_vip)->toBeTrue();
    expect($mangoBuyers->first()->fresh()->is_vip)->toBeFalse();

    // Le statut VIP n'est pas une étape du Groupe.
    expect($aichaAdidas->customer->fresh()->lifecycle_stage)->toBe(Customer::STAGE_NEW);

    // VIP désactivé (0 %) : plus aucun VIP.
    $this->organization->update(['lifecycle_settings' => ['vip_top_percent' => 0]]);
    $this->artisan('crm:refresh-lifecycle')->assertSuccessful();
    expect($aichaAdidas->fresh()->is_vip)->toBeFalse();
    expect($aichaAdidas->fresh()->vip_since)->toBeNull();
});

it('classifies new, active, at-risk and churned customers with the default thresholds', function () {
    $stages = [];
    foreach ([
        'new' => [[5000, 30]],
        'active' => [[5000, 200], [5000, 20]],
        'at_risk' => [[5000, 120]],
        'churned' => [[5000, 400]],
    ] as $expected => $purchases) {
        $contact = Contact::factory()->create(['store_id' => $this->adidas->id]);
        foreach ($purchases as [$amount, $daysAgo]) {
            buy($contact, $amount, $daysAgo);
        }
        $stages[$expected] = $contact->customer_id;
    }
    $this->artisan('crm:refresh-lifecycle')->assertSuccessful();

    foreach ($stages as $expected => $customerId) {
        expect(Customer::find($customerId)->lifecycle_stage)->toBe($expected);
    }
});

it('uses the thresholds configured for the organization', function () {
    $this->organization->update(['lifecycle_settings' => ['at_risk_days' => 20, 'churn_days' => 40, 'vip_top_percent' => 0]]);
    $contact = Contact::factory()->create(['store_id' => $this->adidas->id]);
    buy($contact, 5000, 30);

    expect($contact->customer->fresh()->lifecycle_stage)->toBe(Customer::STAGE_AT_RISK);

    $this->travel(15)->days();
    $this->artisan('crm:refresh-lifecycle')->assertSuccessful();
    expect($contact->customer->fresh()->lifecycle_stage)->toBe(Customer::STAGE_CHURNED);
});

it('keeps the old number when a person changes number', function () {
    $a = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288']);
    $b = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24177451288']);

    $a->update(['phone_e164' => '24166000111']);

    $customer = $a->customer->fresh();
    expect($customer->phone_e164)->toBe('24166000111');
    expect($customer->previous_phones)->toBe(['24177451288']);
    expect($b->fresh()->phone_e164)->toBe('24166000111');
});

it('moves a contact to the existing customer that owns its new number', function () {
    $aicha = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288']);
    $duplicate = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24166000111']);
    $duplicateCustomerId = $duplicate->customer_id;

    $duplicate->update(['phone_e164' => '24177451288']);

    expect($duplicate->fresh()->customer_id)->toBe($aicha->customer_id);
    expect(Customer::find($duplicateCustomerId))->toBeNull();
    expect($aicha->customer->fresh()->previous_phones)->toBe(['24166000111']);
});

it('deletes a customer with its last contact', function () {
    $contact = Contact::factory()->create(['store_id' => $this->adidas->id]);
    $customerId = $contact->customer_id;

    $contact->delete();

    expect(Customer::find($customerId))->toBeNull();
});

it('refreshes customers once at the end of a purchase import', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id]);
    $csv = "telephone,boutique,nom,prix,date_achat\n".
        '77451288,Adidas,Aïcha Mba,25000,'.now()->subDays(3)->toDateString()."\n".
        '77451288,Mango,,15000,'.now()->subDay()->toDateString()."\n";

    $this->actingAs($user)->post('/purchases', ['file' => UploadedFile::fake()->createWithContent('achats.csv', $csv)]);

    $customer = Customer::where('phone_e164', '24177451288')->firstOrFail();
    expect($customer->contacts()->count())->toBe(2);
    expect($customer->name)->toBe('Aïcha Mba');
    expect($customer->purchases_count)->toBe(2);
    expect($customer->total_spent)->toBe(40000);
});

it('links contacts created without a customer when the command runs', function () {
    $contact = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288']);
    DB::table('contacts')->where('id', $contact->id)->update(['customer_id' => null]);
    DB::table('customers')->delete();
    expect($contact->fresh()->customer_id)->toBeNull();

    $this->artisan('crm:refresh-lifecycle')->assertSuccessful();

    expect($contact->fresh()->customer_id)->not->toBeNull();
});

it('lets an administrator change the thresholds and recalculates the customers', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);
    $contact = Contact::factory()->create(['store_id' => $this->adidas->id]);
    buy($contact, 5000, 30);
    expect($contact->customer->fresh()->lifecycle_stage)->toBe(Customer::STAGE_NEW);

    $this->actingAs($admin)->get('/organisation')
        ->assertInertia(fn ($page) => $page->where('lifecycleSettings.churn_days', 180));

    $this->actingAs($admin)->put('/organisation/lifecycle', [
        'new_customer_days' => 60,
        'at_risk_days' => 10,
        'churn_days' => 20,
        'vip_top_percent' => 0,
        'vip_period_days' => 365,
    ])->assertSessionHas('success');

    expect($this->organization->fresh()->lifecycleSettings()['churn_days'])->toBe(20);
    expect($contact->customer->fresh()->lifecycle_stage)->toBe(Customer::STAGE_CHURNED);

    $this->actingAs($admin)->put('/organisation/lifecycle', [
        'new_customer_days' => 60, 'at_risk_days' => 30, 'churn_days' => 20, 'vip_top_percent' => 10, 'vip_period_days' => 365,
    ])->assertSessionHasErrors('at_risk_days');
});

it('targets a campaign segment by lifecycle stage, opt-in only', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);
    $atRisk = Contact::factory()->create(['store_id' => $this->adidas->id, 'consent_status' => 'opt_in']);
    buy($atRisk, 5000, 120);
    $atRiskNoConsent = Contact::factory()->create(['store_id' => $this->adidas->id, 'consent_status' => 'unknown']);
    buy($atRiskNoConsent, 5000, 120);
    $recent = Contact::factory()->create(['store_id' => $this->adidas->id, 'consent_status' => 'opt_in']);
    buy($recent, 50000, 5);

    $count = fn (array $stages) => $this->actingAs($admin)
        ->getJson('/campaigns/segment-count?'.http_build_query(['store_ids' => [$this->adidas->id], 'lifecycle_stages' => $stages]))
        ->json('count');

    expect($count(['at_risk']))->toBe(1);
    expect($count(['at_risk', 'new', 'active']))->toBe(2);

    // VIP de la boutique uniquement : 2 acheteurs Adidas opt-in, top 10 % = 1.
    // L'ancien « vip » n'est plus une étape : refusé comme étape.
    $vipCount = $this->actingAs($admin)
        ->getJson('/campaigns/segment-count?'.http_build_query(['store_ids' => [$this->adidas->id], 'vip_only' => 1]))
        ->json('count');
    expect($vipCount)->toBe(1);
    $this->actingAs($admin)->get('/campaigns/segment-count?'.http_build_query(['store_ids' => [$this->adidas->id], 'lifecycle_stages' => ['vip']]))
        ->assertSessionHasErrors('lifecycle_stages.0');
    expect($count([]))->toBe(2);

    $this->actingAs($admin)->get('/campaigns/segment-count?'.http_build_query(['store_ids' => [$this->adidas->id], 'lifecycle_stages' => ['inconnu']]))
        ->assertSessionHasErrors('lifecycle_stages.0');
});
