<?php

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\SalesTeam;
use Inertia\Testing\AssertableInertia as Assert;

it('loads the fictional Menentia company with prospects, sales reps and commissions', function () {
    $this->artisan('demo:menentia')->assertSuccessful();

    $organization = Organization::where('slug', 'demo-menentia')->sole();
    $stages = Customer::where('organization_id', $organization->id)->whereNotNull('prospect_stage')->pluck('prospect_stage')->countBy();

    expect($stages->sum())->toBe(40)
        ->and($stages['client'])->toBe(12)
        ->and($stages['perdu'])->toBeGreaterThan(0)
        ->and(Customer::where('organization_id', $organization->id)->where('prospect_stage', 'perdu')->whereNull('lost_reason')->exists())->toBeFalse();

    $director = User::where('email', 'directeur@demo-menentia.test')->sole();
    $rep = User::where('email', 'commercial1@demo-menentia.test')->sole();
    expect((new ActionLists($rep))->rows('prospect_followup'))->not->toBeEmpty()
        ->and(collect((new SalesTeam($organization))->board($director)))->toHaveCount(2);

    $this->actingAs($rep)->get('/prospects')->assertInertia(fn (Assert $page) => $page->where('owners', [])->where('prospects', fn ($p) => count($p) > 0));
    $this->actingAs($director)->get('/sales-team')->assertInertia(fn (Assert $page) => $page->has('board', 2));
});
