<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Support\SectorPresets;
use Illuminate\Support\Facades\DB;

/*
 * Accueil épuré : « Pour bien démarrer », 4 chiffres clés par secteur, actions
 * du jour et prochains rendez-vous. Les anciens blocs (contacts par marque,
 * performance par boutique, graphiques) sont dans Analyses et Paramètres ›
 * Établissements.
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

function homeKpi(array $kpis, string $key): ?array
{
    return collect($kpis)->firstWhere('key', $key);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(9, 0));
});

it('shows four key figures, scoped to the user organization', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $otherStore = Store::factory()->create(['organization_id' => $other->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');

    $alice = Contact::factory()->create(['store_id' => $store->id]);
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 10000, 'purchased_at' => now()]);
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 99999, 'purchased_at' => now()->subMonth()]);
    Purchase::factory()->create(['contact_id' => Contact::factory()->create(['store_id' => $otherStore->id])->id, 'store_id' => $otherStore->id, 'amount' => 5000, 'purchased_at' => now()]);
    Appointment::factory()->create(['store_id' => $store->id, 'customer_id' => $alice->customer_id, 'starts_at' => now()->setTime(15, 0), 'status' => 'planned']);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Dashboard/Index')
        ->has('kpis', 4)
        ->where('kpis', fn ($kpis) => homeKpi($kpis->all(), 'revenue')['value'] === 10000
            && homeKpi($kpis->all(), 'appointments_today')['value'] === 1
            && homeKpi($kpis->all(), 'to_confirm')['value'] === 1)
        ->has('today', 1)
        ->where('today.0.time', '15h00')
        ->missing('stores')
        ->missing('brandContacts'));
});

it('adapts the key figures to the sector', function (string $sector, array $keys) {
    $organization = Organization::factory()->create(['sector' => $sector]);
    SectorPresets::apply($organization);
    Store::factory()->create(['organization_id' => $organization->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('kpis', fn ($kpis) => collect($kpis)->pluck('key')->all() === $keys));
})->with([
    'beauté' => ['beaute', ['appointments_today', 'to_confirm', 'to_recall', 'revenue']],
    'sport' => ['sport', ['appointments_today', 'active_subscriptions', 'overdue', 'new_contacts']],
    'pro' => ['pro', ['open_prospects', 'quotes', 'to_recall', 'revenue']],
    'boutiques' => ['commerce', ['revenue', 'buyers', 'at_risk', 'new_contacts']],
]);

it('lists the actions of the day, discussions and requests to handle first', function () {
    $organization = Organization::factory()->create(['sector' => 'beaute']);
    SectorPresets::apply($organization);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = dashboardUserWithRole($organization, 'Super administrateur');
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    $contact->forceFill(['needs_attention' => true])->save();
    Task::open(['organization_id' => $organization->id, 'store_id' => $store->id, 'customer_id' => $contact->customer_id, 'contact_id' => $contact->id, 'kind' => 'callback', 'title' => 'Être rappelé']);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('todo.discussions', 1)
        ->where('todo.tasks', 1)
        ->where('actions', fn ($actions) => ! collect($actions)->contains('key', 'tasks')));
});

it('scopes the key figures to the store of a store manager', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $mine = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $other = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $manager = dashboardUserWithRole($organization, 'Responsable de boutique', ['store_id' => $mine->id]);

    Purchase::factory()->create(['contact_id' => Contact::factory()->create(['store_id' => $mine->id])->id, 'store_id' => $mine->id, 'amount' => 3000, 'purchased_at' => now()]);
    Purchase::factory()->create(['contact_id' => Contact::factory()->create(['store_id' => $other->id])->id, 'store_id' => $other->id, 'amount' => 8000, 'purchased_at' => now()]);

    $this->actingAs($manager)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('kpis', fn ($kpis) => homeKpi($kpis->all(), 'revenue')['value'] === 3000));
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
