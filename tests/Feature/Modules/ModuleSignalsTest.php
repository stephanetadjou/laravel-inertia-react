<?php

use App\Models\Attendance;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\CustomerScoring;
use App\Services\CustomerSearch;
use App\Services\DailyReport;
use App\Services\GroupClasses;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'sport', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['seances_groupe', 'abonnements', 'tranches', 'beneficiaires'], ['abonnements' => ['period' => 'month', 'season_start' => '2026-10-07', 'season_end' => '2027-06-30']]);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->parent = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Mireille Mba', 'consent_status' => 'opt_in'])->customer;
    $this->actingAs($this->admin)->post("/customers/{$this->parent->id}/beneficiaries", ['name' => 'Léo Mba', 'relation' => 'parent']);
    $this->leo = Customer::where('name', 'Léo Mba')->firstOrFail();
    $this->other = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24166000111', 'name' => 'Paul Obame', 'consent_status' => 'opt_in'])->customer;

    $this->plan = Plan::create(['organization_id' => $this->organization->id, 'name' => 'Natation mensuel', 'price' => 15000, 'period' => 'month']);
    $this->actingAs($this->admin)->post('/subscriptions', ['customer_id' => $this->leo->id, 'plan_id' => $this->plan->id, 'store_id' => $this->store->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-11-15']);

    $this->class = GroupClass::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Natation', 'weekday' => 3, 'start_time' => '15:00', 'end_time' => '16:00', 'capacity' => 20, 'starts_on' => '2026-09-01']);
    app(GroupClasses::class)->enroll($this->class, $this->leo, null, Carbon::parse('2026-09-01'));
    app(GroupClasses::class)->enroll($this->class, $this->other, null, Carbon::parse('2026-09-01'));
    app(GroupClasses::class)->ensureSessions($this->organization->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-08'));
    // Léo absent aux 3 dernières séances (16, 23, 30 sept.), Paul présent.
    foreach (ClassSession::whereIn('session_date', ['2026-09-16', '2026-09-23', '2026-09-30'])->get() as $session) {
        app(GroupClasses::class)->mark($session, [$this->leo->id => 'absent', $this->other->id => 'present']);
    }
});

function search($test, array $filters): array
{
    return (new CustomerSearch($test->admin->fresh(), $filters))->query()->orderBy('name')->pluck('name')->all();
}

it('targets customers by module signals', function () {
    expect(search($this, ['follow' => 'absent:3']))->toBe(['Léo Mba'])
        ->and(search($this, ['follow' => "class:{$this->class->id}"]))->toBe(['Léo Mba', 'Paul Obame'])
        // L'abonnement concerne l'enfant et son parent.
        ->and(search($this, ['follow' => 'ending:60']))->toBe(['Léo Mba', 'Mireille Mba'])
        ->and(search($this, ['follow' => 'ending:7']))->toBe([]);

    // Le 15 octobre, la mensualité d'octobre (due le 7) est en retard.
    $this->travelTo(now()->setDate(2026, 10, 15));
    expect(search($this, ['follow' => 'unpaid']))->toBe(['Léo Mba', 'Mireille Mba']);

    $this->actingAs($this->admin)->get('/customers?follow=absent:3')->assertInertia(fn (Assert $page) => $page
        ->where('filters.follow', 'absent:3')
        ->where('filters.follow_options', fn ($o) => collect($o)->contains('value', 'unpaid') && collect($o)->contains('value', "class:{$this->class->id}")));
});

it('reaches the parent when a campaign targets enrolled children', function () {
    $recipients = (new CustomerSearch($this->admin->fresh(), ['follow' => 'absent:3']))->recipients(null)->pluck('customer_id')->all();

    expect($recipients)->toBe([$this->parent->id]);
});

it('raises the churn alert on repeated absences and unpaid installments', function () {
    app(CustomerScoring::class)->refreshOrganization($this->organization);
    expect($this->leo->fresh()->churn_risk)->toBe('medium')
        ->and($this->other->fresh()->churn_risk)->toBeNull();

    $this->travelTo(now()->setDate(2026, 11, 20));
    app(CustomerScoring::class)->refreshOrganization($this->organization);
    // Tranches d'octobre impayées depuis plus de 30 jours.
    expect($this->leo->fresh()->churn_risk)->toBe('high')
        ->and($this->parent->fresh()->churn_risk)->toBe('high')
        ->and(CustomerScoring::signals($this->leo->fresh(), $this->organization))->toContain('2 tranche(s) en retard (30 000 FCFA)');
});

it('lists absent members and subscriptions to renew in the actions of the day', function () {
    $absent = (new ActionLists($this->admin->fresh()))->rows('absent_members');
    expect($absent)->toHaveCount(1)
        ->and($absent[0]['recipient_name'])->toBe('Mireille Mba')
        ->and($absent[0]['message'])->toContain('absences aux derniers cours de Léo');

    $this->travelTo(now()->setDate(2026, 11, 5));
    $ending = (new ActionLists($this->admin->fresh()))->rows('subscription_ending');
    expect($ending)->toHaveCount(1)
        ->and($ending[0]['name'])->toBe('Léo Mba')
        ->and($ending[0]['message'])->toContain('« Natation mensuel » de Léo se termine le dimanche 15 novembre');
});

it('adds payments, attendance and renewals to the evening report', function () {
    $session = ClassSession::where('session_date', '2026-10-07')->firstOrFail();
    app(GroupClasses::class)->mark($session, [$this->leo->id => 'present', $this->other->id => 'absent']);
    $installment = Installment::orderBy('due_on')->first();
    $this->actingAs($this->admin)->post("/installments/{$installment->id}/pay", ['payment_method' => 'cash']);

    $report = (new DailyReport($this->organization->fresh(), today()))->build();

    expect($report['modules']['collected'])->toBe(15000)
        // Fin le 15 novembre : pas encore dans les 30 jours.
        ->and($report['modules']['renewals'])->toBe(0)
        ->and($report['modules']['sessions'][0])->toMatchArray(['name' => 'Natation', 'present' => 1, 'absent' => 1])
        ->and(Attendance::count())->toBe(8);

    $this->travelTo(now()->setDate(2026, 10, 20));
    expect((new DailyReport($this->organization->fresh(), today()))->build()['modules']['renewals'])->toBe(1);
});
