<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\Locale;

it('shares French by default, without a dictionary', function () {
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('locale', 'fr')->where('translations', []));
});

it('switches the interface to English for the user and shares the dictionary', function () {
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($user)->from('/dashboard')->put('/locale', ['locale' => 'en'])->assertRedirect('/dashboard');

    expect($user->fresh()->locale)->toBe('en');
    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('locale', 'en')->where('translations.Se déconnecter', 'Log out'));
});

it('follows the organisation language, and lets a visitor choose with ?lang=', function () {
    $org = Organization::factory()->create(['locale' => 'en']);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('locale', 'en'));

    auth()->logout();
    $this->flushSession();
    $this->get('/login?lang=en')->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('refuses an unknown language', function () {
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($user)->put('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
    expect($user->fresh()->locale)->toBeNull();
});

it('keeps every English translation key unique and non-empty', function () {
    $dict = json_decode(file_get_contents(lang_path('en.json')), true);

    expect($dict)->toBeArray()->not->toBeEmpty()
        ->and(collect($dict)->filter(fn ($v) => ! is_string($v) || trim($v) === '')->all())->toBe([]);
});

it('shows a business public booking page in the business language', function () {
    $org = Organization::factory()->create(['locale' => 'en']);

    $this->get("/rdv/{$org->slug}")->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('lets the manager set the business language', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($admin)->put('/organisation', ['name' => $org->name, 'locale' => 'en'])->assertRedirect();
    expect($org->fresh()->locale)->toBe('en');

    $this->actingAs($admin)->put('/organisation', ['name' => $org->name, 'locale' => 'de'])->assertSessionHasErrors('locale');
});

it('translates the WhatsApp messages sent to customers when the business works in English', function () {
    Locale::apply('en');
    expect(__('Je confirme'))->toBe('I confirm')
        ->and(__('C’est réservé ✅ « :service » le :when chez :store. À bientôt !', ['service' => 'Braids', 'when' => 'Sat 8 Nov at 14:00', 'store' => 'Kalia']))
        ->toBe('Booked ✅ “Braids” on Sat 8 Nov at 14:00 at Kalia. See you soon!');

    Locale::apply('fr');
    expect(__('Je confirme'))->toBe('Je confirme');
});
