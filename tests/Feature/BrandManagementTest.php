<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('creates a brand as super admin', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $response = $this->actingAs($user)->post('/brands', ['name' => 'Adidas']);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Brand::where('organization_id', $organization->id)->where('name', 'Adidas')->exists())->toBeTrue();
});

it('refuses a brand_manager access to brand management routes', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de marque')->id,
        'brand_id' => $brand->id,
    ]);

    $this->actingAs($user)->post('/brands', ['name' => 'Nouvelle marque'])->assertStatus(403);
    $this->actingAs($user)->get('/organisation')->assertStatus(403);
    $this->actingAs($user)->get('/users')->assertStatus(403);
});

it('refuses a store_manager access to organisation management routes', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($user)->get('/organisation')->assertStatus(403);
    $this->actingAs($user)->get('/users')->assertStatus(403);
});

it('allows both admin and super_admin to manage the organisation', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);
    $superAdmin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $this->actingAs($admin)->get('/organisation')->assertOk();
    $this->actingAs($superAdmin)->get('/organisation')->assertOk();
});

it('refuses to delete a brand that still has stores', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);

    $response = $this->actingAs($user)->delete("/brands/{$brand->id}");

    $response->assertSessionHas('error');
    expect(Brand::find($brand->id))->not->toBeNull();
});

it('refuses to manage a brand belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $foreignBrand = Brand::factory()->create(['organization_id' => $otherOrganization->id]);

    $this->actingAs($user)->put("/brands/{$foreignBrand->id}", ['name' => 'Hack'])->assertStatus(403);
    $this->actingAs($user)->delete("/brands/{$foreignBrand->id}")->assertStatus(403);
});

it('creates a store under a brand', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $brand = Brand::factory()->create(['organization_id' => $organization->id, 'name' => 'Adidas']);

    $response = $this->actingAs($user)->post('/stores', ['brand_id' => $brand->id, 'name' => 'Adidas Glass']);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Store::where('brand_id', $brand->id)->where('name', 'Adidas Glass')->exists())->toBeTrue();
});

it('refuses to delete a store that still has contacts', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    Contact::factory()->create(['store_id' => $store->id]);

    $response = $this->actingAs($user)->delete("/stores/{$store->id}");

    $response->assertSessionHas('error');
    expect(Store::find($store->id))->not->toBeNull();
});
