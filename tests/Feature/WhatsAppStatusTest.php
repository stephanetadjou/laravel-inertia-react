<?php

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Store;

function statusStore(): Store
{
    return Store::factory()->create(['whatsapp_phone_number_id' => '111222333']);
}

function statusPayload(array $statuses = [], array $messages = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA_ID',
            'changes' => [[
                'field' => 'messages',
                'value' => array_filter([
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => '111222333'],
                    'statuses' => $statuses,
                    'messages' => $messages,
                ]),
            ]],
        ]],
    ];
}

function metaStatus(string $wamid, string $status, int $timestamp = 1790000000, array $extra = []): array
{
    return ['id' => $wamid, 'status' => $status, 'timestamp' => (string) $timestamp, 'recipient_id' => '24177451288', ...$extra];
}

function sentCampaignMessage(Store $store, string $wamid): CampaignMessage
{
    $campaign = Campaign::factory()->create(['status' => 'completed', 'sent_count' => 1]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288']);

    return CampaignMessage::factory()->create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'status' => 'sent',
        'meta_message_id' => $wamid,
    ]);
}

function processWebhook(Store $store, array $payload): void
{
    (new ProcessWhatsAppWebhookJob($store->id, $payload))->handle();
}

it('records delivered then read on the campaign message and its campaign', function () {
    $store = statusStore();
    $message = sentCampaignMessage($store, 'wamid.1');

    processWebhook($store, statusPayload([metaStatus('wamid.1', 'delivered', 1790000000)]));
    processWebhook($store, statusPayload([metaStatus('wamid.1', 'read', 1790000600)]));

    $message->refresh();
    expect($message->deliveryState())->toBe('read')
        ->and($message->delivered_at->timestamp)->toBe(1790000000)
        ->and($message->read_at->timestamp)->toBe(1790000600);

    $campaign = $message->campaign->fresh();
    expect($campaign->sent_count)->toBe(1)
        ->and($campaign->delivered_count)->toBe(1)
        ->and($campaign->read_count)->toBe(1)
        ->and($campaign->failed_count)->toBe(0);
});

it('ignores replayed and out-of-order statuses', function () {
    $store = statusStore();
    $message = sentCampaignMessage($store, 'wamid.2');

    processWebhook($store, statusPayload([metaStatus('wamid.2', 'read', 1790000600)]));
    processWebhook($store, statusPayload([metaStatus('wamid.2', 'delivered', 1790000000)]));
    processWebhook($store, statusPayload([metaStatus('wamid.2', 'read', 1790009999)]));

    $message->refresh();
    expect($message->read_at->timestamp)->toBe(1790000600)
        ->and($message->delivered_at->timestamp)->toBe(1790000600)
        ->and($message->campaign->fresh()->read_count)->toBe(1);
});

it('turns a message failed later by Meta into a failure with its reason', function () {
    $store = statusStore();
    $message = sentCampaignMessage($store, 'wamid.3');

    processWebhook($store, statusPayload([metaStatus('wamid.3', 'failed', 1790000000, [
        'errors' => [['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'Receiver is incapable of receiving this message']]],
    ])]));

    $message->refresh();
    expect($message->status)->toBe('failed')
        ->and($message->error_message)->toContain('131026')
        ->and($message->error_message)->toContain('incapable');

    $campaign = $message->campaign->fresh();
    expect($campaign->sent_count)->toBe(0)->and($campaign->failed_count)->toBe(1);
});

it('never touches messages of another organization', function () {
    $store = statusStore();
    $foreign = sentCampaignMessage(Store::factory()->create(), 'wamid.4');

    processWebhook($store, statusPayload([metaStatus('wamid.4', 'read')]));

    expect($foreign->fresh()->read_at)->toBeNull();
});

it('removes the opt-in when the customer answers Stop', function (string $answer) {
    $store = statusStore();
    $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288', 'consent_status' => 'opt_in']);

    processWebhook($store, statusPayload(messages: [
        ['id' => 'wamid.IN', 'from' => '24177451288', 'timestamp' => '1790000000', 'type' => 'text', 'text' => ['body' => $answer]],
    ]));

    expect($contact->fresh()->consent_status)->toBe('opt_out');
})->with(['STOP', 'stop', ' Stop. ', 'Arrêt', 'Stop promotions']);

it('removes the opt-in from the Stop promotions quick reply button', function () {
    $store = statusStore();
    $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288', 'consent_status' => 'opt_in']);

    processWebhook($store, statusPayload(messages: [
        ['id' => 'wamid.BTN', 'from' => '24177451288', 'timestamp' => '1790000000', 'type' => 'button', 'button' => ['text' => 'Stop promotions', 'payload' => 'Stop promotions']],
    ]));

    expect($contact->fresh()->consent_status)->toBe('opt_out');
});

it('keeps the opt-in for messages that only mention stop', function () {
    $store = statusStore();
    $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288', 'consent_status' => 'opt_in']);

    processWebhook($store, statusPayload(messages: [
        ['id' => 'wamid.TXT', 'from' => '24177451288', 'timestamp' => '1790000000', 'type' => 'text', 'text' => ['body' => 'Le bus ne s\'arrête pas au stop, vous êtes où ?']],
    ]));

    expect($contact->fresh()->consent_status)->toBe('opt_in');
});

it('only unsubscribes the contact of the store that received the Stop', function () {
    $store = statusStore();
    $otherStore = Store::factory()->create(['organization_id' => $store->organization_id]);
    $here = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288', 'consent_status' => 'opt_in']);
    $there = Contact::factory()->create(['store_id' => $otherStore->id, 'phone_e164' => '24177451288', 'consent_status' => 'opt_in']);

    processWebhook($store, statusPayload(messages: [
        ['id' => 'wamid.S1', 'from' => '24177451288', 'timestamp' => '1790000000', 'type' => 'text', 'text' => ['body' => 'STOP']],
    ]));

    expect($here->fresh()->consent_status)->toBe('opt_out')
        ->and($there->fresh()->consent_status)->toBe('opt_in');
});
