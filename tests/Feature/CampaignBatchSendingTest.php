<?php

use App\Jobs\SendCampaignBatchJob;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\WhatsAppApiException;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function batchCampaign(Store $store, int $recipients): Campaign
{
    $campaign = Campaign::factory()->create(['status' => 'queued', 'message_type' => 'template', 'template_name' => 'promo', 'template_language' => 'fr']);
    $campaign->stores()->sync([$store->id]);

    for ($i = 1; $i <= $recipients; $i++) {
        $contact = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '2417700'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
        CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'pending']);
    }

    return $campaign;
}

it('splits the campaign into one queued job per batch', function () {
    Bus::fake();
    config(['services.meta_whatsapp.batch_size' => 2]);
    $campaign = batchCampaign(Store::factory()->create(), 5);

    (new SendCampaignJob($campaign))->handle();

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 3
        && $batch->jobs->every(fn ($job) => $job instanceof SendCampaignBatchJob)
        && $batch->jobs->map(fn ($job) => count($job->messageIds))->all() === [2, 2, 1]);
    expect($campaign->fresh()->status)->toBe('sending');
});

it('declares retries, timeout and backoff on batch jobs', function () {
    $job = new SendCampaignBatchJob(1, [1, 2]);

    expect($job->tries)->toBe(3)->and($job->timeout)->toBeGreaterThan(0)->and($job->backoff)->not->toBeEmpty();
});

it('sends every batch and completes the campaign', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['messages' => [['id' => 'wamid.1']]])
        ->push(['messages' => [['id' => 'wamid.2']]])
        ->push(['error' => ['code' => 131026, 'message' => 'Undeliverable']], 400)]);
    config(['services.meta_whatsapp.batch_size' => 2]);
    $campaign = batchCampaign(Store::factory()->create(), 3);

    (new SendCampaignJob($campaign))->handle();

    $campaign->refresh();
    expect($campaign->status)->toBe('completed')
        ->and($campaign->sent_count)->toBe(2)
        ->and($campaign->failed_count)->toBe(1);
});

it('retries a batch on a transient Meta error without sending twice', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['messages' => [['id' => 'wamid.1']]])
        ->push(['error' => ['code' => 130429, 'message' => 'Rate limit hit']], 429)
        ->push(['messages' => [['id' => 'wamid.2']]])]);
    $campaign = batchCampaign(Store::factory()->create(), 2);
    $ids = $campaign->campaignMessages()->pluck('id')->all();

    expect(fn () => (new SendCampaignBatchJob($campaign->id, $ids))->handle())->toThrow(WhatsAppApiException::class);
    expect($campaign->campaignMessages()->where('status', 'sent')->count())->toBe(1);

    // Nouvelle tentative : seul le message encore en attente part.
    (new SendCampaignBatchJob($campaign->id, $ids))->handle();

    expect($campaign->campaignMessages()->where('status', 'sent')->count())->toBe(2);
    Http::assertSentCount(3);
});

it('marks the remaining messages failed after the last attempt', function () {
    $campaign = batchCampaign(Store::factory()->create(), 2);
    $ids = $campaign->campaignMessages()->pluck('id')->all();

    (new SendCampaignBatchJob($campaign->id, $ids))->failed(new RuntimeException('Meta indisponible'));

    expect($campaign->campaignMessages()->where('status', 'failed')->count())->toBe(2)
        ->and($campaign->campaignMessages()->first()->error_message)->toContain('Meta indisponible')
        ->and($campaign->fresh()->failed_count)->toBe(2);
});

it('marks the campaign failed when no message could be sent', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 132001, 'message' => 'Template does not exist']], 404)]);
    $campaign = batchCampaign(Store::factory()->create(), 2);

    (new SendCampaignJob($campaign))->handle();

    expect($campaign->fresh()->status)->toBe('failed');
});

it('refuses a campaign above the daily messaging limit of the number', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Adidas Glass', 'whatsapp_messaging_limit_tier' => 'TIER_50']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'promo', 'is_active' => true, 'status' => 'APPROVED']);
    Contact::factory()->count(51)->sequence(fn ($s) => ['phone_e164' => '24166'.str_pad((string) $s->index, 6, '0', STR_PAD_LEFT)])
        ->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);

    $this->actingAs($user)->post('/campaigns', [
        'name' => 'Trop large', 'contact_source' => 'segment', 'store_ids' => [$store->id],
        'message_type' => 'template', 'template_name' => 'promo',
    ])->assertSessionHas('error', fn (string $m) => str_contains($m, 'Adidas Glass') && str_contains($m, 'limite de 50'));

    expect(Campaign::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('counts recipients already messaged in the last 24 hours in the limit', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'whatsapp_messaging_limit_tier' => 'TIER_50']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'promo', 'is_active' => true, 'status' => 'APPROVED']);
    $previous = batchCampaign($store, 45);
    $previous->campaignMessages()->update(['status' => 'sent', 'updated_at' => now()->subHours(2)]);
    Contact::factory()->count(10)->sequence(fn ($s) => ['phone_e164' => '24165'.str_pad((string) $s->index, 6, '0', STR_PAD_LEFT)])
        ->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    Contact::where('store_id', $store->id)->update(['consent_status' => 'opt_in']);

    $this->actingAs($user)->post('/campaigns', [
        'name' => 'Relance', 'contact_source' => 'segment', 'store_ids' => [$store->id],
        'message_type' => 'template', 'template_name' => 'promo',
    ])->assertSessionHas('error', fn (string $m) => str_contains($m, 'déjà 45'));
});

it('shares indicative prices for the cost estimate', function () {
    config(['services.meta_whatsapp.pricing' => ['currency' => 'USD', 'marketing' => 0.03, 'utility' => 0.005, 'authentication' => 0.004]]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/campaigns')
        ->assertInertia(fn ($page) => $page->where('whatsappPricing.marketing', 0.03)->where('whatsappPricing.currency', 'USD'));
});
