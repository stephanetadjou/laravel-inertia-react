<?php

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('lists contacts sorted by their most recent message, with a preview', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $older = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Ancien contact']);
    $newer = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Contact récent']);
    $noMessage = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Sans message']);

    $campaign = Campaign::factory()->create(['message_type' => 'text', 'text_body' => 'Bonjour !']);
    $campaign->stores()->attach($store->id);

    CampaignMessage::factory()->create([
        'campaign_id' => $campaign->id,
        'contact_id' => $older->id,
        'status' => 'sent',
        'updated_at' => now()->subDays(5),
    ]);
    CampaignMessage::factory()->create([
        'campaign_id' => $campaign->id,
        'contact_id' => $newer->id,
        'status' => 'sent',
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/chat');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('contacts.0.id', $newer->id)
        ->where('contacts.0.last_message_preview', 'Bonjour !')
        ->where('contacts.1.id', $older->id)
        ->where('selectedContact', null),
    );

    // Le contact sans message doit quand même apparaître dans la liste.
    $response->assertInertia(fn ($page) => $page->has('contacts', 3));
    expect($noMessage->id)->not->toBeNull();
});

it('shows the message thread for a selected contact', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id]);

    $campaign = Campaign::factory()->create(['message_type' => 'template', 'template_name' => 'hello_world']);
    $campaign->stores()->attach($store->id);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent']);

    $response = $this->actingAs($user)->get("/chat/{$contact->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('selectedContact.id', $contact->id)
        ->has('messages', 1)
        ->where('messages.0.body', 'Template WhatsApp : hello_world'),
    );
});

it('refuses to open the chat of a contact belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id]);

    $this->actingAs($user)->get("/chat/{$foreignContact->id}")->assertStatus(403);
});
