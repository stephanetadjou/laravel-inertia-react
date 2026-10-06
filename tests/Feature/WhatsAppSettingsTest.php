<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function settingsAdmin(Organization $organization): User
{
    return User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Administrateur')->id,
    ]);
}

function connectedStore(Organization $organization, array $attributes = []): Store
{
    return Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_phone_number_id' => '123456789',
        'whatsapp_access_token' => 'valid-token',
        ...$attributes,
    ]);
}

it('shows the WhatsApp settings of every store without exposing secrets', function () {
    $organization = Organization::factory()->create();
    connectedStore($organization, ['whatsapp_app_secret' => 'app-secret', 'whatsapp_business_account_id' => '555']);

    $this->actingAs(settingsAdmin($organization))->get('/settings/whatsapp')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/WhatsApp')
            ->where('stores.0.phone_number_id', '123456789')
            ->where('stores.0.business_account_id', '555')
            ->where('stores.0.has_access_token', true)
            ->where('stores.0.has_app_secret', true)
            ->missing('stores.0.whatsapp_access_token')
            ->missing('stores.0.whatsapp_app_secret'))
        ->assertDontSee('valid-token')
        ->assertDontSee('app-secret');
});

it('reserves the WhatsApp settings to administrators', function () {
    $organization = Organization::factory()->create();
    $store = connectedStore($organization);
    $manager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($manager)->get('/settings/whatsapp')->assertStatus(403);
    $this->actingAs($manager)->put("/settings/whatsapp/{$store->id}", ['phone_number_id' => '999999'])->assertStatus(403);
    $this->actingAs($manager)->post("/settings/whatsapp/{$store->id}/test")->assertStatus(403);
    $this->actingAs($manager)->post("/settings/whatsapp/{$store->id}/register", ['pin' => '123456'])->assertStatus(403);

    expect($store->fresh()->whatsapp_phone_number_id)->toBe('123456789');
});

it('saves the identifiers and encrypts the token and the app secret', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'whatsapp_phone_number_id' => null, 'whatsapp_access_token' => null]);

    $this->actingAs(settingsAdmin($organization))->put("/settings/whatsapp/{$store->id}", [
        'business_account_id' => '102030405060',
        'phone_number_id' => '112233445566',
        'app_id' => '998877665544',
        'access_token' => 'EAAG-new-token',
        'app_secret' => 'shh-secret',
    ])->assertSessionHas('success');

    $store->refresh();
    expect($store->whatsapp_business_account_id)->toBe('102030405060')
        ->and($store->whatsapp_phone_number_id)->toBe('112233445566')
        ->and($store->whatsapp_app_id)->toBe('998877665544')
        ->and($store->whatsapp_access_token)->toBe('EAAG-new-token')
        ->and($store->whatsapp_app_secret)->toBe('shh-secret');

    $raw = DB::table('stores')->where('id', $store->id)->first();
    expect($raw->whatsapp_access_token)->not->toContain('EAAG-new-token')
        ->and($raw->whatsapp_app_secret)->not->toContain('shh-secret');
});

it('keeps the stored secrets when the fields are left blank', function () {
    $organization = Organization::factory()->create();
    $store = connectedStore($organization, ['whatsapp_app_secret' => 'old-secret']);

    $this->actingAs(settingsAdmin($organization))->put("/settings/whatsapp/{$store->id}", [
        'phone_number_id' => '123456789',
        'access_token' => '',
        'app_secret' => '',
    ])->assertSessionHas('success');

    $store->refresh();
    expect($store->whatsapp_access_token)->toBe('valid-token')
        ->and($store->whatsapp_app_secret)->toBe('old-secret');
});

it('rejects identifiers that are not Meta numeric ids', function () {
    $organization = Organization::factory()->create();
    $store = connectedStore($organization);

    $this->actingAs(settingsAdmin($organization))->put("/settings/whatsapp/{$store->id}", [
        'phone_number_id' => '+241 77 00',
        'business_account_id' => 'abc',
    ])->assertSessionHasErrors(['phone_number_id', 'business_account_id']);
});

it('forgets the last test results when the phone number changes', function () {
    $organization = Organization::factory()->create();
    $store = connectedStore($organization, [
        'whatsapp_verified_name' => 'Ancien',
        'whatsapp_messaging_limit_tier' => 'TIER_1K',
        'whatsapp_registered_at' => now(),
    ]);

    $this->actingAs(settingsAdmin($organization))->put("/settings/whatsapp/{$store->id}", ['phone_number_id' => '987654321']);

    $store->refresh();
    expect($store->whatsapp_verified_name)->toBeNull()
        ->and($store->whatsapp_messaging_limit_tier)->toBeNull()
        ->and($store->whatsapp_registered_at)->toBeNull();
});

