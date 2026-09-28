<?php

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

it('creates a campaign scoped to a segment and dispatches the sending job', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world']);

    Contact::factory()->create(['store_id' => $store->id, 'gender' => 'F', 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $store->id, 'gender' => 'F', 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $store->id, 'gender' => 'H', 'consent_status' => 'opt_in']);

    $response = $this->actingAs($user)->post('/campaigns', [
        'name' => 'Vente privée VIP',
        'contact_source' => 'segment',
        'store_ids' => [$store->id],
        'message_type' => 'template',
        'template_name' => 'hello_world',
        'gender' => 'F',
    ]);

    $response->assertRedirect(route('campaigns.index'));

    $campaign = Campaign::first();
    expect($campaign)->not->toBeNull();
    expect($campaign->name)->toBe('Vente privée VIP');
    expect($campaign->status)->toBe('queued');
    expect($campaign->stores->pluck('id')->all())->toBe([$store->id]);
    expect($campaign->segment_filters)->toBe(['gender' => 'F']);
    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(2);

    Queue::assertPushed(SendCampaignJob::class, fn ($job) => $job->campaign->is($campaign));
});

it('targets contacts across several stores at once, each kept for its own store', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $storeA = Store::factory()->create(['organization_id' => $organization->id]);
    $storeB = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world']);

    Contact::factory()->create(['store_id' => $storeA->id, 'gender' => 'F', 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $storeB->id, 'gender' => 'F', 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $storeB->id, 'gender' => 'H', 'consent_status' => 'opt_in']);

    $response = $this->actingAs($user)->post('/campaigns', [
        'name' => 'Campagne multi-boutiques',
        'contact_source' => 'segment',
        'store_ids' => [$storeA->id, $storeB->id],
        'message_type' => 'template',
        'template_name' => 'hello_world',
        'gender' => 'F',
    ]);

    $response->assertRedirect(route('campaigns.index'));

    $campaign = Campaign::first();
    expect($campaign->stores->pluck('id')->sort()->values()->all())->toBe([$storeA->id, $storeB->id]);
    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(2);
});

it('refuses to create a campaign when no contact matches the segment', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world']);

    $response = $this->actingAs($user)->post('/campaigns', [
        'name' => 'Campagne test',
        'contact_source' => 'segment',
        'store_ids' => [$store->id],
        'message_type' => 'template',
        'template_name' => 'hello_world',
        'gender' => 'F',
    ]);

    $response->assertSessionHas('error');
    expect(Campaign::count())->toBe(0);
});

it('never targets opt_out or unknown contacts, even without an explicit consent filter', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world']);

    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_out']);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);

    $response = $this->actingAs($user)->post('/campaigns', [
        'name' => 'Campagne conformité',
        'contact_source' => 'segment',
        'store_ids' => [$store->id],
        'message_type' => 'template',
        'template_name' => 'hello_world',
    ]);

    $response->assertRedirect(route('campaigns.index'));

    $campaign = Campaign::first();
    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(1);
});

it('imports contacts from a file and targets the campaign at exactly those contacts', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world']);

    // Un contact déjà existant pour cette boutique qui ne doit PAS être ciblé.
    Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24170000000']);

    $csv = "nom,telephone\n".
        "Amanda Obame,74129782\n".
        "Jean Mba,66212551\n";

    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->actingAs($user)->post('/campaigns', [
        'name' => 'Campagne fichier',
        'contact_source' => 'file',
        'import_store_id' => $store->id,
        'file' => $file,
        'confirm_consent' => true,
        'message_type' => 'template',
        'template_name' => 'hello_world',
    ]);

    $response->assertRedirect(route('campaigns.index'));
    $response->assertSessionHas('success');

    $campaign = Campaign::first();
    expect($campaign)->not->toBeNull();
    expect($campaign->stores->pluck('id')->all())->toBe([$store->id]);
    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(2);
    expect(Contact::where('store_id', $store->id)->count())->toBe(3);
});

it('refuses to import into a store belonging to another organization when creating a campaign from a file', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world']);

    $file = UploadedFile::fake()->createWithContent('contacts.csv', "nom,telephone\nTest,74129782\n");

    $response = $this->actingAs($user)->post('/campaigns', [
        'name' => 'Campagne fichier',
        'contact_source' => 'file',
        'import_store_id' => $otherStore->id,
        'file' => $file,
        'confirm_consent' => true,
        'message_type' => 'template',
        'template_name' => 'hello_world',
    ]);

    $response->assertSessionHas('error');
    expect(Campaign::count())->toBe(0);
});

it('never reactivates an already opted-out contact when re-imported via a campaign file', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world']);

    $optedOut = Contact::factory()->create([
        'store_id' => $store->id,
        'phone_e164' => '24174129782',
        'consent_status' => 'opt_out',
    ]);

    $csv = "nom,telephone\n".
        "Amanda Obame,74129782\n". // même numéro que le contact désinscrit
        "Jean Mba,66212551\n";

    $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

    $response = $this->actingAs($user)->post('/campaigns', [
        'name' => 'Campagne fichier',
        'contact_source' => 'file',
        'import_store_id' => $store->id,
        'file' => $file,
        'confirm_consent' => true,
        'message_type' => 'template',
        'template_name' => 'hello_world',
    ]);

    $response->assertRedirect(route('campaigns.index'));

    expect($optedOut->fresh()->consent_status)->toBe('opt_out');

    $campaign = Campaign::first();
    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(1);
    expect(CampaignMessage::where('campaign_id', $campaign->id)->where('contact_id', $optedOut->id)->exists())->toBeFalse();
});

it('returns a live count of contacts matching a segment across selected stores', function () {
    $organization = Organization::factory()->create();
    $storeA = Store::factory()->create(['organization_id' => $organization->id]);
    $storeB = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    Contact::factory()->count(3)->create(['store_id' => $storeA->id, 'gender' => 'F', 'consent_status' => 'opt_in']);
    Contact::factory()->count(2)->create(['store_id' => $storeB->id, 'gender' => 'F', 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $storeA->id, 'gender' => 'H', 'consent_status' => 'opt_in']);

    $response = $this->actingAs($user)->getJson("/campaigns/segment-count?store_ids[]={$storeA->id}&store_ids[]={$storeB->id}&gender=F");

    $response->assertOk();
    $response->assertJson(['count' => 5]);
});
