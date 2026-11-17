<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\SectorPresets;
use Inertia\Testing\AssertableInertia as Assert;

it('pins the four flagship apps of each sector, flagship first', function () {
    expect((new Organization(['sector' => 'location']))->pinnedApps())->toBe(['rental', 'fleet', 'customers', 'discussions'])
        ->and((new Organization(['sector' => 'beaute']))->pinnedApps())->toBe(['agenda', 'customers', 'discussions', 'sales'])
        ->and((new Organization(['sector' => null]))->pinnedApps())->toHaveCount(4);

    foreach (Organization::SECTORS as $sector => $definition) {
        expect(Organization::SECTOR_PINNED_APPS)->toHaveKey($sector);
    }
});

it('shares the pinned apps with the interface', function () {
    $organization = Organization::factory()->create(['sector' => 'location']);
    SectorPresets::apply($organization, true);
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $this->actingAs($user)->get('/location/dashboard')->assertInertia(fn (Assert $page) => $page->where('auth.organization.pinned_apps.0', 'rental'));
});
