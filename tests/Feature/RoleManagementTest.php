<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;

it('creates a custom role', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $response = $this->actingAs($user)->post('/roles', [
        'name' => 'Responsable Marketing',
        'scope' => Role::SCOPE_BRAND,
        'can_manage_organization' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Role::where('organization_id', $organization->id)->where('name', 'Responsable Marketing')->where('scope', 'brand')->exists())->toBeTrue();
});

it('refuses a duplicate role name within the same organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $this->actingAs($user)->post('/roles', [
        'name' => 'Administrateur',
        'scope' => Role::SCOPE_ORGANIZATION,
        'can_manage_organization' => true,
    ])->assertSessionHasErrors('name');
});

it('refuses a role management route for a user who cannot manage the organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Responsable de marque')->id]);

    $this->actingAs($user)->get('/roles')->assertStatus(403);
    $this->actingAs($user)->post('/roles', ['name' => 'X', 'scope' => Role::SCOPE_ORGANIZATION])->assertStatus(403);
});

it('renames a role and can change its scope', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $role = Role::factory()->create(['organization_id' => $organization->id, 'name' => 'Ancien nom', 'scope' => Role::SCOPE_STORE]);

    $response = $this->actingAs($user)->put("/roles/{$role->id}", [
        'name' => 'Nouveau nom',
        'scope' => Role::SCOPE_BRAND,
        'can_manage_organization' => false,
    ]);

    $response->assertRedirect();
    expect($role->fresh()->name)->toBe('Nouveau nom');
    expect($role->fresh()->scope)->toBe(Role::SCOPE_BRAND);
});

it('refuses to delete a role assigned to users', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $role = defaultRole($organization, 'Responsable de boutique');
    User::factory()->create(['organization_id' => $organization->id, 'role_id' => $role->id]);

    $response = $this->actingAs($user)->delete("/roles/{$role->id}");

    $response->assertSessionHas('error');
    expect(Role::find($role->id))->not->toBeNull();
});

it('deletes a role with no users assigned', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $role = Role::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->delete("/roles/{$role->id}");

    $response->assertSessionHas('success');
    expect(Role::find($role->id))->toBeNull();
});

it('refuses to remove the last organization-manager capability from a role in use', function () {
    $organization = Organization::factory()->create();
    $superAdminRole = defaultRole($organization, 'Super administrateur');
    User::factory()->create(['organization_id' => $organization->id, 'role_id' => $superAdminRole->id]);
    $actor = User::factory()->create(['organization_id' => $organization->id, 'role_id' => $superAdminRole->id]);

    $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}", [
        'name' => $superAdminRole->name,
        'scope' => $superAdminRole->scope,
        'can_manage_organization' => false,
    ]);

    $response->assertSessionHas('error');
    expect($superAdminRole->fresh()->can_manage_organization)->toBeTrue();
});

it('allows removing organization-manager capability from a role when another manager role has users', function () {
    $organization = Organization::factory()->create();
    $superAdminRole = defaultRole($organization, 'Super administrateur');
    $adminRole = defaultRole($organization, 'Administrateur');
    User::factory()->create(['organization_id' => $organization->id, 'role_id' => $superAdminRole->id]);
    $actor = User::factory()->create(['organization_id' => $organization->id, 'role_id' => $adminRole->id]);

    $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}", [
        'name' => $superAdminRole->name,
        'scope' => $superAdminRole->scope,
        'can_manage_organization' => false,
    ]);

    $response->assertSessionHas('success');
    expect($superAdminRole->fresh()->can_manage_organization)->toBeFalse();
});

it('refuses to manage a role belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $foreignRole = Role::factory()->create(['organization_id' => $otherOrganization->id]);

    $this->actingAs($user)->put("/roles/{$foreignRole->id}", [
        'name' => 'Hack',
        'scope' => Role::SCOPE_ORGANIZATION,
        'can_manage_organization' => false,
    ])->assertStatus(403);
    $this->actingAs($user)->delete("/roles/{$foreignRole->id}")->assertStatus(403);
});

it('lists roles with their assigned user count, scoped to the organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $role = Role::factory()->create(['organization_id' => $organization->id, 'name' => 'Testeur']);
    User::factory()->create(['organization_id' => $organization->id, 'role_id' => $role->id]);
    User::factory()->create(['organization_id' => $organization->id, 'role_id' => $role->id]);

    $response = $this->actingAs($user)->get('/roles');

    $response->assertInertia(fn ($page) => $page
        ->has('roles', 5)
    );

    $names = collect($response->viewData('page')['props']['roles'])->pluck('users_count', 'name');
    expect($names['Testeur'])->toBe(2);
});
