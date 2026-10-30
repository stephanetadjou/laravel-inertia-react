<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $this->brandA = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne A']);
    $this->brandB = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne B']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandA->id, 'name' => 'A Centre']);
    $this->storeB = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id, 'name' => 'B Gare']);
    $this->admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Admin groupe')->value('id'),
    ]);
    $this->boissons = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Boissons']);
    $this->sodas = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Sodas', 'parent_id' => $this->boissons->id]);
    $this->epicerie = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Épicerie']);

    $awa = Contact::factory()->create(['store_id' => $this->storeA->id, 'phone_e164' => '24162605054', 'name' => 'Awa']);
    $awaB = Contact::factory()->create(['store_id' => $this->storeB->id, 'phone_e164' => '24162605054', 'name' => 'Awa']);
    $jean = Contact::factory()->create(['store_id' => $this->storeA->id, 'phone_e164' => '24174000000', 'name' => 'Jean']);

    $ticket = function ($contact, $store, int $amount, $day, $category, string $type = 'sale', int $discount = 0) {
        $p = Purchase::create(['contact_id' => $contact?->id, 'store_id' => $store->id, 'amount' => $amount, 'purchased_at' => $day, 'type' => $type, 'discount_amount' => $discount]);
        PurchaseItem::create(['purchase_id' => $p->id, 'name' => 'Ligne', 'quantity' => $type === 'return' ? -1 : 1, 'unit_price' => abs($amount), 'amount' => $amount, 'discount_amount' => $discount, 'category_id' => $category->id]);

        return $p;
    };

    $ticket($awa, $this->storeA, 10000, today()->subDays(40), $this->sodas, 'sale', 1000);
    $ticket($awaB, $this->storeB, 6000, today()->subDays(10), $this->epicerie);
    $ticket($jean, $this->storeA, 4000, today()->subDays(5), $this->boissons);
    $ticket(null, $this->storeB, 2000, today()->subDays(3), $this->epicerie);
    $ticket($jean, $this->storeA, -1500, today()->subDays(2), $this->boissons, 'return');
});

it('computes the direction dashboard with net revenue, returns and identification', function () {
    $this->actingAs($this->admin)->get('/analytics/direction?period=90d')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Analytics/Index')
            ->where('data.kpis.net', 10000 + 6000 + 4000 + 2000 - 1500)
            ->where('data.kpis.returns', 1500)
            ->where('data.kpis.discounts', 1000)
            ->where('data.kpis.tickets', 4)
            ->where('data.kpis.basket', (int) round(22000 / 4))
            ->where('data.kpis.customers', 2)
            ->where('data.kpis.identification_rate', 75)
            ->where('data.kpis.new_customers', 2)
            ->where('data.tables.0.key', 'brands'));
});

it('filters by brand, store and family, the family including its sub-families', function () {
    $this->actingAs($this->admin)->get('/analytics/direction?period=90d&brand='.$this->brandB->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.kpis.net', 8000));

    $this->actingAs($this->admin)->get('/analytics/direction?period=90d&family='.$this->boissons->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.kpis.net', 10000 + 4000 - 1500));
});

it('analyses sales at the level chosen by the organization', function () {
    $this->organization->update(['product_analysis_level' => 'category']);

    $this->actingAs($this->admin)->get('/analytics/families?period=90d')->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.tables.0.title', 'Ventes par Catégorie')
            ->where('data.tables.0.rows.0.0', 'Boissons')
            ->where('data.tables.0.rows.0.1', 12500));
});

it('shows multi-brand customers, retention and import quality', function () {
    $this->actingAs($this->admin)->get('/analytics/clientele?period=90d')->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.kpis.buyers', 2)->where('data.kpis.multi_brand', 1));

    $this->actingAs($this->admin)->get('/analytics/retention?period=90d')->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.kpis.repeat_rate', 50));

    $this->actingAs($this->admin)->get('/analytics/quality?period=90d')->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.kpis.anonymous_share', 20));
});

it('exports a table with the same scope and filters, and audits it', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Responsable de marque')->value('id'),
        'brand_id' => $this->brandA->id,
    ]);

    $csv = $this->actingAs($manager)->get('/analytics/network/export/stores?period=90d&brand='.$this->brandB->id)->assertOk()->streamedContent();
    expect($csv)->toContain('A Centre')->not->toContain('B Gare');

    $this->actingAs($this->admin)->get('/analytics/clientele/export/top_customers?period=90d&format=xlsx')->assertOk();
    expect(AuditLog::where('action', 'export')->count())->toBe(2);

    $operator = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Opérateur d’import')->value('id'),
    ]);
    $this->actingAs($operator)->get('/analytics/network/export/stores')->assertForbidden();
});

it('renders the printable direction report', function () {
    $this->actingAs($this->admin)->get('/analytics/report?period=90d')->assertOk()
        ->assertSee('Rapport de direction')->assertSee('Comparaison des marques');
});