it('refuses to edit a store of another organization', function () {
    $organization = Organization::factory()->create();
    $foreign = connectedStore(Organization::factory()->create());

    $this->actingAs(settingsAdmin($organization))->put("/settings/whatsapp/{$foreign->id}", ['phone_number_id' => '111111'])->assertStatus(403);
    $this->actingAs(settingsAdmin($organization))->post("/settings/whatsapp/{$foreign->id}/test")->assertStatus(403);
});

it('tests the number at Meta and records name, quality and messaging limit', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'verified_name' => 'Mango Libreville',
            'display_phone_number' => '+241 77 00 00 00',
            'quality_rating' => 'GREEN',
            'messaging_limit_tier' => 'TIER_1K',
            'id' => '123456789',
        ], 200),
    ]);

    $organization = Organization::factory()->create();
    $store = connectedStore($organization);

    $this->actingAs(settingsAdmin($organization))->post("/settings/whatsapp/{$store->id}/test")
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Mango Libreville')
            && str_contains($message, 'qualité élevée')
            && str_contains($message, '1 000 destinataires'));

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/123456789')
        && str_contains($request->url(), 'messaging_limit_tier')
        && $request->hasHeader('Authorization', 'Bearer valid-token'));

    $store->refresh();
    expect($store->whatsapp_verified_name)->toBe('Mango Libreville')
        ->and($store->whatsapp_quality_rating)->toBe('GREEN')
        ->and($store->whatsapp_messaging_limit_tier)->toBe('TIER_1K')
        ->and($store->dailyMessagingLimit())->toBe(1000)
        ->and($store->whatsapp_checked_at)->not->toBeNull();
});

it('explains Meta errors in plain French', function (int $status, array $error, string $expected) {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => $error], $status)]);

    $organization = Organization::factory()->create();
    $store = connectedStore($organization);

    $this->actingAs(settingsAdmin($organization))->post("/settings/whatsapp/{$store->id}/test")
        ->assertSessionHas('error', fn (string $message) => str_contains($message, $expected));

    expect($store->fresh()->whatsapp_checked_at)->toBeNull();
})->with([
    'expired token' => [401, ['code' => 190, 'message' => 'Error validating access token'], 'token d\'accès est invalide ou expiré'],
    'unknown number' => [400, ['code' => 100, 'error_subcode' => 33, 'message' => 'Unsupported get request'], 'Phone Number ID est introuvable'],
    'missing permission' => [403, ['code' => 200, 'message' => 'Permissions error'], 'permissions nécessaires'],
    'rate limit' => [429, ['code' => 80007, 'message' => 'Rate limit'], 'Trop de requêtes'],
]);

it('asks for the credentials before testing', function () {
    Http::fake();
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'whatsapp_phone_number_id' => null, 'whatsapp_access_token' => null]);

    $this->actingAs(settingsAdmin($organization))->post("/settings/whatsapp/{$store->id}/test")
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'renseignez d\'abord'));

    Http::assertNothingSent();
});

it('registers the phone number with the PIN', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    $organization = Organization::factory()->create();
    $store = connectedStore($organization);

    $this->actingAs(settingsAdmin($organization))->post("/settings/whatsapp/{$store->id}/register", ['pin' => '246810'])
        ->assertSessionHas('success');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/123456789/register')
        && $request['messaging_product'] === 'whatsapp'
        && $request['pin'] === '246810');

    expect($store->fresh()->whatsapp_registered_at)->not->toBeNull();
});

it('validates the PIN format before calling Meta', function (string $pin) {
    Http::fake();
    $organization = Organization::factory()->create();
    $store = connectedStore($organization);

    $this->actingAs(settingsAdmin($organization))->post("/settings/whatsapp/{$store->id}/register", ['pin' => $pin])
        ->assertSessionHasErrors('pin');

    Http::assertNothingSent();
})->with(['12345', '1234567', 'abcdef']);

it('explains a PIN mismatch reported by Meta', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 133005, 'message' => 'Two step verification PIN Mismatch']], 400)]);

    $organization = Organization::factory()->create();
    $store = connectedStore($organization);

    $this->actingAs(settingsAdmin($organization))->post("/settings/whatsapp/{$store->id}/register", ['pin' => '000000'])
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'PIN ne correspond pas'));

    expect($store->fresh()->whatsapp_registered_at)->toBeNull();
});
