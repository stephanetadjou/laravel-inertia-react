<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Support\Facades\Queue;

function webhookStore(array $attributes = []): Store
{
    $store = Store::factory()->create([
        'whatsapp_phone_number_id' => '111222333',
        'whatsapp_app_secret' => 'app-secret',
        ...$attributes,
    ]);
    $store->ensureWebhookCredentials();

    return $store->fresh();
}

function inboundPayload(string $phoneNumberId, array $messages): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA_ID',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '24177000000', 'phone_number_id' => $phoneNumberId],
                    'messages' => $messages,
                ],
            ]],
        ]],
    ];
}

function textMessage(string $id, string $from, string $body): array
{
    return ['id' => $id, 'from' => $from, 'timestamp' => '1790000000', 'type' => 'text', 'text' => ['body' => $body]];
}

/**
 * Appel signé comme Meta : HMAC SHA-256 du corps brut avec l'App Secret.
 */
function postSignedWebhook($test, Store $store, array $payload, ?string $secret = 'app-secret')
{
    $body = json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', "/webhooks/whatsapp/{$store->webhook_key}", [], [], [], $headers, $body);
}

it('answers Meta verification challenge with the right verify token', function () {
    $store = webhookStore();

    $this->get("/webhooks/whatsapp/{$store->webhook_key}?hub.mode=subscribe&hub.verify_token={$store->webhook_verify_token}&hub.challenge=1158201444")
        ->assertOk()
        ->assertSeeText('1158201444');
});

it('refuses the verification with a wrong token or an unknown store', function () {
    $store = webhookStore();

    $this->get("/webhooks/whatsapp/{$store->webhook_key}?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1")->assertStatus(403);
    $this->get('/webhooks/whatsapp/unknown-key?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1')->assertStatus(403);
});

it('accepts a correctly signed event and queues its processing', function () {
    Queue::fake();
    $store = webhookStore();

    postSignedWebhook($this, $store, inboundPayload('111222333', [textMessage('wamid.A', '24177451288', 'Bonjour')]))
        ->assertOk();

    Queue::assertPushed(ProcessWhatsAppWebhookJob::class, fn ($job) => $job->storeId === $store->id);
    expect(WhatsAppInboundMessage::count())->toBe(0);
});

it('rejects events with a missing or forged signature', function (?string $secret) {
    Queue::fake();
    $store = webhookStore();

    postSignedWebhook($this, $store, inboundPayload('111222333', [textMessage('wamid.A', '24177451288', 'Bonjour')]), $secret)
        ->assertStatus(403);

    Queue::assertNothingPushed();
})->with(['no signature' => [null], 'other secret' => ['not-the-app-secret']]);

it('rejects every event while the store has no app secret', function () {
    Queue::fake();
    $store = webhookStore(['whatsapp_app_secret' => null]);

    postSignedWebhook($this, $store, inboundPayload('111222333', []), '')->assertStatus(403);

    Queue::assertNothingPushed();
});

it('does not need a CSRF token', function () {
    Queue::fake();
    $store = webhookStore();

    // Pas de withoutMiddleware : le webhook doit passer la pile web complète.
    postSignedWebhook($this, $store, inboundPayload('111222333', []))->assertOk();
});

it('records inbound messages once even when Meta delivers them twice', function () {
    $store = webhookStore();
    $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288']);
    $payload = inboundPayload('111222333', [textMessage('wamid.DUP', '24177451288', 'Bonjour, vous avez du 42 ?')]);

    postSignedWebhook($this, $store, $payload)->assertOk();
    postSignedWebhook($this, $store, $payload)->assertOk();

    $message = WhatsAppInboundMessage::sole();
    expect($message->store_id)->toBe($store->id)
        ->and($message->contact_id)->toBe($contact->id)
        ->and($message->body)->toBe('Bonjour, vous avez du 42 ?')
        ->and($message->type)->toBe('text')
        ->and($message->received_at->timestamp)->toBe(1790000000);
});

it('routes an event to the store of the same organization owning the phone number', function () {
    $receiving = webhookStore();
    $sibling = Store::factory()->create([
        'organization_id' => $receiving->organization_id,
        'whatsapp_phone_number_id' => '444555666',
    ]);
    $foreign = Store::factory()->create(['whatsapp_phone_number_id' => '777888999']);

    postSignedWebhook($this, $receiving, inboundPayload('444555666', [textMessage('wamid.S', '24166000001', 'Salut')]));
    postSignedWebhook($this, $receiving, inboundPayload('777888999', [textMessage('wamid.F', '24166000002', 'Intrus')]));

    expect(WhatsAppInboundMessage::pluck('store_id')->all())->toBe([$sibling->id]);
    expect($foreign->id)->not->toBe($sibling->id);
});

it('shows the webhook URL and verify token in the WhatsApp settings', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);

    $this->actingAs($admin)->get('/settings/whatsapp')
        ->assertInertia(fn ($page) => $page
            ->where('stores.0.webhook_url', fn (string $url) => str_contains($url, '/webhooks/whatsapp/'.$store->fresh()->webhook_key))
            ->where('stores.0.webhook_verify_token', fn (?string $token) => strlen((string) $token) === 32));
});
