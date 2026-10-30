<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BrandMetrics;
use App\Services\CustomerScoring;
use App\Services\RetailAnalytics;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $this->brandA = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alpha']);
    $this->brandB = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Beta']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandA->id, 'name' => 'Alpha Centre']);
    $this->storeB = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id, 'name' => 'Beta Gare']);
    $this->admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Admin groupe')->value('id'),
    ]);
    $robes = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Robes']);
    $sandales = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Sandales']);
    $costumes = Category::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Costumes']);

    $ticket = function (?Contact $contact, Store $store, int $amount, int $daysAgo, Category $family, string $type = 'sale') {
        $p = Purchase::create(['contact_id' => $contact?->id, 'store_id' => $store->id, 'amount' => $amount, 'purchased_at' => today()->subDays($daysAgo), 'type' => $type]);
        PurchaseItem::create(['purchase_id' => $p->id, 'name' => $family->name, 'quantity' => $type === 'return' ? -1 : 1, 'unit_price' => abs($amount), 'amount' => $amount, 'category_id' => $family->id]);
    };
    $contact = fn (Store $store, int $i) => Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '2416'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'consent_status' => 'opt_in']);

    // Six bonnes clientes d'Alpha qui n'achètent plus depuis 7 mois.
    foreach (range(1, 6) as $i) {
        $c = $contact($this->storeA, $i);
        foreach ([300, 260, 230, 210] as $day) {
            $ticket($c, $this->storeA, 90000, $day, $robes);
        }
    }

    // Quinze clients réguliers d'Alpha, récents, jamais venus chez Beta.
    foreach (range(10, 24) as $i) {
        $c = $contact($this->storeA, $i);
        $ticket($c, $this->storeA, 10000, 40, $robes);
        $ticket($c, $this->storeA, 10000, 10, $robes);
    }

    // Costumes : forte baisse sur les 90 derniers jours.
    $ticket(null, $this->storeA, 1200000, 120, $costumes);
    $ticket(null, $this->storeA, 300000, 30, $costumes);

    // Beta : beaucoup de tickets anonymes, et des sandales souvent retournées.
    foreach (range(1, 40) as $i) {
        $ticket($i <= 20 ? null : $contact($this->storeB, 100 + $i), $this->storeB, 20000, 5 + $i, $sandales);
    }
    $ticket(null, $this->storeB, -120000, 3, $sandales, 'return');

    app(CustomerScoring::class)->refreshOrganization($this->organization);
    app(BrandMetrics::class)->refreshOrganization($this->organization);
});

it('lists the key findings of the direction dashboard with a suggested decision and a link', function () {
    $response = $this->actingAs($this->admin)->get('/analytics/direction?period=12m')->assertOk();
    $insights = collect($response->viewData('page')['props']['data']['insights']);

    expect($insights->count())->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(5)
        ->and($insights->every(fn ($i) => filled($i['title']) && filled($i['action']) && str_starts_with($i['link'], '/')))->toBeTrue();

    $keys = $insights->pluck('key');
    expect($keys)->toContain('lost_loyal', 'anonymous', 'returns');
    expect($insights->firstWhere('key', 'lost_loyal')['title'])->toContain('Alpha')->toContain('6 bons clients');
    expect($insights->firstWhere('key', 'anonymous')['title'])->toContain('Beta Gare')->toContain('50 %');
});

it('computes each rule on its own', function () {
    $analytics = new RetailAnalytics($this->organization, [$this->storeA->id, $this->storeB->id], today()->subYear(), today()->endOfDay());
    $all = collect($analytics->insights(10))->keyBy('key');

    expect($all['cross_sell']['title'])->toContain('21 clients fidèles de Alpha')->toContain('Beta')
        ->and($all['family_down']['title'])->toContain('Costumes')->toContain('-75 %')
        ->and($all['returns']['title'])->toContain('Sandales');
});

it('keeps the findings inside the brand of a brand manager', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Responsable de marque')->value('id'),
        'brand_id' => $this->brandA->id,
    ]);

    $insights = collect($this->actingAs($manager)->get('/analytics/direction?period=12m')->viewData('page')['props']['data']['insights']);
    expect($insights->pluck('title')->implode(' '))->not->toContain('Beta');
});
