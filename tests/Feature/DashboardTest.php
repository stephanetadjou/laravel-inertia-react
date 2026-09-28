<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('shows consolidated counters scoped to the user organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $unconnectedStore = Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_access_token' => null,
        'whatsapp_phone_number_id' => null,
    ]);
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);

    $user = User::factory()->create(['organization_id' => $organization->id]);

    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_out']);
    Contact::factory()->create(['store_id' => $otherStore->id, 'consent_status' => 'opt_in']);

    $campaign = Campaign::factory()->create(['sent_count' => 3, 'failed_count' => 1]);
    $campaign->stores()->sync([$store->id]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('stats.total_contacts', 3)
        ->where('stats.opt_in_contacts', 2)
        ->where('stats.opt_out_contacts', 1)
        ->where('stats.total_stores', 2)
        ->where('stats.connected_stores', 1)
        ->has('stores', 2)
        ->has('recentCampaigns', 1),
    );

    expect($unconnectedStore->id)->not->toBeNull();
});

it('lists recent unknown-consent contacts still waiting for a welcome message', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $pending = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown', 'name' => 'À accueillir']);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in', 'name' => 'Déjà opt-in']);
    Contact::factory()->create([
        'store_id' => $store->id,
        'consent_status' => 'unknown',
        'welcome_message_sent_at' => now(),
        'name' => 'Déjà accueilli',
    ]);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown', 'created_at' => now()->subDays(90), 'name' => 'Trop ancien']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('recentClients', 1)
        ->where('recentClients.0.id', $pending->id),
    );
});
