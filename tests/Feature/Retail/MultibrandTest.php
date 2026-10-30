<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BrandMetrics;
use App\Services\RfmCalculator;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $this->brandA = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne A']);
    $this->brandB = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne B']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandA->id]);
    $this->storeB = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id]);
    $this->admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Admin groupe')->value('id'),
    ]);

    // Awa achète dans les deux marques, Jean et Paul seulement dans la marque A.
    $buy = function (string $phone, Store $store, array $amounts) {
        $contact = Contact::where('store_id', $store->id)->where('phone_e164', $phone)->first()
            ?? Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => $phone]);
        foreach ($amounts as $i => $amount) {
            Purchase::create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => $amount, 'purchased_at' => today()->subDays(10 + $i * 20)]);
        }
    };
    $buy('24162605054', $this->storeA, [50000, 40000, 30000]);
    $buy('24162605054', $this->storeB, [8000]);
    $buy('24174000000', $this->storeA, [2000]);
    $buy('24166000000', $this->storeA, [9000, 7000]);

    app(BrandMetrics::class)->refreshOrganization($this->organization);
});

it('keeps one customer file for a customer of two brands, with metrics per brand', function () {
    $awa = Customer::where('phone_e164', '24162605054')->sole();

    expect(Customer::count())->toBe(3)
        ->and($awa->brands_count)->toBe(2)
        ->and($awa->total_spent)->toBe(128000);

    $metrics = DB::table('customer_brand_metrics')->where('customer_id', $awa->id)->get()->keyBy('brand_id');
    expect((int) $metrics[$this->brandA->id]->order_count)->toBe(3)
        ->and((int) $metrics[$this->brandA->id]->net_spend)->toBe(120000)
        ->and((int) $metrics[$this->brandB->id]->net_spend)->toBe(8000)
        ->and($metrics[$this->brandA->id]->rfm_segment)->not->toBeNull();
});

it('filters customers by multi-brand and by segment within a brand', function () {
    $this->actingAs($this->admin)->get('/customers?brands=multi')->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.total', 1));

    $this->actingAs($this->admin)->get('/customers?brands=mono')->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.total', 2));

    $segment = DB::table('customer_brand_metrics')->where('brand_id', $this->brandB->id)->value('rfm_segment');
    $this->actingAs($this->admin)->get("/customers?brand={$this->brandB->id}&segment={$segment}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.total', 1));

    // Vente croisée : clients de la marque A jamais venus dans la marque B.
    $this->actingAs($this->admin)->get("/customers?brand={$this->brandA->id}&never_brand={$this->brandB->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.total', 2));
});

it('shows the shared customers matrix, limited to the brand of a brand manager', function () {
    $this->actingAs($this->admin)->get('/multibrand')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Multibrand/Index')
            ->where('kpis.multi', 1)
            ->where('matrix.0.customers', 3)
            ->where('matrix.0.shared.1', 1));

    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Responsable de marque')->value('id'),
        'brand_id' => $this->brandB->id,
    ]);
    $this->actingAs($manager)->get('/multibrand')->assertOk()
        ->assertInertia(fn ($page) => $page->where('brands', fn ($brands) => collect($brands)->pluck('name')->all() === ['Enseigne B']));
});

it('applies the RFM rules of the organization and keeps a versioned history', function () {
    expect(RfmCalculator::segmentKey(5, 5, 5, 3))->toBe('champions')
        ->and(RfmCalculator::segmentKey(5, 5, 5, 3, ['champions_min_fm' => 5, 'champions_min_r' => 5]))->toBe('champions')
        ->and(RfmCalculator::segmentKey(4, 4, 4, 3, ['champions_min_r' => 5]))->toBe('loyal');

    $this->actingAs($this->admin)->put('/settings/rfm', [
        'period_months' => 12, 'active_min_r' => 3, 'champions_min_r' => 5, 'champions_min_fm' => 5,
        'loyal_min_fm' => 3, 'cant_lose_min_fm' => 4, 'at_risk_min_fm' => 3,
    ])->assertSessionHas('success');

    expect($this->organization->fresh()->rfmSettings()['version'])->toBe(2)
        ->and(DB::table('customer_segment_history')->where('rules_version', 2)->whereNull('brand_id')->count())->toBeGreaterThan(0)
        ->and(AuditLog::where('action', 'settings.updated')->exists())->toBeTrue();
});

it('refreshes brand metrics after a sales import', function () {
    expect(DB::table('customer_brand_metrics')->count())->toBe(4);

    Purchase::query()->delete();
    app(BrandMetrics::class)->refreshOrganization($this->organization);

    expect(DB::table('customer_brand_metrics')->count())->toBe(0)
        ->and(Customer::where('brands_count', '>', 0)->count())->toBe(0);
});
