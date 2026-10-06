<?php

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Store;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.meta_whatsapp.driver' => 'log',
        'services.meta_whatsapp.token' => null,
        'services.meta_whatsapp.phone_number_id' => null,
    ]);
    Http::preventStrayRequests();
});

it('simulates sends without credentials nor any HTTP call', function () {
    $client = new WhatsAppClient;

    expect($client->sendTemplate('24177451288', 'bienvenue')['messages'][0]['id'])->toStartWith('wamid.SIMULATED.');
    expect($client->sendText('24177451288', 'Bonjour')['messages'][0]['id'])->toStartWith('wamid.SIMULATED.');
    expect($client->verifyConnection()['verified_name'])->toContain('Simulation');
});

it('simulates a failure for numbers ending with 000', function () {
    (new WhatsAppClient)->sendTemplate('24177451000', 'bienvenue');
})->throws(WhatsAppApiException::class, 'échec simulé');

it('runs a whole campaign in simulation, stores without credentials included', function () {
    $store = Store::factory()->create(['whatsapp_access_token' => null, 'whatsapp_phone_number_id' => null]);
    expect($store->canSendWhatsApp())->toBeTrue();

    $campaign = Campaign::factory()->create(['status' => 'queued']);
    $campaign->stores()->sync([$store->id]);
    $ok = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451288']);
    $ko = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177451000']);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $ok->id]);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $ko->id]);

    (new SendCampaignJob($campaign))->handle();

    $campaign->refresh();
    expect($campaign->status)->toBe('completed');
    expect($campaign->sent_count)->toBe(1);
    expect($campaign->failed_count)->toBe(1);
});

it('never simulates in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(WhatsAppClient::simulated())->toBeFalse();
    expect(fn () => new WhatsAppClient)->toThrow(RuntimeException::class, 'Identifiants WhatsApp manquants');
});
