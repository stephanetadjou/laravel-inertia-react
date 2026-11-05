<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Organization;
use App\Models\PlatformLog;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * « WhatsApp géré par Menentia » : le client partage son compte WhatsApp
 * avec le portefeuille Menentia, qui envoie avec son token et reçoit tout
 * sur le webhook unique /webhooks/whatsapp.
 */

beforeEach(function () {
    config(['services.meta_whatsapp.platform' => [
        'token' => 'menentia-token',
        'app_secret' => 'menentia-secret',
        'verify_token' => 'menentia-verify',
    ]]);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
    $this->salon = Organization::factory()->create(['name' => 'Salon Awa', 'whatsapp_api_enabled' => false]);
    $this->store = Store::factory()->create(['organization_id' => $this->salon->id, 'name' => 'Salon Awa Louis']);
});

function managedPayload(string $phoneNumberId, string $wamid = 'wamid.IN1'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '999888777',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '24174000000', 'phone_number_id' => $phoneNumberId],
                    'messages' => [['id' => $wamid, 'from' => '24174000001', 'timestamp' => '1790000000', 'type' => 'text', 'text' => ['body' => 'Bonjour']]],
                ],
            ]],
        ]],
    ];
}

function postPlatformWebhook($test, array $payload, ?string $secret = 'menentia-secret')
{
    $body = json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', '/webhooks/whatsapp', [], [], [], $headers, $body);
}

it('lets the Menentia team connect a shared WhatsApp account to a point of sale', function () {
    $this->store->update(['whatsapp_access_token' => 'old-token', 'whatsapp_app_secret' => 'old-secret']);

    $this->actingAs($this->admin)->put("/admin/organizations/{$this->salon->id}/stores/{$this->store->id}/whatsapp", [
        'managed' => true, 'business_account_id' => '999888777', 'phone_number_id' => '555444333',
    ])->assertSessionHas('success');

    $store = $this->store->fresh();
    expect($store->whatsapp_managed)->toBeTrue()
        ->and($store->whatsapp_phone_number_id)->toBe('555444333')
        ->and($store->whatsapp_access_token)->toBeNull()
        ->and($store->canSendWhatsApp())->toBeTrue()
        ->and($this->salon->fresh()->whatsapp_api_enabled)->toBeTrue()
        ->and(PlatformLog::where('action', 'store.whatsapp_managed')->count())->toBe(1);
});

it('refuses a phone number already used by another point of sale', function () {
    Store::factory()->create(['whatsapp_phone_number_id' => '555444333']);

    $this->actingAs($this->admin)->put("/admin/organizations/{$this->salon->id}/stores/{$this->store->id}/whatsapp", [
        'managed' => true, 'business_account_id' => '999888777', 'phone_number_id' => '555444333',
    ])->assertSessionHasErrors('phone_number_id');

    expect($this->store->fresh()->whatsapp_managed)->toBeFalse();
});

it('keeps the managed setting out of reach of company users', function () {
    $owner = User::factory()->create(['organization_id' => $this->salon->id, 'role_id' => defaultRole($this->salon, 'Administrateur')->id]);
    $this->store->update(['whatsapp_managed' => true, 'whatsapp_phone_number_id' => '555444333']);
    $this->salon->update(['whatsapp_api_enabled' => true]);

    $this->actingAs($owner)->put("/admin/organizations/{$this->salon->id}/stores/{$this->store->id}/whatsapp", [
        'managed' => true, 'business_account_id' => '1', 'phone_number_id' => '777777777',
    ])->assertForbidden();

    // Depuis ses Paramètres WhatsApp, l'entreprise ne peut pas pointer le token Menentia vers un autre numéro.
    $this->actingAs($owner)->put("/settings/whatsapp/{$this->store->id}", ['phone_number_id' => '777777777'])
        ->assertSessionHas('error');

    expect($this->store->fresh()->whatsapp_phone_number_id)->toBe('555444333')
        ->and((new Store)->isFillable('whatsapp_managed'))->toBeTrue();
});

it('sends with the Menentia token for a managed number', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    $this->store->update(['whatsapp_managed' => true, 'whatsapp_phone_number_id' => '555444333']);

    $this->store->fresh()->whatsappClient()->sendTemplate('24174000001', 'hello_world', 'en_US');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/555444333/messages')
        && $request->hasHeader('Authorization', 'Bearer menentia-token'));
});

it('answers the verification of the single webhook with the Menentia verify token only', function () {
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=menentia-verify&hub.challenge=42')
        ->assertOk()->assertSee('42');

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')->assertForbidden();

    config(['services.meta_whatsapp.platform.verify_token' => null]);
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=42')->assertForbidden();
});

it('routes each event of the single webhook to the managed point of sale owning the number', function () {
    Queue::fake();
    $this->store->update(['whatsapp_managed' => true, 'whatsapp_phone_number_id' => '555444333']);
    // Même numéro saisi ailleurs sans être géré : jamais destinataire du webhook Menentia.
    Store::factory()->create(['whatsapp_phone_number_id' => '111000111']);

    postPlatformWebhook($this, managedPayload('555444333'))->assertOk();
    postPlatformWebhook($this, managedPayload('111000111'))->assertOk();

    Queue::assertPushed(ProcessWhatsAppWebhookJob::class, 1);
    Queue::assertPushed(ProcessWhatsAppWebhookJob::class, fn ($job) => $job->storeId === $this->store->id);
});

it('rejects single webhook calls that are not signed with the Menentia App Secret', function (?string $secret) {
    Queue::fake();
    $this->store->update(['whatsapp_managed' => true, 'whatsapp_phone_number_id' => '555444333']);

    postPlatformWebhook($this, managedPayload('555444333'), $secret)->assertForbidden();

    Queue::assertNothingPushed();
})->with(['no signature' => [null], 'store secret' => ['other-secret']]);

it('records the inbound message of a managed number end to end', function () {
    $this->store->update(['whatsapp_managed' => true, 'whatsapp_phone_number_id' => '555444333']);

    postPlatformWebhook($this, managedPayload('555444333', 'wamid.E2E'))->assertOk();

    expect(WhatsAppInboundMessage::where('wa_message_id', 'wamid.E2E')->value('store_id'))->toBe($this->store->id);
});

it('accepts the per-store webhook of a managed number with the Menentia App Secret', function () {
    Queue::fake();
    $this->store->update(['whatsapp_managed' => true, 'whatsapp_phone_number_id' => '555444333']);
    $this->store->ensureWebhookCredentials();
    $body = json_encode(managedPayload('555444333'));

    $this->call('POST', "/webhooks/whatsapp/{$this->store->fresh()->webhook_key}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'menentia-secret'),
    ], $body)->assertOk();

    Queue::assertPushed(ProcessWhatsAppWebhookJob::class);
});
