<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    $this->organization = Organization::factory()->create();
    $this->adidasBrand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $this->mangoBrand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mango']);
    $this->adidas = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->adidasBrand->id, 'name' => 'Adidas Glass']);
    $this->mango = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->mangoBrand->id, 'name' => 'Mango Mbolo']);

    // Aïcha achète dans les deux marques : une seule fiche.
    $this->aichaAdidas = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha Mba', 'consent_status' => 'opt_in']);
    $this->aichaMango = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha Mba', 'consent_status' => 'unknown']);
    Purchase::factory()->create(['contact_id' => $this->aichaAdidas->id, 'store_id' => $this->adidas->id, 'amount' => 20000, 'purchased_at' => now()->subDays(5)]);
    Purchase::factory()->create(['contact_id' => $this->aichaMango->id, 'store_id' => $this->mango->id, 'amount' => 70000, 'purchased_at' => now()->subDays(2)]);
    $this->aicha = $this->aichaAdidas->customer->fresh();

    // Brice : seulement chez Mango, sans achat.
    $this->brice = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24166210347', 'name' => 'Brice Obame', 'consent_status' => 'unknown'])->customer;
});

it('lists one row per person with group-wide counters and stage counts', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($admin)->get('/customers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Index')
            ->where('customers.total', 2)
            ->where('customers.data.0.id', $this->aicha->id)
            ->where('customers.data.0.purchases_count', 2)
            ->where('customers.data.0.total_spent', 90000)
            ->where('customers.data.0.stores', [['name' => 'Adidas Glass', 'vip' => true, 'consent' => 'opt_in'], ['name' => 'Mango Mbolo', 'vip' => true, 'consent' => 'unknown']])
            ->where('customers.data.0.opt_in', true)
            ->where('stages', fn ($stages) => collect($stages)->firstWhere('key', 'contact')['count'] === 1));

    $this->actingAs($admin)->get('/customers?stage=contact')
        ->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.id', $this->brice->id));

    $this->actingAs($admin)->get('/customers?q=obame')
        ->assertInertia(fn ($page) => $page->where('customers.total', 1));

    $this->actingAs($admin)->get("/customers?brand={$this->adidasBrand->id}")
        ->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.stores', [['name' => 'Adidas Glass', 'vip' => true, 'consent' => 'opt_in']]));
});

it('limits a brand manager to their brand, counters included', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de marque')->id,
        'brand_id' => $this->adidasBrand->id,
    ]);

    $this->actingAs($manager)->get('/customers')
        ->assertInertia(fn ($page) => $page
            ->where('customers.total', 1)
            ->where('customers.data.0.total_spent', 20000)
            ->where('customers.data.0.stores', [['name' => 'Adidas Glass', 'vip' => true, 'consent' => 'opt_in']]));

    $this->actingAs($manager)->get("/customers/{$this->aicha->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scoped', true)
            ->has('contacts', 1)
            ->has('purchases', 1)
            ->where('rfm.monetary', 20000));

    $this->actingAs($manager)->get("/customers/{$this->brice->id}")->assertStatus(403);
});

it('shows the whole customer to an organization-wide role', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($admin)->get("/customers/{$this->aicha->id}")
        ->assertInertia(fn ($page) => $page
            ->where('customer.name', 'Aïcha Mba')
            ->where('customer.stage', $this->aicha->lifecycle_stage)
            ->has('contacts', 2)
            ->has('purchases', 2)
            ->where('rfm.monetary', 90000)
            ->where('rfm.average_basket', 45000)
            ->where('purchases.0.amount', 70000)
            ->where('canEdit', true)
            ->has('stageHistory'));
});

it('confirms the identity and changes the number of every contact, keeping the old one', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($admin)->put("/customers/{$this->aicha->id}", [
        'name' => 'Aïcha Mba Obame',
        'phone_e164' => '066 00 01 11',
        'gender' => 'F',
        'birthdate' => '1990-05-12',
    ])->assertSessionHas('success');

    $aicha = $this->aicha->fresh();
    expect($aicha->name)->toBe('Aïcha Mba Obame');
    expect($aicha->identity_confirmed_at)->not->toBeNull();
    expect($aicha->phone_e164)->toBe('24166000111');
    expect($aicha->previous_phones)->toBe(['24177451288']);
    expect($this->aichaMango->fresh()->phone_e164)->toBe('24166000111');

    // Un nouveau contact ne remplace plus le nom confirmé.
    Contact::factory()->create(['store_id' => Store::factory()->create(['organization_id' => $this->organization->id])->id, 'phone_e164' => '24166000111', 'name' => 'A. M.']);
    expect($aicha->fresh()->name)->toBe('Aïcha Mba Obame');
});

it('refuses a number that already belongs to another customer', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($admin)->put("/customers/{$this->aicha->id}", [
        'name' => 'Aïcha',
        'phone_e164' => $this->brice->phone_e164,
    ])->assertSessionHasErrors('phone_e164');

    expect($this->aicha->fresh()->phone_e164)->toBe('24177451288');
});

it('refuses a customer of another organization', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);
    $foreign = Customer::factory()->create();

    $this->actingAs($admin)->get("/customers/{$foreign->id}")->assertStatus(403);
});

it('exports the filtered customers, one row per person', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id]);

    $csv = $this->actingAs($admin)->get('/customers/export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines)->toHaveCount(3); // en-tête + Aïcha + Brice
    expect($csv)->toContain('Aïcha Mba')->toContain('"Adidas Glass, Mango Mbolo"')->toContain('Brice Obame');

    $vipOnly = $this->actingAs($admin)->get('/customers/export?vip=1')->streamedContent();
    expect($vipOnly)->toContain('Aïcha Mba')->not->toContain('Brice Obame');
});

it('limits the export to the brand manager scope', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de marque')->id,
        'brand_id' => $this->adidasBrand->id,
    ]);

    $csv = $this->actingAs($manager)->get('/customers/export')->streamedContent();

    expect($csv)->toContain('Aïcha Mba')->toContain(',20000,')->not->toContain('Mango Mbolo')->not->toContain('Brice Obame');
});
