<?php

use App\Jobs\SendCampaignJob;
use App\Jobs\SendWelcomeMessageJob;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeWhatsAppSend(): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200),
    ]);
}

function assertTemplateLanguageSent(string $templateName, string $language): void
{
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages')
        && $request['template']['name'] === $templateName
        && $request['template']['language']['code'] === $language);
}

function campaignFor(Store $store, Contact $contact, string $templateName): Campaign
{
    $campaign = Campaign::create([
        'name' => 'Nouvelle collection',
        'message_type' => 'template',
        'template_name' => $templateName,
        'status' => 'queued',
    ]);
    $campaign->stores()->attach($store->id);
    CampaignMessage::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'pending']);

    return $campaign;
}

it('sends a campaign template in the language registered for that template', function () {
    fakeWhatsAppSend();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'nouvelle_collection', 'language' => 'fr']);

    (new SendCampaignJob(campaignFor($store, $contact, 'nouvelle_collection')))->handle();

    assertTemplateLanguageSent('nouvelle_collection', 'fr');
});

it('uses the language of the template belonging to the store organization, not another organization', function () {
    fakeWhatsAppSend();

    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    MessageTemplate::factory()->create(['organization_id' => $otherOrganization->id, 'name' => 'promo', 'language' => 'en_US']);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'promo', 'language' => 'en']);

    (new SendCampaignJob(campaignFor($store, $contact, 'promo')))->handle();

    assertTemplateLanguageSent('promo', 'en');
});

it('falls back to french when the campaign template is not registered', function () {
    fakeWhatsAppSend();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);

    (new SendCampaignJob(campaignFor($store, $contact, 'template_inconnu')))->handle();

    assertTemplateLanguageSent('template_inconnu', 'fr');
});

it('sends the welcome template in the language registered for that template', function () {
    fakeWhatsAppSend();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);
    MessageTemplate::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'bienvenue_opt_in',
        'language' => 'fr',
        'category' => 'utility',
    ]);

    (new SendWelcomeMessageJob($contact, 'bienvenue_opt_in'))->handle();

    assertTemplateLanguageSent('bienvenue_opt_in', 'fr');
});
