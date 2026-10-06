<?php

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

function chatUserWithRole(Organization $organization, string $roleName, array $attributes = []): User
{
    Role::seedDefaultsFor($organization);

    return User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => Role::where('organization_id', $organization->id)->where('name', $roleName)->value('id'),
        ...$attributes,
    ]);
}

function chatCampaignFor(Store $store, array $attributes = []): Campaign
{
    $campaign = Campaign::factory()->create(['message_type' => 'template', 'template_name' => 'promo_rentree', ...$attributes]);
    $campaign->stores()->sync([$store->id]);

    return $campaign;
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
});

it('lists campaign and welcome messages of the period, newest first, with stats', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $alice = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Alice']);
    $bob = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Bob', 'welcome_message_sent_at' => now()->subHour()]);
    $campaign = chatCampaignFor($store, ['name' => 'Soldes']);

    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $alice->id, 'status' => 'sent', 'updated_at' => now()->subHours(3)]);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $bob->id, 'status' => 'failed', 'error_message' => '131026 Numéro non joignable', 'updated_at' => now()->subHours(2)]);
    // En attente : pas encore envoyé, absent du journal.
    $pending = Contact::factory()->create(['store_id' => $store->id]);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $pending->id, 'status' => 'pending']);
    // Hors période (7 jours par défaut).
    CampaignMessage::factory()->create(['campaign_id' => chatCampaignFor($store)->id, 'contact_id' => $alice->id, 'status' => 'sent', 'updated_at' => now()->subDays(20)]);

    $this->actingAs($user)->get('/chat')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Chat/Index')
            ->where('filters.period', '7d')
            ->where('stats.sent', 2)
            ->where('stats.failed', 1)
            ->where('stats.failure_rate', 50)
            ->where('stats.clients', 2)
            ->where('messages.total', 3)
            ->where('messages.data.0.kind', 'welcome')
            ->where('messages.data.0.contact.id', $bob->id)
            ->where('messages.data.1.status', 'failed')
            ->where('messages.data.1.error_message', '131026 Numéro non joignable')
            ->where('messages.data.1.campaign.name', 'Soldes')
            ->where('messages.data.1.campaign.template', 'promo_rentree')
            ->where('messages.data.2.contact.id', $alice->id)
            // Sans contact demandé, le panneau montre le client du premier message.
            ->where('selectedContact.id', $bob->id));

    $this->actingAs($user)->get('/chat?period=30d')
        ->assertInertia(fn ($page) => $page->where('messages.total', 4));
});

it('filters by failures tab, type and search', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $alice = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Alice Mba', 'phone_e164' => '24177451288', 'welcome_message_sent_at' => now()]);
    $bob = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Bob Ondo', 'phone_e164' => '24166210347']);
    $campaign = chatCampaignFor($store);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $alice->id, 'status' => 'sent']);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $bob->id, 'status' => 'failed']);

    $this->actingAs($user)->get('/chat?tab=failed')
        ->assertInertia(fn ($page) => $page
            ->where('filters.tab', 'failed')
            ->where('messages.total', 1)
            ->where('messages.data.0.contact.id', $bob->id)
            // Les chiffres restent ceux de tous les messages.
            ->where('stats.total', 3));

    $this->actingAs($user)->get('/chat?type=welcome')
        ->assertInertia(fn ($page) => $page->where('messages.total', 1)->where('messages.data.0.kind', 'welcome'));

    $this->actingAs($user)->get('/chat?type=campaign')
        ->assertInertia(fn ($page) => $page->where('messages.total', 2));

    $this->actingAs($user)->get('/chat?q=ondo')
        ->assertInertia(fn ($page) => $page->where('messages.total', 1)->where('messages.data.0.contact.id', $bob->id));

    // Recherche par numéro, espaces tolérés.
    $this->actingAs($user)->get('/chat?q='.urlencode('77 45 12'))
        ->assertInertia(fn ($page) => $page->where('messages.total', 2));
});

