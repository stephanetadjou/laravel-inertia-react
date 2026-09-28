<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('creates a brand_manager user scoped to a brand', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $brandManagerRole = defaultRole($organization, 'Responsable de marque');

    $response = $this->actingAs($admin)->post('/users', [
        'name' => 'Responsable Adidas',
        'email' => 'brand-manager@example.com',
        'password' => 'password123',
        'role_id' => $brandManagerRole->id,
        'brand_id' => $brand->id,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $created = User::where('email', 'brand-manager@example.com')->first();
    expect($created)->not->toBeNull();
    expect($created->role_id)->toBe($brandManagerRole->id);
    expect($created->brand_id)->toBe($brand->id);
    expect($created->store_id)->toBeNull();
});

it('creates a store_manager user scoped to a store', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $storeManagerRole = defaultRole($organization, 'Responsable de boutique');

    $response = $this->actingAs($admin)->post('/users', [
        'name' => 'Responsable Store',
        'email' => 'store-manager@example.com',
        'password' => 'password123',
        'role_id' => $storeManagerRole->id,
        'store_id' => $store->id,
    ]);

    $response->assertRedirect();

    $created = User::where('email', 'store-manager@example.com')->first();
    expect($created->role_id)->toBe($storeManagerRole->id);
    expect($created->store_id)->toBe($store->id);
    expect($created->brand_id)->toBeNull();
});

it('requires a brand for a brand_manager and a store for a store_manager', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $this->actingAs($admin)->post('/users', [
        'name' => 'Sans marque',
        'email' => 'nobrand@example.com',
        'password' => 'password123',
        'role_id' => defaultRole($organization, 'Responsable de marque')->id,
    ])->assertSessionHasErrors('brand_id');

    $this->actingAs($admin)->post('/users', [
        'name' => 'Sans store',
        'email' => 'nostore@example.com',
        'password' => 'password123',
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
    ])->assertSessionHasErrors('store_id');
});

it('updates a user without changing the password when left blank', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $target = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);
    $originalHash = $target->password;

    $response = $this->actingAs($admin)->put("/users/{$target->id}", [
        'name' => 'Nouveau nom',
        'email' => $target->email,
        'role_id' => $target->role_id,
    ]);

    $response->assertRedirect();
    expect($target->fresh()->name)->toBe('Nouveau nom');
    expect($target->fresh()->password)->toBe($originalHash);
});

it('refuses to delete your own account', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $response = $this->actingAs($admin)->delete("/users/{$admin->id}");

    $response->assertSessionHas('error');
    expect(User::find($admin->id))->not->toBeNull();
});

it('refuses to delete your own account even as the sole organization manager', function () {
    $organization = Organization::factory()->create();
    $superAdmin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $response = $this->actingAs($superAdmin)->delete("/users/{$superAdmin->id}");

    $response->assertSessionHas('error');
    expect(User::find($superAdmin->id))->not->toBeNull();
});

it('allows deleting an organization manager when another one remains', function () {
    $organization = Organization::factory()->create();
    $superAdmin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);

    $response = $this->actingAs($admin)->delete("/users/{$superAdmin->id}");

    $response->assertSessionHas('success');
    expect(User::find($superAdmin->id))->toBeNull();
});

it('refuses to manage a user belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $foreignUser = User::factory()->create(['organization_id' => $otherOrganization->id]);

    $this->actingAs($admin)->put("/users/{$foreignUser->id}", [
        'name' => 'Hack',
        'email' => $foreignUser->email,
        'role_id' => defaultRole($organization, 'Administrateur')->id,
    ])->assertStatus(403);

    $this->actingAs($admin)->delete("/users/{$foreignUser->id}")->assertStatus(403);
});

it('refuses to remove the last organization-manager capability from a role in use', function () {
    $organization = Organization::factory()->create();
    $superAdminRole = defaultRole($organization, 'Super administrateur');
    // Aucun autre rôle "gère l'organisation" n'a d'utilisateur : abaisser
    // celui-ci laisserait l'organisation sans personne pour la gérer.
    User::factory()->create(['organization_id' => $organization->id, 'role_id' => $superAdminRole->id]);

    $response = $this->actingAs(User::factory()->create(['organization_id' => $organization->id, 'role_id' => $superAdminRole->id]))
        ->put("/roles/{$superAdminRole->id}", [
            'name' => $superAdminRole->name,
            'scope' => $superAdminRole->scope,
            'can_manage_organization' => false,
        ]);

    $response->assertSessionHas('error');
    expect($superAdminRole->fresh()->can_manage_organization)->toBeTrue();
});
