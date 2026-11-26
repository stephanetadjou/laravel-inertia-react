<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Support\SectorPresets;
use Illuminate\Support\Facades\DB;

/*
 * Accueil (page d'arrivée de tous les secteurs) : « Mon activité » (6 chiffres
 * comparés au mois précédent), « À faire aujourd'hui », « Recommandations »,
 * « Comparaison des boutiques » (si plusieurs boutiques visibles) et
 * « Mon secteur ».
 */

function dashboardUserWithRole(Organization $organization, string $roleName, array $attributes = []): User
{
    Role::seedDefaultsFor($organization);

    return User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => Role::where('organization_id', $organization->id)->where('name', $roleName)->value('id'),
        ...$attributes,
    ]);
}

function homeTile(array $tiles, string $key): ?array
{
    return collect($tiles)->firstWhere('key', $key);
}

function homeSale(Store $store, ?Contact $contact, int $amount, $date): Purchase
{
    return Purchase::factory()->create(['contact_id' => $contact?->id, 'store_id' => $store->id, 'amount' => $amount, 'purchased_at' => $date]);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(9, 0));
});

it('shows the activity of the month compared to the previous month, scoped to the organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $otherStore = Store::factory()->create(['organization_id' => $other->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur', ['name' => 'Awa Ndong']);

    $alice = Contact::factory()->create(['store_id' => $store->id]);
    homeSale($store, $alice, 10000, now());
    homeSale($store, $alice, 40000, now()->subMonth());
    homeSale($otherStore, Contact::factory()->create(['store_id' => $otherStore->id]), 5000, now());
    Appointment::factory()->create(['store_id' => $store->id, 'customer_id' => $alice->customer_id, 'starts_at' => now()->setTime(15, 0), 'status' => 'planned']);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Dashboard/Index')
        ->where('header.first_name', 'Awa')
        ->where('filters.period', 'month')
        ->where('activity.tiles', fn ($tiles) => homeTile($tiles->all(), 'net')['value'] === 10000
            && homeTile($tiles->all(), 'net')['previous'] === 40000
            && homeTile($tiles->all(), 'active')['value'] === 1
            && homeTile($tiles->all(), 'basket')['value'] === 10000
            // Pas de vente anonyme ni importée : pas de tuile « Tickets identifiés ».
            && homeTile($tiles->all(), 'identification') === null
            && collect($tiles)->every(fn ($t) => $t['definition'] !== '' && $t['href'] !== ''))
        ->has('today', 1)
        ->where('comparison', null)
        ->where('stores', []));
});

it('counts an active customer once even with a contact in two stores, and shows the stores comparison', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $a = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'A']);
    $b = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'B']);
    $user = dashboardUserWithRole($organization, 'Super administrateur');

    $inA = Contact::factory()->create(['store_id' => $a->id, 'phone_e164' => '24177000001']);
    $inB = Contact::factory()->create(['store_id' => $b->id, 'phone_e164' => '24177000001']);
    expect($inA->fresh()->customer_id)->toBe($inB->fresh()->customer_id);
    homeSale($a, $inA, 10000, now());
    homeSale($b, $inB, 30000, now()->subDay());
    homeSale($b, null, 5000, now());

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('activity.tiles', fn ($tiles) => homeTile($tiles->all(), 'active')['value'] === 1
            && homeTile($tiles->all(), 'identification')['value'] === 67)
        ->has('stores', 2)
        ->where('comparison.rows.0.name', 'B')
        ->where('comparison.rows.0.net', 35000)
        ->where('comparison.active_sum', 2)
        ->where('comparison.active_total', 1));

    // Une boutique choisie : chiffres de cette boutique, plus de comparaison.
    $this->actingAs($user)->get("/dashboard?store={$a->id}")->assertInertia(fn ($page) => $page
        ->where('filters.store', $a->id)
        ->where('activity.tiles', fn ($tiles) => homeTile($tiles->all(), 'net')['value'] === 10000)
        ->where('comparison', null));
});

it('keeps the figures of a store manager to their store, even with another store in the query', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $mine = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $other = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $manager = dashboardUserWithRole($organization, 'Responsable de boutique', ['store_id' => $mine->id]);

    homeSale($mine, Contact::factory()->create(['store_id' => $mine->id]), 3000, now());
    homeSale($other, Contact::factory()->create(['store_id' => $other->id]), 8000, now());

    $this->actingAs($manager)->get("/dashboard?store={$other->id}")->assertInertia(fn ($page) => $page
        ->where('filters.store', null)
        ->where('activity.tiles', fn ($tiles) => homeTile($tiles->all(), 'net')['value'] === 3000)
        ->where('comparison', null)
        ->where('stores', []));
});

