<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('refuses to update a store belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->put("/stores/{$otherStore->id}", [
        'name' => 'hack',
    ])->assertStatus(403);
});

it('lets an organization manager rename a store and move it to another brand', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Ancien nom']);
    $newBrand = Brand::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->put("/stores/{$store->id}", [
        'name' => 'Mango Glass',
        'brand_id' => $newBrand->id,
        'whatsapp_phone_number_id' => $store->whatsapp_phone_number_id,
    ])->assertSessionHas('success');

    $store->refresh();
    expect($store->name)->toBe('Mango Glass');
    expect($store->brand_id)->toBe($newBrand->id);
});

it('rejects moving a store to a brand of another organization', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $foreignBrand = Brand::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->put("/stores/{$store->id}", ['brand_id' => $foreignBrand->id])
        ->assertSessionHasErrors('brand_id');

    expect($store->fresh()->brand_id)->not->toBe($foreignBrand->id);
});

it('refuses a store manager renaming a store', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Carré Louis']);
    $manager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($manager)->put("/stores/{$store->id}", ['name' => 'Renommée'])->assertStatus(403);

    expect($store->fresh()->name)->toBe('Carré Louis');
});

it('lists stores with their connection mode and the brands for managers', function () {
    config(['services.meta_whatsapp.token' => 'shared', 'services.meta_whatsapp.phone_number_id' => '123']);

    $organization = Organization::factory()->sharedWhatsAppAccount()->create();
    $own = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'A propre']);
    $shared = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'B partagé', 'whatsapp_phone_number_id' => null, 'whatsapp_access_token' => null]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->get('/stores')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canManage', true)
            ->where('sharedAccountConfigured', true)
            ->has('brands', 2)
            ->where('stores.0.id', $own->id)
            ->where('stores.0.connection', 'own')
            ->where('stores.1.id', $shared->id)
            ->where('stores.1.connection', 'shared'));
});

it('shows the loyalty QR code page with the WhatsApp number read from Meta', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['verified_name' => 'Mango', 'display_phone_number' => '+241 77 00 00 01'], 200),
    ]);

    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Mango',
        'whatsapp_access_token' => 'valid-token',
        'whatsapp_phone_number_id' => '654321',
    ]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->get("/stores/{$store->id}/qr")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Stores/Qr')
            ->where('store.name', 'Mango')
            ->where('whatsappNumber', '24177000001')
            ->where('message', 'OUI Mango'));

    // Numéro mis en cache : servi même si Meta ne répond plus.
    expect(Cache::get('whatsapp-display-number:654321'))->toBe('24177000001');
    Http::fake(['graph.facebook.com/*' => Http::response([], 500)]);
    $this->actingAs($user)->get("/stores/{$store->id}/qr")->assertInertia(fn ($page) => $page->where('whatsappNumber', '24177000001'));
});

it('asks for the number on the QR code page when Meta does not answer', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401)]);

    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_access_token' => 'invalid-token',
        'whatsapp_phone_number_id' => '654321',
    ]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->get("/stores/{$store->id}/qr")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('whatsappNumber', null));
});

it('refuses the QR code page of a store outside the user scope', function () {
    $organization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->get("/stores/{$otherStore->id}/qr")->assertStatus(403);
});
