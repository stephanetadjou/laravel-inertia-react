<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;

it('lists purchases scoped to the user organization with totals', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $contact = Contact::factory()->create(['store_id' => $store->id]);
    Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => 10000, 'quantity' => 2, 'purchased_at' => now()]);
    Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => 5000, 'quantity' => 1, 'purchased_at' => now()->subDay()]);

    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id]);
    Purchase::factory()->create(['contact_id' => $foreignContact->id, 'store_id' => $otherStore->id, 'amount' => 99999, 'purchased_at' => now()]);

    $response = $this->actingAs($user)->get('/purchases');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('kpis.current.purchases', 2)
        ->where('kpis.current.revenue', 15000)
        ->where('kpis.current.quantity', 3)
        ->has('purchases.data', 2),
    );
});

it('previews a purchase import without writing to the database, flagging which contacts are new', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Adidas']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    Contact::factory()->create(['store_id' => $store->id, 'name' => 'Amanda Obame', 'phone_e164' => '24174129782']);

    $csv = "telephone,boutique,nom,categorie,quantite,prix,date_achat\n".
        "74129782,Adidas,,Sneakers,2,25000,2026-03-01\n". // contact déjà existant
        "60000099,Adidas,Nouveau Client,Sneakers,1,10000,2026-03-02\n". // contact à créer
        "123,Adidas,,Sneakers,1,10000,2026-03-03\n"; // numéro invalide -> ignoré

    $file = UploadedFile::fake()->createWithContent('achats.csv', $csv);

    $response = $this->actingAs($user)->post('/purchases/preview', ['file' => $file]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('preview.purchases_to_record', 2)
        ->where('preview.contacts_to_create', 1)
        ->where('preview.total_amount', 60000)
        ->has('preview.skipped', 1)
        ->where('preview.sample.0.new_contact', false)
        ->where('preview.sample.1.new_contact', true)
        ->where('preview.sample.1.contact_name', 'Nouveau Client'),
    );

    expect(Purchase::count())->toBe(0);
    expect(Contact::count())->toBe(1);
});

it('creates a new contact when the purchase history references an unknown phone number', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Grand Store']);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $csv = "telephone,boutique,nom,prix,date_achat\n".
        "65000000,Grand Store,Nouvelle Cliente,20000,2026-06-01\n";

    $file = UploadedFile::fake()->createWithContent('achats.csv', $csv);

    $response = $this->actingAs($user)->post('/purchases', ['file' => $file]);

    $response->assertRedirect(route('purchases.index'));

    $contact = Contact::where('store_id', $store->id)->where('phone_e164', '24165000000')->first();
    expect($contact)->not->toBeNull();
    expect($contact->name)->toBe('Nouvelle Cliente');
    expect($contact->consent_status)->toBe('unknown');
    expect(Purchase::where('contact_id', $contact->id)->count())->toBe(1);
});

it('imports a purchase history and computes the amount from quantity and unit price', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Mango']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24166212551']);

    $csv = "telephone,boutique,categorie,quantite,prix,date_achat\n".
        "66212551,Mango,Robes,3,15000,2026-04-10\n";

    $file = UploadedFile::fake()->createWithContent('achats.csv', $csv);

    $response = $this->actingAs($user)->post('/purchases', ['file' => $file]);

    $response->assertRedirect(route('purchases.index'));
    $response->assertSessionHas('success');

    $purchase = Purchase::first();
    expect($purchase->contact_id)->toBe($contact->id);
    expect($purchase->quantity)->toBe(3);
    expect($purchase->amount)->toBe(45000);
    expect($purchase->product_category)->toBe('Robes');
});

it('uses the montant column directly when both montant and prix are present', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Carré']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24160000001']);

    $csv = "telephone,boutique,quantite,prix,montant,date_achat\n".
        "60000001,Carré,4,1000,99999,2026-05-01\n";

    $file = UploadedFile::fake()->createWithContent('achats.csv', $csv);

    $this->actingAs($user)->post('/purchases', ['file' => $file]);

    expect(Purchase::first()->amount)->toBe(99999);
});

it('skips a row whose store does not belong to the organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $foreignStore = Store::factory()->create(['organization_id' => $otherOrganization->id, 'name' => 'Boutique Rivale']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    Contact::factory()->create(['store_id' => $foreignStore->id, 'phone_e164' => '24170000000']);

    $csv = "telephone,boutique,prix,date_achat\n".
        "70000000,Boutique Rivale,10000,2026-05-01\n";

    $file = UploadedFile::fake()->createWithContent('achats.csv', $csv);

    $this->actingAs($user)->post('/purchases', ['file' => $file]);

    expect(Purchase::count())->toBe(0);
});
