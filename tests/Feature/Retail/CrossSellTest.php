<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BrandMetrics;
use App\Services\CustomerScoring;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'commerce', 'whatsapp_api_enabled' => false]);
    Role::seedDefaultsFor($this->organization);
    $this->brandA = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alpha']);
    $this->brandB = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Beta']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandA->id, 'name' => 'Alpha Centre']);
    $this->storeB = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id, 'name' => 'Beta Gare']);
    $this->admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Admin groupe')->value('id'),
    ]);

    // Dix clients d'Alpha, réguliers et récents ; « Awa » (le plus gros) n'est jamais allée chez Beta,
    // « Paul » achète déjà dans les deux marques.
    foreach (range(1, 10) as $i) {
        $contact = Contact::factory()->create(['store_id' => $this->storeA->id, 'phone_e164' => '2416200000'.($i - 1), 'name' => $i === 10 ? 'Awa Ndong' : "Client {$i}", 'consent_status' => 'opt_in']);
        foreach (range(1, $i <= 3 ? 1 : 4) as $n) {
            Purchase::create(['contact_id' => $contact->id, 'store_id' => $this->storeA->id, 'amount' => 5000 * $i, 'purchased_at' => today()->subDays(5 + $n * 15)]);
        }
    }
    $paul = Contact::factory()->create(['store_id' => $this->storeB->id, 'phone_e164' => '24162000008', 'name' => 'Client 9']);
    Purchase::create(['contact_id' => $paul->id, 'store_id' => $this->storeB->id, 'amount' => 1000, 'purchased_at' => today()->subDays(3)]);

    app(CustomerScoring::class)->refreshOrganization($this->organization);
    app(BrandMetrics::class)->refreshOrganization($this->organization);
});

it('lists loyal customers of a brand who never bought in the other one', function () {
    $response = $this->actingAs($this->admin)->get('/actions?list=cross_sell&brand='.$this->brandA->id)->assertOk();

    $rows = collect($response->viewData('page')['props']['rows']);
    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('name'))->toContain('Awa Ndong')
        ->and($rows->pluck('name'))->not->toContain('Client 9')
        ->and($rows->firstWhere('name', 'Awa Ndong')['reason'])->toContain('jamais venu chez Beta')
        ->and($rows->firstWhere('name', 'Awa Ndong')['message'])->toContain('Beta')
        ->and($rows->firstWhere('name', 'Awa Ndong')['whatsapp_url'])->toStartWith('https://wa.me/');
});

it('hides the cross-sell list from a single-brand perimeter', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Responsable de marque')->value('id'),
        'brand_id' => $this->brandA->id,
    ]);

    $this->actingAs($manager)->get('/actions')->assertOk()
        ->assertInertia(fn ($page) => $page->where('lists', fn ($lists) => ! collect($lists)->contains('key', 'cross_sell')));
});

it('builds a group from multi-brand and cross-sell filters', function () {
    $group = Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'À faire découvrir Beta']);

    $this->actingAs($this->admin)->post("/groups/{$group->id}/customers", [
        'all' => 1,
        'filters' => ['brand' => $this->brandA->id, 'never_brand' => $this->brandB->id],
    ])->assertSessionHas('success');

    $members = $group->contacts()->pluck('name');
    expect($members)->toContain('Awa Ndong')->not->toContain('Client 9');

    $multi = Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Multimarques']);
    $this->actingAs($this->admin)->post("/groups/{$multi->id}/customers", ['all' => 1, 'filters' => ['brands' => 'multi']]);
    expect($multi->contacts()->distinct()->pluck('customer_id')->count())->toBe(1);
});

it('previews a campaign audience with brand segment filters', function () {
    $segment = DB::table('customer_brand_metrics')
        ->where('brand_id', $this->brandA->id)->where('customer_id', Customer::where('name', 'Awa Ndong')->value('id'))->value('rfm_segment');

    $this->actingAs($this->admin)->getJson('/customers/audience?all=1&brand='.$this->brandA->id.'&segment='.$segment)->assertOk()
        ->assertJsonPath('customers', fn ($n) => $n >= 1);
});
