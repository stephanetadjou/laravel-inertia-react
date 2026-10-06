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

function dashboardUserWithRole(Organization $organization, string $roleName, array $attributes = []): User
{
    Role::seedDefaultsFor($organization);

    return User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => Role::where('organization_id', $organization->id)->where('name', $roleName)->value('id'),
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
});

it('shows consolidated counters scoped to the user organization', function () {
    // Sans compte Meta partagé, une boutique sans identifiants propres n'est pas connectée.
    config(['services.meta_whatsapp.token' => null, 'services.meta_whatsapp.phone_number_id' => null]);

    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $store = Store::factory()->create(['organization_id' => $organization->id]);
    Store::factory()->create([
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
        ->component('Dashboard/Index')
        ->where('kpis.contacts.current', 3)
        ->where('kpis.opt_in.opt_in', 2)
        ->where('kpis.opt_in.opt_out', 1)
        ->where('filters.preset', '30d')
        ->where('filters.store_count', 2)
        ->has('stores', 2)
        ->where('stores', fn ($stores) => collect($stores)->where('connected', true)->count() === 1)
        ->has('recentCampaigns', 1)
        ->has('messagesSeries', 30)
        ->has('salesSeries', 30),
    );
});

it('computes revenue, buyers and messages for the period and the previous one', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $alice = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in', 'created_at' => now()->subDays(60)]);
    $bob = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);

    // Période courante (7 derniers jours) : 2 achats d'Alice + 1 de Bob.
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 10000, 'product_category' => 'Robes', 'purchased_at' => now()->subDays(1)]);
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 5000, 'product_category' => 'Sacs', 'purchased_at' => now()]);
    Purchase::factory()->create(['contact_id' => $bob->id, 'store_id' => $store->id, 'amount' => 20000, 'product_category' => 'Robes', 'purchased_at' => now()->subDays(3)]);
    // Période précédente (J-13 à J-7).
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 7000, 'purchased_at' => now()->subDays(10)]);
    // Hors des deux périodes.
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 99999, 'purchased_at' => now()->subDays(40)]);

    $campaign = Campaign::factory()->create();
    $campaign->stores()->sync([$store->id]);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $alice->id, 'status' => 'sent']);
    CampaignMessage::factory()->create(['campaign_id' => $campaign->id, 'contact_id' => $bob->id, 'status' => 'failed']);
    $bob->update(['welcome_message_sent_at' => now()->subDay()]);

    $response = $this->actingAs($user)->get('/dashboard?period=7d');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.preset', '7d')
        ->where('filters.days', 7)
        ->where('kpis.revenue.current', 35000)
        ->where('kpis.revenue.previous', 7000)
        ->where('kpis.buyers.current', 2)
        ->where('kpis.buyers.previous', 1)
        ->where('kpis.buyers.purchases', 3)
        ->where('kpis.contacts.current', 2)
        ->where('kpis.contacts.new', 1)
        ->where('kpis.contacts.previous', 1)
        // 1 message de campagne + 1 message de bienvenue.
        ->where('kpis.messages.current', 2)
        ->where('kpis.messages.campaigns', 1)
        ->where('kpis.failure.failed', 1)
        ->where('kpis.failure.attempted', 2)
        ->has('messagesSeries', 7)
        ->where('salesSeries.6.revenue', 5000)
        ->where('salesSeries.3.previous', 7000)
        ->where('topCategories.0.name', 'Robes')
        ->where('topCategories.0.revenue', 30000)
        ->where('topCategories.0.clients', 2)
        ->where('topCategories.0.purchases', 2),
    );
});

it('groups long periods by week and accepts explicit dates', function () {
    $organization = Organization::factory()->create();
    Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->get('/dashboard?period=90d')
        ->assertInertia(fn ($page) => $page->where('filters.days', 90)->has('salesSeries', 13));

    $this->actingAs($user)->get('/dashboard?from=2026-09-01&to=2026-09-10')
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2026-09-10')
            ->where('filters.preset', null)
            ->where('filters.days', 10));

    // Dates inversées : période par défaut.
    $this->actingAs($user)->get('/dashboard?from=2026-09-10&to=2026-09-01')
        ->assertInertia(fn ($page) => $page->where('filters.preset', '30d'));
});

