<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentSchedule;
use App\Models\Plan;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'sport', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['abonnements', 'tranches', 'beneficiaires', 'seances_groupe'], [
        'abonnements' => ['period' => 'month', 'season_start' => '2026-10-07', 'season_end' => '2027-06-30'],
        'tranches' => ['due_day' => 5, 'reminders' => [-2, 0, 7]],
    ]);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->parent = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Mireille Mba', 'consent_status' => 'opt_in'])->customer;
    $this->actingAs($this->admin)->post("/customers/{$this->parent->id}/beneficiaries", ['name' => 'Léo Mba', 'relation' => 'parent']);
    $this->leo = Customer::where('name', 'Léo Mba')->firstOrFail();
});

function judoPlan($test): Plan
{
    $test->actingAs($test->admin)->post('/plans', [
        'name' => 'Judo enfants', 'price' => 13000, 'period' => 'month',
        'fees' => [['label' => 'Inscription', 'amount' => 10000], ['label' => 'Assurance', 'amount' => 17000]],
        'options' => [['label' => 'Transport', 'amount' => 15000, 'recurring' => true], ['label' => 'Kimono', 'amount' => 30000, 'recurring' => false]],
    ])->assertSessionHas('success');

    return Plan::firstOrFail();
}

it('creates plans with one-off fees and options', function () {
    $plan = judoPlan($this);

    expect($plan->fees)->toBe([['label' => 'Inscription', 'amount' => 10000], ['label' => 'Assurance', 'amount' => 17000]])
        ->and($plan->options[0])->toBe(['label' => 'Transport', 'amount' => 15000, 'recurring' => true])
        ->and($plan->priceLabel())->toBe('13 000 FCFA / mois');

    $this->actingAs($this->admin)->get('/plans')->assertInertia(fn (Assert $page) => $page->component('Plans/Index')->has('plans', 1));
});

it('subscribes a child and prepares the schedule of the season', function () {
    $plan = judoPlan($this);

    $this->actingAs($this->admin)->post('/subscriptions', [
        'customer_id' => $this->leo->id, 'plan_id' => $plan->id, 'store_id' => $this->store->id,
        'starts_on' => '2026-10-07', 'options' => [1],
    ])->assertSessionHas('success');

    $subscription = Subscription::firstOrFail();
    $installments = PaymentSchedule::findOrFail($subscription->payment_schedule_id)->installments;

    expect($subscription->customer_id)->toBe($this->parent->id)
        ->and($subscription->beneficiary_id)->toBe($this->leo->id)
        ->and($subscription->ends_on->toDateString())->toBe('2027-06-30')
        // Inscription, assurance, kimono le jour du début, puis 9 mensualités d'octobre à juin.
        ->and($installments)->toHaveCount(12)
        ->and($installments->take(4)->pluck('label')->all())->toBe(['Inscription', 'Assurance', 'Kimono', 'Mensualité octobre 2026'])
        ->and($installments->take(4)->pluck('amount')->all())->toBe([10000, 17000, 30000, 13000])
        ->and($installments[4]->due_on->toDateString())->toBe('2026-11-05')
        ->and($installments->last()->label)->toBe('Mensualité juin 2027');

    $this->actingAs($this->admin)->get("/customers/{$this->leo->id}")->assertInertia(fn (Assert $page) => $page
        ->has('subscriptionModule.subscriptions', 1)
        ->where('subscriptionModule.subscriptions.0.plan', 'Judo enfants')
        ->where('subscriptionModule.subscriptions.0.total', 30000 + 17000 + 10000 + 9 * 13000));
});

it('adds recurring options to every monthly installment and stops cleanly', function () {
    $plan = judoPlan($this);
    $this->actingAs($this->admin)->post('/subscriptions', [
        'customer_id' => $this->leo->id, 'plan_id' => $plan->id, 'store_id' => $this->store->id,
        'starts_on' => '2026-10-07', 'ends_on' => '2026-12-31', 'options' => [0],
    ]);

    $subscription = Subscription::firstOrFail();
    expect($subscription->schedule->installments->pluck('amount')->all())->toBe([10000, 17000, 28000, 28000, 28000]);

    $this->actingAs($this->admin)->post("/subscriptions/{$subscription->id}/cancel")->assertSessionHas('success');
    expect($subscription->fresh()->status)->toBe('cancelled')
        ->and($subscription->schedule->fresh()->status)->toBe('cancelled');
});

