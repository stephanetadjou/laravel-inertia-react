<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

function purchaseUserWithRole(Organization $organization, string $roleName, array $attributes = []): User
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

it('computes the period figures and compares them with the previous period', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $alice = Contact::factory()->create(['store_id' => $store->id]);
    $bob = Contact::factory()->create(['store_id' => $store->id]);

    // 7 derniers jours : 3 achats, 2 clients.
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 10000, 'quantity' => 1, 'purchased_at' => now()]);
    Purchase::factory()->create(['contact_id' => $alice->id, 'store_id' => $store->id, 'amount' => 20000, 'quantity' => 2, 'purchased_at' => now()->subDays(2)]);
    Purchase::factory()->create(['contact_id' => $bob->id, 'store_id' => $store->id, 'amount' => 30000, 'quantity' => 1, 'purchased_at' => now()->subDays(6)]);
    // Période précédente (J-13 à J-7).
    Purchase::factory()->create(['contact_id' => $bob->id, 'store_id' => $store->id, 'amount' => 40000, 'purchased_at' => now()->subDays(9)]);
    // Hors des deux périodes.
    Purchase::factory()->create(['contact_id' => $bob->id, 'store_id' => $store->id, 'amount' => 99999, 'purchased_at' => now()->subDays(60)]);

    $this->actingAs($user)->get('/purchases?period=7d')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Purchases/Index')
            ->where('filters.preset', '7d')
            ->where('kpis.current.revenue', 60000)
            ->where('kpis.current.purchases', 3)
            ->where('kpis.current.buyers', 2)
            ->where('kpis.current.quantity', 4)
            ->where('kpis.previous.revenue', 40000)
            ->where('kpis.previous.purchases', 1)
            ->has('purchases.data', 3)
            ->where('purchases.data.0.amount', 10000));

    $this->actingAs($user)->get('/purchases?from=2026-08-01&to=2026-08-31')
        ->assertInertia(fn ($page) => $page->where('filters.preset', null)->where('kpis.current.revenue', 99999));
});

it('filters by category, store and search, and sorts by amount', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $storeA = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $storeB = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $aicha = Contact::factory()->create(['store_id' => $storeA->id, 'name' => 'Aïcha Mba', 'phone_e164' => '24177451288']);
    $brice = Contact::factory()->create(['store_id' => $storeB->id, 'name' => 'Brice Obame', 'phone_e164' => '24166210347']);

    Purchase::factory()->create(['contact_id' => $aicha->id, 'store_id' => $storeA->id, 'amount' => 5000, 'product_category' => 'Robes', 'purchased_at' => now()]);
    Purchase::factory()->create(['contact_id' => $aicha->id, 'store_id' => $storeA->id, 'amount' => 45000, 'product_category' => 'Sacs', 'purchased_at' => now()->subDay()]);
    Purchase::factory()->create(['contact_id' => $brice->id, 'store_id' => $storeB->id, 'amount' => 15000, 'product_category' => 'Robes', 'purchased_at' => now()->subDays(2)]);

    $this->actingAs($user)->get('/purchases?category=Robes')
        ->assertInertia(fn ($page) => $page->where('filters.category', 'Robes')->where('kpis.current.revenue', 20000)->has('purchases.data', 2));

    $this->actingAs($user)->get("/purchases?store={$storeB->id}")
        ->assertInertia(fn ($page) => $page->where('filters.store', $storeB->id)->where('kpis.current.revenue', 15000));

    $this->actingAs($user)->get('/purchases?q=obame')
        ->assertInertia(fn ($page) => $page->has('purchases.data', 1)->where('purchases.data.0.contact.id', $brice->id));

    // Recherche par numéro (espaces tolérés) et par catégorie.
    $this->actingAs($user)->get('/purchases?q='.urlencode('77 45 12'))
        ->assertInertia(fn ($page) => $page->has('purchases.data', 2));
    $this->actingAs($user)->get('/purchases?q=sacs')
        ->assertInertia(fn ($page) => $page->where('kpis.current.revenue', 45000));

    $this->actingAs($user)->get('/purchases?sort=amount&dir=desc')
        ->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'amount')
            ->where('purchases.data.0.amount', 45000)
            ->where('purchases.data.2.amount', 5000));

    $this->actingAs($user)->get('/purchases')
        ->assertInertia(fn ($page) => $page->where('categories', ['Robes', 'Sacs']));
});

it('paginates purchases on the server', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id]);
    Purchase::factory()->count(30)->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'purchased_at' => now()]);

    $this->actingAs($user)->get('/purchases')
        ->assertInertia(fn ($page) => $page->has('purchases.data', 25)->where('purchases.total', 30)->where('purchases.last_page', 2));

    $this->actingAs($user)->get('/purchases?page=2')
        ->assertInertia(fn ($page) => $page->has('purchases.data', 5));

    $this->actingAs($user)->get('/purchases?per_page=50')
        ->assertInertia(fn ($page) => $page->has('purchases.data', 30)->where('filters.per_page', 50));
});

it('filters by brand and keeps each role within its scope', function () {
    $organization = Organization::factory()->create();
    $mango = Brand::factory()->create(['organization_id' => $organization->id]);
    $adidas = Brand::factory()->create(['organization_id' => $organization->id]);
    $mangoStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $mango->id]);
    $adidasStore = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $adidas->id]);

    foreach ([[$mangoStore, 10000], [$adidasStore, 25000]] as [$store, $amount]) {
        Purchase::factory()->create([
            'contact_id' => Contact::factory()->create(['store_id' => $store->id])->id,
            'store_id' => $store->id,
            'amount' => $amount,
            'purchased_at' => now(),
        ]);
    }

    $admin = purchaseUserWithRole($organization, 'Administrateur');
    $this->actingAs($admin)->get("/purchases?brand={$mango->id}")
        ->assertInertia(fn ($page) => $page->where('filters.brand', $mango->id)->has('filters.brands', 2)->where('kpis.current.revenue', 10000));

    $brandManager = purchaseUserWithRole($organization, 'Responsable de marque', ['brand_id' => $adidas->id]);
    $this->actingAs($brandManager)->get("/purchases?brand={$mango->id}&store={$mangoStore->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filters.brand', $adidas->id)
            ->where('filters.brand_locked', true)
            ->where('filters.store', null)
            ->where('kpis.current.revenue', 25000)
            ->has('stores', 1));

    $storeManager = purchaseUserWithRole($organization, 'Responsable de boutique', ['store_id' => $mangoStore->id]);
    $this->actingAs($storeManager)->get('/purchases')
        ->assertInertia(fn ($page) => $page->where('filters.brand_hidden', true)->where('kpis.current.revenue', 10000));
});
