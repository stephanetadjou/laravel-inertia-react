<?php

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;

it('shows a contact profile with rfm summary, purchase history and campaign messages', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Amanda Obame']);

    Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => 50000, 'purchased_at' => now()->subDays(10)]);
    Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => 30000, 'purchased_at' => now()->subDays(40)]);

    $campaign = Campaign::factory()->create(['name' => 'Vente VIP']);
    $campaign->stores()->attach($store->id);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent']);

    $response = $this->actingAs($user)->get("/contacts/{$contact->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('contact.name', 'Amanda Obame')
        ->where('rfm.frequency', 2)
        ->where('rfm.monetary', 80000)
        ->where('rfm.recency_days', 10)
        ->has('purchases', 2)
        ->has('campaignMessages', 1)
        ->where('campaignMessages.0.campaign_name', 'Vente VIP'),
    );
});

it('refuses to show a contact belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id]);

    $this->actingAs($user)->get("/contacts/{$foreignContact->id}")->assertStatus(403);
});