it('paginates the log on the server', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $campaign = chatCampaignFor($store);

    Contact::factory()->count(20)->create(['store_id' => $store->id])->each(
        fn (Contact $contact) => CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent']),
    );

    $this->actingAs($user)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('messages.total', 20)->has('messages.data', 15)->where('messages.last_page', 2));

    $this->actingAs($user)->get('/chat?page=2')
        ->assertInertia(fn ($page) => $page->has('messages.data', 5)->where('messages.current_page', 2));
});

it('filters by brand or store within the role scope', function () {
    $organization = Organization::factory()->create();
    $mango = Brand::factory()->create(['organization_id' => $organization->id]);
    $adidas = Brand::factory()->create(['organization_id' => $organization->id]);
    $mangoA = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $mango->id]);
    $mangoB = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $mango->id]);
    $adidasStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $adidas->id]);
    $user = chatUserWithRole($organization, 'Administrateur');

    foreach ([$mangoA, $mangoB, $adidasStore] as $store) {
        Contact::factory()->create(['store_id' => $store->id, 'welcome_message_sent_at' => now()]);
    }

    $this->actingAs($user)->get("/chat?brand={$mango->id}")
        ->assertInertia(fn ($page) => $page->where('filters.brand', $mango->id)->where('messages.total', 2)->has('storeOptions', 2));

    $this->actingAs($user)->get("/chat?brand={$mango->id}&store={$mangoB->id}")
        ->assertInertia(fn ($page) => $page->where('filters.store', $mangoB->id)->where('messages.total', 1));

    // Boutique hors de la marque choisie : ignorée.
    $this->actingAs($user)->get("/chat?brand={$mango->id}&store={$adidasStore->id}")
        ->assertInertia(fn ($page) => $page->where('filters.store', null)->where('messages.total', 2));
});

it('restricts a brand manager and a store manager to their scope', function () {
    $organization = Organization::factory()->create();
    $own = Brand::factory()->create(['organization_id' => $organization->id]);
    $other = Brand::factory()->create(['organization_id' => $organization->id]);
    $ownStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $own->id]);
    $otherStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $other->id]);
    Contact::factory()->create(['store_id' => $ownStore->id, 'welcome_message_sent_at' => now()]);
    $foreign = Contact::factory()->create(['store_id' => $otherStore->id, 'welcome_message_sent_at' => now()]);

    $brandManager = chatUserWithRole($organization, 'Responsable de marque', ['brand_id' => $own->id]);
    $this->actingAs($brandManager)->get("/chat?brand={$other->id}&store={$otherStore->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filters.brand', $own->id)
            ->where('filters.brand_locked', true)
            ->where('filters.store', null)
            ->where('messages.total', 1)
            ->has('storeOptions', 1));

    $storeManager = chatUserWithRole($organization, 'Responsable de boutique', ['store_id' => $otherStore->id]);
    $this->actingAs($storeManager)->get('/chat')
        ->assertInertia(fn ($page) => $page
            ->where('filters.brand_hidden', true)
            ->where('messages.total', 1)
            ->where('messages.data.0.contact.id', $foreign->id));

    $this->actingAs($brandManager)->get("/chat/{$foreign->id}")->assertStatus(403);
});

it('shows the selected contact panel with timeline and last purchase', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create([
        'store_id' => $store->id,
        'consent_status' => 'opt_in',
        'welcome_message_sent_at' => now()->subDays(40),
    ]);

    $text = chatCampaignFor($store, ['message_type' => 'text', 'text_body' => 'Bonjour !']);
    CampaignMessage::factory()->create(['campaign_id' => $text->id, 'contact_id' => $contact->id, 'status' => 'sent', 'updated_at' => now()->subDay()]);
    Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => 38500, 'purchased_at' => now()->subDays(3)]);

    $this->actingAs($user)->get("/chat/{$contact->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('selectedContact.id', $contact->id)
            ->where('selectedContact.consent_status', 'opt_in')
            ->where('selectedContact.message_count', 2)
            ->where('selectedContact.last_purchase.amount', 38500)
            ->where('selectedContact.timeline.0.kind', 'welcome')
            ->where('selectedContact.timeline.1.body', 'Bonjour !'));
});

it('refuses to open the chat of a contact belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id]);

    $this->actingAs($user)->get("/chat/{$foreignContact->id}")->assertStatus(403);
});
