<?php

use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\KnowledgeEntry;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\Organization;
use App\Models\User;
use App\Modules\CarteDigitale\Models\DigitalCard;
use Illuminate\Support\Carbon;

it('loads the demo with every loyalty situation, once', function () {
    $this->travelTo(Carbon::parse('2026-11-04 10:00'));

    $this->artisan('demo:retailgroup-mode', ['--clients' => 60])->assertSuccessful();
    $this->artisan('demo:retailgroup-mode')->assertSuccessful();

    $organization = Organization::where('slug', 'retailgroup-mode')->sole();
    $cards = LoyaltyCard::where('organization_id', $organization->id);

    expect($organization->brands()->pluck('name')->sort()->values()->all())->toBe(['Adidas', 'Carré', 'Mango'])
        ->and(LoyaltyProgram::where('organization_id', $organization->id)->count())->toBe(2)
        ->and(User::where('email', 'admin.mode@retailgroup-demo.test')->exists())->toBeTrue()
        ->and(Customer::where('organization_id', $organization->id)->count())->toBeGreaterThan(40)
        ->and((clone $cards)->where('status', 'none')->exists())->toBeTrue()
        ->and((clone $cards)->where('status', 'active')->whereNotNull('discount_percent')->exists())->toBeTrue()
        ->and((clone $cards)->where('status', 'expired')->exists())->toBeTrue()
        ->and((clone $cards)->where('discounts_used', '>', 0)->exists())->toBeTrue();

    $journal = LoyaltyMovement::where('organization_id', $organization->id)->pluck('type')->unique();
    expect($journal->all())->toContain('activation', 'bonus', 'gain', 'tier', 'discount_used', 'return', 'expiration');

    // Démonstration : une carte digitale par magasin, base de l'Assistant IA, un seul cas « À vérifier ».
    expect(DigitalCard::where('organization_id', $organization->id)->count())->toBe($organization->stores()->count())
        ->and(KnowledgeEntry::where('organization_id', $organization->id)->where('title', 'Retours et échanges')->exists())->toBeTrue()
        ->and(CustomerMatchReview::where('organization_id', $organization->id)->where('status', 'pending')->count())->toBeLessThanOrEqual(1)
        ->and($organization->hasModule('assistant'))->toBeTrue();

    $this->artisan('fidelite:recalculer', ['--organization' => $organization->id])->assertSuccessful();
});
