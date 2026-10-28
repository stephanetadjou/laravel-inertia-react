<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\Loyalty;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10 12:00'));
    $this->organization = Organization::factory()->create([
        'whatsapp_api_enabled' => false,
        'loyalty_settings' => [
            'enabled' => true,
            'fcfa_per_point' => 1000,
            'starts_on' => '2026-11-01',
            'tiers' => [['name' => 'Bronze', 'min_points' => 0], ['name' => 'Argent', 'min_points' => 50], ['name' => 'Or', 'min_points' => 200]],
        ],
    ]);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
    $this->contact = Contact::factory()->create(['store_id' => $this->store->id, 'name' => 'Estelle Ndong']);
    $this->customer = $this->contact->customer;
    $this->reward = LoyaltyReward::create(['organization_id' => $this->organization->id, 'name' => 'Brushing offert', 'points_cost' => 40]);
});

function loyaltyPurchase(int $amount, string $on = '2026-11-05'): Purchase
{
    return Purchase::factory()->create(['contact_id' => test()->contact->id, 'store_id' => test()->store->id, 'amount' => $amount, 'purchased_at' => $on]);
}

it('earns one point per 1 000 FCFA from the launch date', function () {
    loyaltyPurchase(25500);
    loyaltyPurchase(80000, '2026-10-15'); // avant le lancement

    $loyalty = Loyalty::for($this->organization);
    expect($loyalty->balance($this->customer))->toBe(25)
        ->and($loyalty->tier($this->customer)['current']['name'])->toBe('Bronze')
        ->and($loyalty->tier($this->customer)['next']['name'])->toBe('Argent');

    // Montant corrigé : les points suivent ; achat supprimé : ils sont retirés.
    $purchase = loyaltyPurchase(10000);
    expect($loyalty->balance($this->customer))->toBe(35);
    $purchase->update(['amount' => 30000]);
    expect($loyalty->balance($this->customer))->toBe(55)
        ->and($loyalty->tier($this->customer)['current']['name'])->toBe('Argent');
    $purchase->delete();
    expect($loyalty->balance($this->customer))->toBe(25);
});

it('earns nothing while the programme is off', function () {
    $this->organization->update(['loyalty_settings' => [...$this->organization->loyalty_settings, 'enabled' => false]]);
    loyaltyPurchase(50000);

    expect(LoyaltyTransaction::count())->toBe(0);
});

it('redeems a reward from the customer page when the balance allows it', function () {
    loyaltyPurchase(30000);

    $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/loyalty/redeem", ['reward_id' => $this->reward->id])
        ->assertSessionHasErrors('reward');

    loyaltyPurchase(15000);
    $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/loyalty/redeem", ['reward_id' => $this->reward->id])
        ->assertSessionHasNoErrors();

    expect(Loyalty::for($this->organization)->balance($this->customer))->toBe(5)
        ->and(LoyaltyTransaction::where('type', 'redeem')->sole()->user_id)->toBe($this->admin->id);

    $this->actingAs($this->admin)->get("/customers/{$this->customer->id}")
        ->assertInertia(fn ($page) => $page
            ->where('loyalty.balance', 5)
            ->where('loyalty.tier', 'Bronze')
            ->where('loyalty.rewards.0.name', 'Brushing offert')
            ->where('loyalty.history.0.type', 'Récompense'));
});

it('lets managers adjust points, never below zero', function () {
    loyaltyPurchase(10000);

    $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/loyalty/adjust", ['points' => -20, 'note' => 'Correction'])
        ->assertSessionHasErrors('points');
    $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/loyalty/adjust", ['points' => 15, 'note' => 'Geste commercial'])
        ->assertSessionHasNoErrors();
    expect(Loyalty::for($this->organization)->balance($this->customer))->toBe(25);

    $seller = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->store->id,
    ]);
    $this->actingAs($seller)->post("/customers/{$this->customer->id}/loyalty/adjust", ['points' => 100, 'note' => 'x'])->assertForbidden();
    $this->actingAs($seller)->post("/customers/{$this->customer->id}/loyalty/redeem", ['reward_id' => $this->reward->id])->assertSessionHasErrors('reward');

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->post("/customers/{$this->customer->id}/loyalty/redeem", ['reward_id' => $this->reward->id])->assertForbidden();
});

it('configures rules, tiers and rewards', function () {
    $this->actingAs($this->admin)->put('/settings/loyalty', [
        'enabled' => true, 'fcfa_per_point' => 500, 'starts_on' => '2026-11-08',
        'tiers' => [['name' => 'Membre', 'min_points' => 0], ['name' => 'VIP', 'min_points' => 400]],
    ])->assertSessionHasNoErrors();

    expect($this->organization->fresh()->loyaltySettings())->toMatchArray(['fcfa_per_point' => 500, 'starts_on' => '2026-11-08'])
        ->and(collect($this->organization->fresh()->loyaltySettings()['tiers'])->pluck('name')->all())->toBe(['Membre', 'VIP']);

    $this->actingAs($this->admin)->post('/settings/loyalty/rewards', ['name' => 'Soin offert', 'points_cost' => 300])->assertSessionHasNoErrors();
    $soin = LoyaltyReward::where('name', 'Soin offert')->sole();
    $this->actingAs($this->admin)->post("/settings/loyalty/rewards/{$soin->id}/toggle");
    expect($soin->fresh()->active)->toBeFalse();

    $this->actingAs($this->admin)->post("/customers/{$this->customer->id}/loyalty/redeem", ['reward_id' => $soin->id])->assertSessionHasErrors('reward_id');
    $this->actingAs($this->admin)->get('/settings/loyalty')->assertOk()->assertInertia(fn ($page) => $page->component('Settings/Loyalty'));
});
