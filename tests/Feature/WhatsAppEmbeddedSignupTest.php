<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Connexion WhatsApp en 5 minutes (inscription intégrée Meta, coexistence) :
 * échange du code, abonnement de l'app ClientlyX au compte, numéro enregistré
 * seulement s'il n'est pas déjà dans l'application WhatsApp Business.
 */

function esSetup(): array
{
    config([
        'services.meta_whatsapp.embedded.app_id' => '111222333',
        'services.meta_whatsapp.embedded.config_id' => '999888',
        'services.meta_whatsapp.platform.app_secret' => 'secret-test',
        'services.meta_whatsapp.platform.token' => null,
    ]);
    $org = Organization::factory()->create(['whatsapp_api_enabled' => true]);
    $store = Store::factory()->create(['organization_id' => $org->id]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role_id' => defaultRole($org, 'Super administrateur')->id]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAB-business-token']),
        'graph.facebook.com/*/7700123/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/*/55500011/register' => Http::response(['success' => true]),
        'graph.facebook.com/*/55500011*' => Http::response(['verified_name' => 'Studio Lumière', 'display_phone_number' => '+241 77 00 00 00', 'quality_rating' => 'GREEN']),
    ]);

    return [$org, $store, $admin];
}

it('affiche le bouton de connexion en 5 minutes quand il est configuré', function () {
    [, , $admin] = esSetup();

    $this->actingAs($admin)->get('/settings/whatsapp')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('embedded.config_id', '999888')->where('embedded.coexistence', true));
});

it('connecte un numéro de l’application WhatsApp Business sans le réenregistrer', function () {
    [, $store, $admin] = esSetup();

    $this->actingAs($admin)->post("/settings/whatsapp/{$store->id}/embedded", [
        'code' => 'CODE-META', 'phone_number_id' => '55500011', 'waba_id' => '7700123', 'coexistence' => true,
    ])->assertSessionHas('success');

    $store->refresh();
    expect($store->whatsapp_phone_number_id)->toBe('55500011')
        ->and($store->whatsapp_managed)->toBeTrue()
        ->and($store->whatsapp_verified_name)->toBe('Studio Lumière')
        ->and($store->canSendWhatsApp())->toBeTrue();

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth/access_token') && str_contains($r->url(), 'code=CODE-META'));
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '7700123/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer EAAB-business-token'));
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/register'));
});

it('enregistre un nouveau numéro sur Cloud API', function () {
    [, $store, $admin] = esSetup();

    $this->actingAs($admin)->post("/settings/whatsapp/{$store->id}/embedded", [
        'code' => 'CODE-META', 'phone_number_id' => '55500011', 'waba_id' => '7700123', 'coexistence' => false,
    ])->assertSessionHas('success');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '55500011/register'));
});

it('refuse l’établissement d’une autre entreprise', function () {
    [, , $admin] = esSetup();
    $other = Store::factory()->create();

    $this->actingAs($admin)->post("/settings/whatsapp/{$other->id}/embedded", [
        'code' => 'X', 'phone_number_id' => '55500011', 'waba_id' => '12345',
    ])->assertForbidden();
});