it('filters every block by brand for an organization-wide role', function () {
    $organization = Organization::factory()->create();
    $brandA = Brand::factory()->create(['organization_id' => $organization->id, 'name' => 'Mango']);
    $brandB = Brand::factory()->create(['organization_id' => $organization->id, 'name' => 'Adidas']);
    $storeA = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brandA->id]);
    $storeB = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brandB->id]);
    $user = dashboardUserWithRole($organization, 'Administrateur');

    Contact::factory()->count(2)->create(['store_id' => $storeA->id, 'consent_status' => 'opt_in']);
    $contactB = Contact::factory()->create(['store_id' => $storeB->id, 'consent_status' => 'unknown']);
    Purchase::factory()->create(['contact_id' => $contactB->id, 'store_id' => $storeB->id, 'amount' => 12000, 'purchased_at' => now()]);

    $this->actingAs($user)->get("/dashboard?brand={$brandA->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filters.brand', $brandA->id)
            ->where('filters.brand_locked', false)
            ->has('filters.brands', 2)
            ->where('kpis.contacts.current', 2)
            ->where('kpis.revenue.current', 0)
            ->has('stores', 1)
            ->where('stores.0.id', $storeA->id)
            ->has('newClients', 2)
            // Le bloc « Contacts par marque » garde toutes les marques pour permettre de basculer.
            ->has('brandContacts', 2));

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('filters.brand', null)
            ->where('kpis.contacts.current', 3)
            ->where('kpis.revenue.current', 12000));
});

it('ignores a brand filter pointing outside the organization', function () {
    $organization = Organization::factory()->create();
    Store::factory()->create(['organization_id' => $organization->id]);
    $foreignBrand = Brand::factory()->create();
    $user = dashboardUserWithRole($organization, 'Administrateur');

    $this->actingAs($user)->get("/dashboard?brand={$foreignBrand->id}")
        ->assertInertia(fn ($page) => $page->where('filters.brand', null)->where('filters.store_count', 1));
});

it('locks a brand manager on their own brand', function () {
    $organization = Organization::factory()->create();
    $ownBrand = Brand::factory()->create(['organization_id' => $organization->id]);
    $otherBrand = Brand::factory()->create(['organization_id' => $organization->id]);
    $ownStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $ownBrand->id]);
    $otherStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $otherBrand->id]);
    $user = dashboardUserWithRole($organization, 'Responsable de marque', ['brand_id' => $ownBrand->id]);

    Contact::factory()->create(['store_id' => $ownStore->id]);
    Contact::factory()->count(3)->create(['store_id' => $otherStore->id]);

    // Même en demandant l'autre marque, le périmètre reste celui de sa marque.
    $this->actingAs($user)->get("/dashboard?brand={$otherBrand->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filters.brand', $ownBrand->id)
            ->where('filters.brand_locked', true)
            ->where('filters.brand_hidden', false)
            ->has('filters.brands', 1)
            ->where('kpis.contacts.current', 1)
            ->has('stores', 1)
            ->where('stores.0.id', $ownStore->id)
            ->has('brandContacts', 1));
});

it('hides the brand filter for a store manager and scopes to their store', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $sibling = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $store->brand_id]);
    $user = dashboardUserWithRole($organization, 'Responsable de boutique', ['store_id' => $store->id]);

    Contact::factory()->count(2)->create(['store_id' => $store->id]);
    Contact::factory()->create(['store_id' => $sibling->id]);

    $this->actingAs($user)->get("/dashboard?brand={$store->brand_id}")
        ->assertInertia(fn ($page) => $page
            ->where('filters.brand', null)
            ->where('filters.brand_hidden', true)
            ->where('kpis.contacts.current', 2)
            ->has('stores', 1)
            ->where('stores.0.id', $store->id));
});

it('lists the newest contacts of the period with their consent and welcome status', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $pending = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown', 'created_at' => now()->subDays(2)]);
    $optOut = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_out', 'created_at' => now()->subDay()]);
    $welcomed = Contact::factory()->create([
        'store_id' => $store->id,
        'consent_status' => 'unknown',
        'welcome_message_sent_at' => now(),
        'created_at' => now(),
    ]);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown', 'created_at' => now()->subDays(90)]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->has('newClients', 3)
            ->where('newClients.0.id', $welcomed->id)
            ->where('newClients.0.welcomed', true)
            ->where('newClients.1.id', $optOut->id)
            ->where('newClients.1.consent_status', 'opt_out')
            ->where('newClients.2.id', $pending->id)
            ->where('newClients.2.welcomed', false)
            ->where('pendingWelcome', 1));
});

it('shows the customer journey funnel and the moves of the period', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $prospect = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);
    $prospect->update(['consent_status' => 'opt_in']);
    $buyer = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    Purchase::factory()->create(['contact_id' => $buyer->id, 'store_id' => $store->id, 'amount' => 10000, 'purchased_at' => now()->subDay()]);
    Contact::factory()->create(['store_id' => Store::factory()->create()->id]); // autre organisation

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('lifecycle.total', 2)
            ->where('lifecycle.stages', fn ($stages) => collect($stages)->firstWhere('key', 'prospect')['count'] === 1)
            ->where('lifecycle.moves.new_buyers', 1)
            // Seul acheteur de la boutique : VIP de cette boutique.
            ->where('lifecycle.vip', 1)
            ->where('stores.0.vip', 1)
            // Seul le passage Contact → Prospect compte : la première étape
            // calculée d'une fiche n'est pas un mouvement.
            ->where('lifecycle.moves.new_prospects', 1));
});
