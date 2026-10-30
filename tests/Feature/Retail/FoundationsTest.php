<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

it('saves currency, timezone and analysis level of the organization and audits it', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    expect($organization->fresh()->currency)->toBe('XAF')
        ->and($organization->fresh()->timezone)->toBe('Africa/Libreville')
        ->and($organization->fresh()->analysisLevel())->toBe('family');

    $this->actingAs($user)->put('/organisation', [
        'name' => $organization->name,
        'currency' => 'XOF',
        'timezone' => 'Africa/Dakar',
        'product_analysis_level' => 'category',
    ])->assertSessionHas('success');

    $organization->refresh();
    expect($organization->currency)->toBe('XOF')
        ->and($organization->timezone)->toBe('Africa/Dakar')
        ->and($organization->analysisLevel())->toBe('category')
        ->and($organization->currencySymbol())->toBe('FCFA');

    $log = AuditLog::where('organization_id', $organization->id)->where('action', 'settings.updated')->sole();
    expect($log->user_id)->toBe($user->id)->and($log->payload)->toHaveKey('currency');
});

it('rejects an unknown currency or timezone', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->put('/organisation', ['name' => 'X', 'currency' => 'BTC', 'timezone' => 'Mars/Olympus'])
        ->assertSessionHasErrors(['currency', 'timezone']);
});

it('creates a store with a code and a city, closes it, and audits each change', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->post('/stores', ['brand_id' => $brand->id, 'name' => 'Centre-ville', 'code' => 'LBV01', 'city' => 'Libreville'])
        ->assertSessionHas('success');

    $store = Store::where('code', 'LBV01')->sole();
    expect($store->city)->toBe('Libreville')->and($store->is_active)->toBeTrue();

    // Code unique dans l'organisation.
    $this->actingAs($user)->post('/stores', ['brand_id' => $brand->id, 'name' => 'Autre', 'code' => 'LBV01'])
        ->assertSessionHasErrors('code');

    $this->actingAs($user)->put("/stores/{$store->id}", ['is_active' => false])->assertSessionHas('success');
    expect($store->fresh()->is_active)->toBeFalse();

    expect(AuditLog::where('organization_id', $organization->id)->pluck('action')->all())
        ->toContain('store.created', 'store.updated');
});

it('audits brand and user creation, update and deletion', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id]);
    $role = defaultRole($organization, 'Administrateur');

    $this->actingAs($admin)->post('/brands', ['name' => 'Enseigne A'])->assertSessionHas('success');
    $brand = Brand::where('name', 'Enseigne A')->sole();
    $this->actingAs($admin)->put("/brands/{$brand->id}", ['name' => 'Enseigne B']);
    $this->actingAs($admin)->delete("/brands/{$brand->id}");

    $this->actingAs($admin)->post('/users', ['name' => 'Awa', 'email' => 'awa@example.com', 'password' => 'secret-123', 'role_id' => $role->id])
        ->assertSessionHas('success');
    $user = User::where('email', 'awa@example.com')->sole();
    $this->actingAs($admin)->put("/users/{$user->id}", ['name' => 'Awa N.', 'email' => 'awa@example.com', 'role_id' => $role->id]);
    $this->actingAs($admin)->delete("/users/{$user->id}");

    expect(AuditLog::where('organization_id', $organization->id)->pluck('action')->all())
        ->toContain('brand.created', 'brand.updated', 'brand.deleted', 'user.created', 'user.updated', 'user.deleted');

    $this->actingAs($admin)->get('/settings/audit')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Audit')->where('logs.total', 6));
});

it('keeps the audit log out of reach of non managers', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $manager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($manager)->get('/settings/audit')->assertForbidden();
});

it('seeds retail role presets for a commerce organization and on demand', function () {
    $commerce = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($commerce);

    expect(Role::where('organization_id', $commerce->id)->pluck('name')->all())
        ->toContain('Admin groupe', 'Direction', 'Marketing', 'Opérateur d’import');

    $import = Role::where('organization_id', $commerce->id)->where('name', 'Opérateur d’import')->sole();
    expect($import->can_import)->toBeTrue()->and($import->can_export)->toBeFalse();

    $salon = Organization::factory()->create(['sector' => 'beaute']);
    $admin = User::factory()->create(['organization_id' => $salon->id]);
    Role::seedDefaultsFor($salon);
    expect(Role::where('organization_id', $salon->id)->where('name', 'Marketing')->exists())->toBeFalse();

    $this->actingAs($admin)->post('/roles/presets')->assertSessionHas('success');
    expect(Role::where('organization_id', $salon->id)->where('name', 'Marketing')->exists())->toBeTrue();
});

it('derives import and export rights from the role', function () {
    $organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($organization);
    $marketing = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => Role::where('organization_id', $organization->id)->where('name', 'Marketing')->value('id'),
    ]);

    expect($marketing->canImport())->toBeFalse()->and($marketing->canExport())->toBeTrue();
});