it('lists only the non-empty actions of the day, discussions first', function () {
    $organization = Organization::factory()->create(['sector' => 'beaute']);
    SectorPresets::apply($organization);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('todo', []));

    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    $contact->forceFill(['needs_attention' => true])->save();
    Task::open(['organization_id' => $organization->id, 'store_id' => $store->id, 'customer_id' => $contact->customer_id, 'contact_id' => $contact->id, 'kind' => 'callback', 'title' => 'Être rappelé']);
    CustomerMatchReview::create(['organization_id' => $organization->id, 'customer_id' => $contact->customer_id, 'store_id' => $store->id, 'phone_e164' => $contact->phone_e164,
        'incoming_name' => 'Autre nom', 'existing_name' => 'Nom', 'reason' => 'name_mismatch', 'purchase_ids' => [], 'status' => CustomerMatchReview::STATUS_PENDING]);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('todo.0.key', 'discussions')
        ->where('todo.0.count', 1)
        ->where('todo.1.key', 'tasks')
        ->where('todo', fn ($todo) => collect($todo)->firstWhere('key', 'reviews')['count'] === 1
            && collect($todo)->every(fn ($row) => $row['count'] > 0)));
});

it('recommends customers late on their rhythm, lapsed best customers and new customers to bring back', function () {
    $organization = Organization::factory()->create(['whatsapp_api_enabled' => true]);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');

    $late = Contact::factory()->create(['store_id' => $store->id]);
    homeSale($store, $late, 20000, now()->subDays(50));
    $best = Contact::factory()->create(['store_id' => $store->id]);
    homeSale($store, $best, 90000, now()->subDays(70));
    $newOnce = Contact::factory()->create(['store_id' => $store->id]);
    homeSale($store, $newOnce, 15000, now()->subDays(20));
    $newTwice = Contact::factory()->create(['store_id' => $store->id]);
    homeSale($store, $newTwice, 15000, now()->subDays(30));
    homeSale($store, $newTwice, 15000, now()->subDays(5));

    Customer::whereKey($late->fresh()->customer_id)->update(['churn_risk' => 'high']);
    Customer::whereKey($best->fresh()->customer_id)->update(['rfm_segment' => 'champions']);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('recommendations.0.key', 'rhythm')
        ->where('recommendations.0.count', 1)
        ->where('recommendations.0.stat.value', 20000)
        ->where('recommendations.1.count', 1)
        ->where('recommendations.2.count', 1)
        ->where('recommendations.0.campaign_href', fn ($href) => str_contains($href, 'campagne=1')));

    // La même audience dans Fiches clients.
    $this->actingAs($user)->get('/customers?'.http_build_query(['first_from' => now()->subDays(45)->toDateString(), 'first_to' => now()->subDays(15)->toDateString(), 'max_sales' => 1]))
        ->assertInertia(fn ($page) => $page->where('customers.total', 1));
});

it('keeps the sector tiles without repeating the activity figures', function (string $sector, array $keys) {
    $organization = Organization::factory()->create(['sector' => $sector]);
    SectorPresets::apply($organization);
    Store::factory()->create(['organization_id' => $organization->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('sector', fn ($tiles) => collect($tiles)->pluck('key')->all() === $keys));
})->with([
    'beauté' => ['beaute', ['appointments_today', 'to_confirm', 'to_recall']],
    'sport' => ['sport', ['appointments_today', 'active_subscriptions', 'overdue']],
    'formation' => ['formation', ['appointments_today', 'learners', 'exams', 'dropping']],
    'pro' => ['pro', ['open_prospects', 'quotes', 'to_recall']],
]);

it('shows the retail tiles to a shop', function () {
    $organization = Organization::factory()->create(['sector' => 'commerce']);
    SectorPresets::apply($organization);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');
    homeSale($store, Contact::factory()->create(['store_id' => $store->id]), 12000, now());

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('sector.0.key', 'sales_today')
        ->where('sector.0.value', 1));
});

it('keeps contacts, opt-in and VIP per store in Settings › Stores', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');
    $vip = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_out']);
    DB::table('contacts')->where('id', $vip->id)->update(['is_vip' => true]);

    $this->actingAs($user)->get('/stores')->assertInertia(fn ($page) => $page
        ->where('stores.0.contacts_count', 2)
        ->where('stores.0.opt_in_count', 1)
        ->where('stores.0.vip_count', 1));
});
