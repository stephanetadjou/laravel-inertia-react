<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

function registrationPayload(array $overrides = []): array
{
    return [
        'organization_name' => 'Boutique Akanda',
        'store_name' => 'Akanda Centre',
        'name' => 'Awa Mba',
        'email' => 'awa@example.com',
        'password' => 'Secret-123456',
        'password_confirmation' => 'Secret-123456',
        ...$overrides,
    ];
}

it('shows the registration page to guests', function () {
    $this->get('/register')->assertOk();
});

it('creates the organization, its first store and an admin account', function () {
    $this->post('/register', registrationPayload())
        ->assertRedirect(route('dashboard.index'));

    $organization = Organization::where('name', 'Boutique Akanda')->firstOrFail();
    $store = Store::where('organization_id', $organization->id)->sole();
    $user = User::where('email', 'awa@example.com')->firstOrFail();

    expect($store->name)->toBe('Akanda Centre')
        ->and($store->brand->name)->toBe('Akanda Centre')
        ->and($store->brand->organization_id)->toBe($organization->id)
        ->and($store->whatsapp_access_token)->toBeNull()
        ->and($user->organization_id)->toBe($organization->id)
        ->and($user->role->name)->toBe('Super administrateur')
        ->and($user->canManageOrganization())->toBeTrue()
        ->and($organization->roles()->count())->toBe(4);

    $this->assertAuthenticatedAs($user);
});

it('keeps each registered company isolated', function () {
    $this->post('/register', registrationPayload());
    auth()->logout();
    $this->post('/register', registrationPayload([
        'organization_name' => 'Autre entreprise',
        'email' => 'autre@example.com',
    ]));

    $second = User::where('email', 'autre@example.com')->firstOrFail();

    expect(Organization::count())->toBe(2)
        ->and($second->organization->name)->toBe('Autre entreprise')
        ->and($second->organization->stores()->count())->toBe(1);
});

it('validates the registration form', function (array $overrides, string $field) {
    $this->post('/register', registrationPayload($overrides))->assertSessionHasErrors($field);

    expect(Organization::count())->toBe(0)
        ->and(User::count())->toBe(0);
})->with([
    'missing company' => [['organization_name' => ''], 'organization_name'],
    'missing store' => [['store_name' => ''], 'store_name'],
    'invalid email' => [['email' => 'pas-un-email'], 'email'],
    'password mismatch' => [['password_confirmation' => 'autre'], 'password'],
]);

it('refuses an email already used by another account', function () {
    User::factory()->create(['email' => 'awa@example.com']);

    $this->post('/register', registrationPayload())->assertSessionHasErrors('email');

    expect(Organization::where('name', 'Boutique Akanda')->exists())->toBeFalse();
});

it('redirects authenticated users away from registration', function () {
    $this->actingAs(User::factory()->create())->get('/register')->assertRedirect();
});
