<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerScoring;
use App\Services\LoyaltyEngine;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-04 10:00'));
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $role = fn (string $name) => Role::where('organization_id', $this->organization->id)->where('name', $name)->value('id');
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Admin groupe')]);
    $this->adidas = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $this->mango = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mango']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->adidas->id, 'name' => 'Adidas Glass']);
    $this->storeM = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->mango->id, 'name' => 'Mango Akanda']);
    $this->manager = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Responsable de marque'), 'brand_id' => $this->mango->id]);

    $program = LoyaltyProgram::create(['organization_id' => $this->organization->id, 'name' => 'Fidélité 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    foreach ([$this->adidas, $this->mango] as $brand) {
        $program->rules()->create(['brand_id' => $brand->id, 'threshold' => 400000, 'tranche_amount' => 10000, 'points_per_tranche' => 1, 'activation_bonus' => 50, 'tiers' => [['points' => 300, 'percent' => 10]], 'validity_months' => 12, 'reset_after_use' => true]);
    }

    $buy = function (Store $store, int $amount, string $on, ?string $phone, array $extra = []) {
        $contact = $phone ? (Contact::where('store_id', $store->id)->where('phone_e164', $phone)->first() ?? Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => $phone, 'name' => "Client {$phone}x"])) : null;
        Purchase::create(['contact_id' => $contact?->id, 'store_id' => $store->id, 'amount' => $amount, 'purchased_at' => $on, ...$extra]);
    };

    $buy($this->storeA, 500000, '2026-10-01', '24162000001');
    $buy($this->storeA, 2500000, '2026-10-02', '24162000002', ['loyalty_discount_percent' => null]);
    $buy($this->storeA, 100000, '2026-10-20', '24162000002', ['loyalty_discount_percent' => 10]);
    $buy($this->storeM, 350000, '2026-10-05', '24162000003');
    $buy($this->storeM, 50000, '2026-10-06', null);
    $buy($this->storeA, 200000, '2025-10-10', '24162000001');

    app(CustomerScoring::class)->refreshOrganization($this->organization);
    app(LoyaltyEngine::class)->recalculate($this->organization);
});

function biData($test, string $url, ?User $user = null): array
{
    return $test->actingAs($user ?? $test->admin)->get($url)->assertOk()->viewData('page')['props'];
}

function kpi(array $data, string $label): array
{
    return collect($data['kpis'])->firstWhere('label', $label);
}

it('shows the direction page with both comparisons', function () {
    $props = biData($this, '/pilotage/direction?from=2026-10-01&to=2026-10-31');
    $data = $props['data'];

    expect(kpi($data, 'CA net')['value'])->toBe(3500000)
        ->and(kpi($data, 'Tickets')['value'])->toBe(5)
        ->and(kpi($data, 'Taux d’identification')['value'])->toBe(80)
        ->and(kpi($data, 'Clients actifs')['value'])->toBe(3)
        ->and(kpi($data, 'Taux de réachat')['value'])->toBe(33)
        ->and(kpi($data, 'CA net')['last_year'])->toBe(200000)
        ->and(kpi($data, 'CA net')['previous'])->toBe(0)
        ->and($data['monthly'][0]['last_year'])->toBe(200000)
        ->and(collect($data['charts'][0]['items'])->firstWhere('label', 'Mango')['value'])->toBe(400000);
});

it('filters every figure by brand, store, segment and loyalty status', function () {
    $base = 'from=2026-10-01&to=2026-10-31';

    expect(kpi(biData($this, "/pilotage/direction?{$base}&brand={$this->mango->id}")['data'], 'CA net')['value'])->toBe(400000)
        ->and(kpi(biData($this, "/pilotage/direction?{$base}&store={$this->storeA->id}")['data'], 'CA net')['value'])->toBe(3100000)
        ->and(kpi(biData($this, "/pilotage/direction?{$base}&loyalty_status=none")['data'], 'CA net')['value'])->toBe(350000);

    $segment = Customer::where('phone_e164', '24162000001')->value('rfm_segment');
    $expected = Customer::where('rfm_segment', $segment)->count();
    expect(kpi(biData($this, "/pilotage/direction?{$base}&segment={$segment}")['data'], 'Clients actifs')['value'])->toBe($expected);
});

it('stays inside the scope of the role', function () {
    expect(kpi(biData($this, '/pilotage/direction?from=2026-10-01&to=2026-10-31', $this->manager)['data'], 'CA net')['value'])->toBe(400000);
});

it('reads loyalty figures and action lists', function () {
    $data = biData($this, '/pilotage/fidelite?from=2026-10-01&to=2026-10-31')['data'];

    expect(kpi($data, 'Membres actifs')['value'])->toBe(2)
        ->and(kpi($data, 'Remises utilisées')['value'])->toBe(1)
        ->and(kpi($data, 'Coût des remises')['value'])->toBe(11111)
        ->and(kpi($data, 'Proches du seuil')['value'])->toBe(1)
        ->and(collect($data['lists'])->firstWhere('key', 'near_threshold')['rows'][0][2])->toBe('Mango')
        ->and(collect($data['lists'])->firstWhere('key', 'near_threshold')['audience'])->toBe(['loyalty_status' => 'near_threshold']);
});

it('renders every tab', function (string $tab) {
    $data = biData($this, "/pilotage/{$tab}")['data'];

    expect($data)->toHaveKeys(['kpis', 'charts', 'lists']);
})->with(['direction', 'clients', 'fidelite', 'enseignes', 'familles', 'qualite']);

it('hides the loyalty tab without a programme', function () {
    LoyaltyProgram::query()->delete();

    $this->actingAs($this->admin)->get('/pilotage/fidelite')->assertNotFound();
});

it('exports a list to Excel, audited, for roles allowed to export', function () {
    $this->actingAs($this->admin)->get('/pilotage/fidelite/export/near_threshold?from=2026-10-01&to=2026-10-31')->assertOk();

    expect(AuditLog::where('action', 'export')->exists())->toBeTrue();

    $reader = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Opérateur d’import')->value('id')]);
    $this->actingAs($reader)->get('/pilotage/fidelite/export/near_threshold')->assertForbidden();
});
