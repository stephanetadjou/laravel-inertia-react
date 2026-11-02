<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\DailyReport;
use App\Services\SalesTeam;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'pro', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['rdv', 'catalogue', 'prospects', 'abonnements', 'tranches']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->director = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id, 'name' => 'Direction']);
    $role = Role::ensureSalesRole($this->organization);
    $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role->id, 'name' => 'Alice', 'email' => 'alice@test.test']);
    $this->bruno = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role->id, 'name' => 'Bruno']);
    $this->plan = Plan::create(['organization_id' => $this->organization->id, 'name' => 'Équipe', 'price' => 25000, 'period' => 'month']);

    $this->actingAs($this->alice)->post('/prospects', ['name' => 'Institut Awa', 'phone' => '077 11 22 33', 'store_id' => $this->store->id]);
    $this->awa = Customer::where('name', 'Institut Awa')->sole();
});

function salesVisit($test, User $by, Customer $customer, string $kind, string $when): Appointment
{
    $a = Appointment::create(['store_id' => $test->store->id, 'customer_id' => $customer->id, 'starts_at' => $when, 'duration_minutes' => 30, 'status' => 'planned', 'kind' => $kind, 'created_by' => $by->id]);
    $a->update(['status' => 'done']);

    return $a;
}

it('records visits and demos with an optional GPS position', function () {
    $this->actingAs($this->alice)->post('/appointments', [
        'store_id' => $this->store->id, 'customer_id' => $this->awa->id, 'starts_at' => '2026-11-10 11:00',
        'kind' => 'visite', 'latitude' => 0.4162, 'longitude' => 9.4673,
    ])->assertSessionHasNoErrors();

    $visit = Appointment::where('kind', 'visite')->sole();
    expect((float) $visit->latitude)->toBe(0.4162);
    $visit->update(['status' => 'done']);
    expect($this->awa->fresh()->prospect_stage)->toBe('visite');
});

it('shows targets, results to date, conversion and ranking per sales rep', function () {
    salesVisit($this, $this->alice, $this->awa, 'visite', '2026-11-03 10:00');
    salesVisit($this, $this->alice, $this->awa, 'demo', '2026-11-05 10:00');
    $this->actingAs($this->alice)->post("/prospects/{$this->awa->id}/stage", ['stage' => 'essai']);

    $this->actingAs($this->director)->put("/sales-team/{$this->alice->id}/targets", ['month' => '2026-11', 'visits' => 30, 'demos' => 60, 'trials' => 24, 'customers' => 7, 'revenue' => 500000])->assertSessionHas('success');
    $this->actingAs($this->director)->put("/sales-team/{$this->bruno->id}/targets", ['month' => '2026-11', 'visits' => 30, 'demos' => 60, 'trials' => 24, 'customers' => 7, 'revenue' => 500000]);

    $this->actingAs($this->director)->get('/sales-team?month=2026-11')->assertInertia(fn (Assert $page) => $page
        ->component('SalesTeam/Index')
        ->has('board', 2)
        ->where('board.0.name', 'Alice')
        ->where('board.0.indicators.1.actual', 1)
        ->where('board.0.indicators.1.to_date', 20)
        ->where('board.0.conversion.visit_demo', 100)
        ->where('board.0.conversion.demo_trial', 100)
        ->where('board.0.behind', fn ($b) => collect($b)->contains('Démos')));

    // Un commercial ne voit que sa ligne.
    $this->actingAs($this->bruno)->get('/sales-team?month=2026-11')->assertInertia(fn (Assert $page) => $page->has('board', 1)->where('board.0.name', 'Bruno')->where('canEdit', false));
    $this->actingAs($this->bruno)->put("/sales-team/{$this->bruno->id}/targets", ['month' => '2026-11', 'visits' => 1, 'demos' => 1, 'trials' => 1, 'customers' => 1, 'revenue' => 1])->assertForbidden();
});

it('owes the commission at the second payment of the customer brought in, with a monthly payroll export', function () {
    $this->actingAs($this->director)->post('/subscriptions', ['customer_id' => $this->awa->id, 'plan_id' => $this->plan->id, 'store_id' => $this->store->id, 'starts_on' => '2026-10-05', 'ends_on' => '2027-03-31']);
    $installments = Installment::orderBy('due_on')->get();
    $this->actingAs($this->director)->post("/installments/{$installments[0]->id}/pay", ['paid_on' => '2026-10-05']);

    expect((new SalesTeam($this->organization, '2026-11'))->commissions())->toBeEmpty();

    $this->actingAs($this->director)->post("/installments/{$installments[1]->id}/pay", ['payment_method' => 'cash']);
    $rows = (new SalesTeam($this->organization->fresh(), '2026-11'))->commissions();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['user' => 'Alice', 'plan' => 'Équipe', 'amount' => 12500]);
    expect($this->awa->fresh()->prospect_stage)->toBe('client');

    $this->actingAs($this->director)->get('/commissions?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('totals.0.amount', 12500));
    $this->actingAs($this->director)->get('/commissions/export?month=2026-11')->assertOk();
    $this->actingAs($this->bruno)->get('/commissions?month=2026-11')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
});

it('reports each sales rep in the evening and reminds them in the morning', function () {
    $this->actingAs($this->director)->put("/sales-team/{$this->alice->id}/targets", ['month' => '2026-11', 'visits' => 30, 'demos' => 60, 'trials' => 24, 'customers' => 7, 'revenue' => 0]);
    salesVisit($this, $this->alice, $this->awa, 'demo', '2026-11-10 08:00');

    $report = (new DailyReport($this->organization->fresh(), today()))->build();
    expect($report['sales_team'][0])->toMatchArray(['name' => 'Alice', 'demos' => 1])
        ->and($report['sales_team'][0]['behind'])->toContain('Démos');

    Mail::fake();
    $this->travelTo(now()->addDays(5));
    $this->artisan('crm:sales-morning')->expectsOutput('1 rappel(s) envoyé(s).')->assertSuccessful();
});
