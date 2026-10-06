<?php

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;

// L'ancienne fiche contact est fusionnée dans la fiche client.

it('redirects the old contact page to the customer page', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id]);

    $this->actingAs($user)->get("/contacts/{$contact->id}")
        ->assertRedirect("/customers/{$contact->fresh()->customer_id}");
});

it('shows rfm, the last purchases and messages on the customer page', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create([
        'store_id' => $store->id,
        'name' => 'Amanda Obame',
        'custom_attributes' => ['Taille' => 'M', 'Pointure' => ''],
    ]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'bienvenue', 'category' => 'utility', 'is_active' => true]);

    foreach (range(1, 7) as $i) {
        Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => 10000, 'purchased_at' => now()->subDays($i * 10)]);
    }

    $campaign = Campaign::factory()->create(['name' => 'Vente VIP']);
    $campaign->stores()->attach($store->id);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent']);

    $this->actingAs($user)->get("/customers/{$contact->fresh()->customer_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->where('customer.name', 'Amanda Obame')
            ->where('rfm.frequency', 7)
            ->where('rfm.monetary', 70000)
            ->where('rfm.recency_days', 10)
            ->has('purchases', 5)
            ->where('purchases.0.date', now()->subDays(10)->toDateString())
            ->where('firstPurchaseAt', now()->subDays(70)->toDateString())
            ->has('messages', 1)
            ->where('messagesCount', 1)
            ->where('messages.0.label', 'Vente VIP')
            ->where('contacts.0.attributes', ['Taille' => 'M'])
            ->where('welcomeTemplates', ['bienvenue']),
        );
});

it('refuses the old contact page for a contact of another organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignContact = Contact::factory()->create(['store_id' => Store::factory()->create()->id]);

    $this->actingAs($user)->get("/contacts/{$foreignContact->id}")->assertStatus(403);
});
