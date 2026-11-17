<?php

use App\Models\Organization;
use App\Models\User;

it('lists activity families without hospitality or shops', function () {
    $this->get('/register')->assertInertia(fn ($page) => $page
        ->has('families', count(Organization::FAMILIES))
        ->where('families', fn ($families) => collect($families)->pluck('label')->doesntContain('Hôtellerie') && collect($families)->contains('key', 'immobilier')));
});

it('registers a business from its family and trade', function () {
    $this->post('/register', [
        'organization_name' => 'Agence Horizon Immo', 'sector' => 'immobilier', 'activity' => 'Agence immobilière',
        'store_name' => 'Agence centre', 'name' => 'Awa', 'email' => 'awa@horizon.test',
        'password' => 'Secret-Horizon-2026', 'password_confirmation' => 'Secret-Horizon-2026',
    ])->assertRedirect();

    $organization = User::where('email', 'awa@horizon.test')->first()->organization;
    expect($organization->sector)->toBe('immobilier')
        ->and($organization->activity)->toBe('Agence immobilière')
        ->and($organization->bookingLabel())->toBe('Visite')
        ->and($organization->usage_mode)->toBe('complete')
        ->and($organization->hasModule('prospects'))->toBeTrue();
});

it('opens analysis only mode for businesses that only analyse their data', function () {
    $this->post('/register', [
        'organization_name' => 'Pharma Plus', 'sector' => 'commerce', 'activity' => 'Analyser mes données clients',
        'store_name' => 'Siège', 'name' => 'Paul', 'email' => 'paul@pharma.test',
        'password' => 'Secret-Pharma-2026', 'password_confirmation' => 'Secret-Pharma-2026',
    ])->assertRedirect();

    expect(User::where('email', 'paul@pharma.test')->first()->organization->usage_mode)->toBe('analyse');
});