it('lists subscriptions ending soon', function () {
    $plan = Plan::create(['organization_id' => $this->organization->id, 'name' => 'Natation carte 10', 'price' => 25000, 'period' => 'sessions', 'sessions_count' => 10]);
    $this->actingAs($this->admin)->post('/subscriptions', ['customer_id' => $this->parent->id, 'plan_id' => $plan->id, 'store_id' => $this->store->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-20']);

    expect(Subscription::first()->sessions_left)->toBe(10);
    $this->actingAs($this->admin)->get('/plans?show=ending')->assertInertia(fn (Assert $page) => $page->has('subscriptions.data', 1)->where('counts.ending', 1));
});

it('fills the agenda with the planned sessions of a subscription, coached or not', function () {
    $this->organization->setModules(['abonnements', 'tranches', 'beneficiaires', 'seances_groupe', 'rdv']);
    $this->organization->save();
    $this->admin->unsetRelation('organization');
    $coach = StaffMember::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Coach Rodrigue', 'active' => true]);
    $plan = judoPlan($this);

    // Mardi 18:00 et jeudi 07:30, suivi par le coach, 90 minutes.
    $this->actingAs($this->admin)->post('/subscriptions', [
        'customer_id' => $this->leo->id, 'plan_id' => $plan->id, 'store_id' => $this->store->id, 'starts_on' => '2026-10-07',
        'planning' => [['weekday' => 2, 'time' => '18:00'], ['weekday' => 4, 'time' => '07:30']],
        'session_minutes' => 90, 'coached' => true, 'staff_member_id' => $coach->id,
    ])->assertSessionHas('success');

    $subscription = Subscription::firstOrFail();
    $sessions = Appointment::where('subscription_id', $subscription->id)->orderBy('starts_at')->get();
    expect($sessions)->toHaveCount(16)
        ->and($sessions[0]->starts_at->setTimezone('Africa/Libreville')->format('D H:i'))->toBe('Thu 07:30')
        ->and($sessions[0]->customer_id)->toBe($this->leo->id)
        ->and($sessions[0]->staff_member_id)->toBe($coach->id)
        ->and($sessions[0]->duration_minutes)->toBe(90)
        ->and($sessions[0]->service)->toContain('séance suivie');

    // Rejouer la nuit ne crée pas de doublon ; une semaine plus tard, l'horizon avance.
    $this->artisan('crm:subscription-planning');
    expect(Appointment::where('subscription_id', $subscription->id)->count())->toBe(16);
    $this->travel(7)->days();
    $this->artisan('crm:subscription-planning');
    expect(Appointment::where('subscription_id', $subscription->id)->count())->toBe(18);

    // Nouveau planning en autonomie : les séances à venir sont remplacées.
    $this->actingAs($this->admin)->put("/subscriptions/{$subscription->id}/planning", ['planning' => [['weekday' => 6, 'time' => '09:00']], 'session_minutes' => 60, 'coached' => false])
        ->assertSessionHas('success');
    $future = Appointment::where('subscription_id', $subscription->id)->where('starts_at', '>=', now())->get();
    expect($future)->toHaveCount(8)->and($future->every(fn ($a) => $a->staff_member_id === null && str_contains($a->service, 'autonomie')))->toBeTrue();

    $this->actingAs($this->admin)->get("/customers/{$this->leo->id}")->assertInertia(fn (Assert $page) => $page
        ->where('subscriptionModule.subscriptions.0.planning_label', 'sam. 09h00')
        ->where('subscriptionModule.hasAgenda', true));

    // Abonnement arrêté : ses séances à venir quittent l'agenda.
    $this->actingAs($this->admin)->post("/subscriptions/{$subscription->id}/cancel");
    expect(Appointment::where('subscription_id', $subscription->id)->where('starts_at', '>=', now())->count())->toBe(0);
});
