<?php

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

function makeBrandWithStore(Organization $organization, string $brandName): array
{
    $brand = Brand::factory()->create(['organization_id' => $organization->id, 'name' => $brandName]);
    $store = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);

    return [$brand, $store];
}

it('scopes the contacts list to a brand_manager\'s own brand', function () {
    $organization = Organization::factory()->create();
    [$adidas, $adidasStore] = makeBrandWithStore($organization, 'Adidas');
    [$mango, $mangoStore] = makeBrandWithStore($organization, 'Mango');

    $adidasContact = Contact::factory()->create(['store_id' => $adidasStore->id]);
    Contact::factory()->create(['store_id' => $mangoStore->id]);

    $brandManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de marque')->id,
        'brand_id' => $adidas->id,
    ]);

    $response = $this->actingAs($brandManager)->get('/contacts');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('contacts.data', 1)
        ->where('contacts.data.0.id', $adidasContact->id),
    );
});

it('scopes the contacts list to a store_manager\'s own store, even within the same brand', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $storeA = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $storeB = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);

    $contactA = Contact::factory()->create(['store_id' => $storeA->id]);
    Contact::factory()->create(['store_id' => $storeB->id]);

    $storeManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $storeA->id,
    ]);

    $response = $this->actingAs($storeManager)->get('/contacts');

    $response->assertInertia(fn ($page) => $page
        ->has('contacts.data', 1)
        ->where('contacts.data.0.id', $contactA->id),
    );
});

it('refuses a store_manager direct access to a contact outside their store', function () {
    $organization = Organization::factory()->create();
    [, $storeA] = makeBrandWithStore($organization, 'Adidas');
    [, $storeB] = makeBrandWithStore($organization, 'Mango');
    $foreignContact = Contact::factory()->create(['store_id' => $storeB->id]);

    $storeManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $storeA->id,
    ]);

    $this->actingAs($storeManager)->get("/contacts/{$foreignContact->id}")->assertStatus(403);
});

it('scopes the stores list to a brand_manager\'s own brand', function () {
    $organization = Organization::factory()->create();
    [$adidas, $adidasStore] = makeBrandWithStore($organization, 'Adidas');
    makeBrandWithStore($organization, 'Mango');

    $brandManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de marque')->id,
        'brand_id' => $adidas->id,
    ]);

    $response = $this->actingAs($brandManager)->get('/stores');

    $response->assertInertia(fn ($page) => $page
        ->has('stores', 1)
        ->where('stores.0.id', $adidasStore->id),
    );
});

it('refuses a store_manager updating a store outside their scope', function () {
    $organization = Organization::factory()->create();
    [, $storeA] = makeBrandWithStore($organization, 'Adidas');
    [, $storeB] = makeBrandWithStore($organization, 'Mango');

    $storeManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $storeA->id,
    ]);

    $this->actingAs($storeManager)->put("/stores/{$storeB->id}", ['whatsapp_phone_number_id' => '123'])->assertStatus(403);
});

it('scopes campaigns to the stores a brand_manager can see', function () {
    $organization = Organization::factory()->create();
    [$adidas, $adidasStore] = makeBrandWithStore($organization, 'Adidas');
    [, $mangoStore] = makeBrandWithStore($organization, 'Mango');

    $adidasCampaign = Campaign::factory()->create();
    $adidasCampaign->stores()->sync([$adidasStore->id]);

    $mangoCampaign = Campaign::factory()->create();
    $mangoCampaign->stores()->sync([$mangoStore->id]);

    $brandManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de marque')->id,
        'brand_id' => $adidas->id,
    ]);

    $response = $this->actingAs($brandManager)->get('/campaigns');

    $response->assertInertia(fn ($page) => $page
        ->has('campaigns.data', 1)
        ->where('campaigns.data.0.id', $adidasCampaign->id),
    );
});

it('scopes purchases to a store_manager\'s own store', function () {
    $organization = Organization::factory()->create();
    [, $storeA] = makeBrandWithStore($organization, 'Adidas');
    [, $storeB] = makeBrandWithStore($organization, 'Mango');

    $contactA = Contact::factory()->create(['store_id' => $storeA->id]);
    $contactB = Contact::factory()->create(['store_id' => $storeB->id]);
    $purchaseA = Purchase::factory()->create(['contact_id' => $contactA->id, 'store_id' => $storeA->id]);
    Purchase::factory()->create(['contact_id' => $contactB->id, 'store_id' => $storeB->id]);

    $storeManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $storeA->id,
    ]);

    $response = $this->actingAs($storeManager)->get('/purchases');

    $response->assertInertia(fn ($page) => $page
        ->has('purchases.data', 1)
        ->where('purchases.data.0.id', $purchaseA->id),
    );
});

it('scopes the dashboard counters to a brand_manager\'s own brand', function () {
    $organization = Organization::factory()->create();
    [$adidas, $adidasStore] = makeBrandWithStore($organization, 'Adidas');
    [, $mangoStore] = makeBrandWithStore($organization, 'Mango');

    Contact::factory()->create(['store_id' => $adidasStore->id, 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $mangoStore->id, 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $mangoStore->id, 'consent_status' => 'opt_in']);

    $brandManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de marque')->id,
        'brand_id' => $adidas->id,
    ]);

    $response = $this->actingAs($brandManager)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('stats.total_contacts', 1)
        ->has('stores', 1),
    );
});

it('does not restrict admin and super_admin to any brand or store', function () {
    $organization = Organization::factory()->create();
    [, $storeA] = makeBrandWithStore($organization, 'Adidas');
    [, $storeB] = makeBrandWithStore($organization, 'Mango');

    Contact::factory()->create(['store_id' => $storeA->id]);
    Contact::factory()->create(['store_id' => $storeB->id]);

    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);

    $response = $this->actingAs($admin)->get('/contacts');

    $response->assertInertia(fn ($page) => $page->has('contacts.data', 2));
});

it('scopes visibility to the assigned brand even for a custom role', function () {
    $organization = Organization::factory()->create();
    [$adidas, $adidasStore] = makeBrandWithStore($organization, 'Adidas');
    [, $mangoStore] = makeBrandWithStore($organization, 'Mango');

    $adidasContact = Contact::factory()->create(['store_id' => $adidasStore->id]);
    Contact::factory()->create(['store_id' => $mangoStore->id]);

    $customRole = Role::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Responsable Marketing Adidas',
        'scope' => Role::SCOPE_BRAND,
        'can_manage_organization' => false,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => $customRole->id,
        'brand_id' => $adidas->id,
    ]);

    $response = $this->actingAs($user)->get('/contacts');

    $response->assertInertia(fn ($page) => $page
        ->has('contacts.data', 1)
        ->where('contacts.data.0.id', $adidasContact->id),
    );
});
