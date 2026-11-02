<?php

use App\Models\ClassSession;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActionLists;
use Inertia\Testing\AssertableInertia as Assert;

it('loads the fictional sports club, idempotently', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(9, 0));
    $other = Organization::factory()->create(['name' => 'Autre entreprise']);

    $this->artisan('demo:sport')->assertSuccessful();
    $this->artisan('demo:sport')->assertSuccessful();

    $organization = Organization::where('slug', 'demo-sport')->sole();
    $children = Customer::where('organization_id', $organization->id)->whereNotNull('responsible_id');

    expect($organization->enabledModules())->toContain('seances_groupe', 'abonnements', 'tranches', 'beneficiaires', 'lieux')
        ->and($organization->hasAgenda())->toBeFalse()
        ->and($children->count())->toBe(120)
        ->and(Customer::where('organization_id', $organization->id)->has('beneficiaries', '>=', 2)->exists())->toBeTrue()
        ->and(GroupClass::where('organization_id', $organization->id)->pluck('name')->sort()->values()->all())->toBe(['Danse enfants', 'Football enfants', 'Judo 5-7 ans', 'Judo 8-12 ans', 'Natation enfants'])
        ->and(ClassSession::where('organization_id', $organization->id)->where('status', 'cancelled')->where('cancel_reason', 'weather')->count())->toBe(2)
        ->and(ClassSession::where('organization_id', $organization->id)->whereNotNull('makeup_of_id')->value('session_date'))->toBe('2026-11-07')
        ->and(Installment::overdue()->whereHas('schedule', fn ($q) => $q->where('organization_id', $organization->id))->exists())->toBeTrue()
        ->and(Organization::find($other->id))->not->toBeNull();

    $director = User::where('email', 'directeur@demo-sport.test')->sole();
    $lists = new ActionLists($director);
    expect($lists->rows('class_makeup'))->not->toBeEmpty()
        ->and($lists->rows('absent_members'))->not->toBeEmpty();

    $this->actingAs($director)->get('/classes')->assertInertia(fn (Assert $page) => $page->has('classes', 5));
    $this->actingAs($director)->get('/installments?status=overdue')->assertInertia(fn (Assert $page) => $page->where('stats.overdue_count', fn ($n) => $n > 0));
});
