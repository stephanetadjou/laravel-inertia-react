<?php

use App\Models\Appointment;
use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Closure;
use App\Models\Contact;
use App\Models\GroupClass;
use App\Models\Organization;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\DailyReport;
use App\Services\Occupation;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Mardi 10 novembre 2026.
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(8, 0));
    $this->organization = Organization::factory()->create(['sector' => 'beaute', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['rdv', 'seances_groupe', 'ressources']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    // Du lundi au samedi, 9 h - 18 h, pause 13 h - 14 h : 8 h de travail par jour.
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'booking_break' => ['start' => '13:00', 'end' => '14:00']]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->mireille = StaffMember::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Mireille', 'active' => true]);
    $this->customer = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Awa'])->customer;
});

function booking($test, string $at, int $minutes, array $extra = []): Appointment
{
    return Appointment::create(['store_id' => $test->store->id, 'customer_id' => $test->customer->id, 'staff_member_id' => $test->mireille->id, 'starts_at' => Carbon::parse($at), 'duration_minutes' => $minutes, 'service' => 'Tresses', 'status' => 'confirmed', ...$extra]);
}

it('measures booked hours against working hours, break and closures excluded', function () {
    booking($this, '2026-11-10 09:00', 240);       // 4 h sur 8
    booking($this, '2026-11-10 12:00', 120);       // 1 h avant la pause, 1 h pendant : 1 h comptée
    booking($this, '2026-11-11 10:00', 60, ['status' => 'cancelled']);
    Closure::create(['organization_id' => $this->organization->id, 'store_id' => null, 'starts_on' => '2026-11-12', 'ends_on' => '2026-11-12', 'label' => 'Férié']);

    $week = (new Occupation($this->organization))->period(Carbon::parse('2026-11-10'), Carbon::parse('2026-11-13'));

    // Mardi 480 min + mercredi 480 min (jeudi fermé) ; 300 min réservées.
    expect($week['staff'][0]['capacity'])->toBe(960)
        ->and($week['staff'][0]['booked'])->toBe(300)
        ->and($week['rate'])->toBe(31)
        ->and($week['heatmap']['rows'])->toHaveCount(2)
        ->and($week['heatmap']['rows'][0]['cells'][0])->toBe(100)   // mardi 9 h
        ->and($week['heatmap']['rows'][0]['cells'][4])->toBeNull();  // mardi 13 h : pause
});

it('flags gaps, saturated days, under-filled classes and idle resources', function () {
    booking($this, '2026-11-10 09:00', 240);
    booking($this, '2026-11-10 14:00', 240);   // mardi plein
    booking($this, '2026-11-11 09:00', 60);    // mercredi presque vide

    $class = GroupClass::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Yoga', 'weekday' => 4, 'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => 10, 'starts_on' => '2026-11-01', 'active' => true]);
    ClassSession::create(['group_class_id' => $class->id, 'organization_id' => $this->organization->id, 'session_date' => '2026-11-12', 'start_time' => '18:00', 'end_time' => '19:00', 'status' => ClassSession::STATUS_SCHEDULED]);
    ClassEnrollment::create(['group_class_id' => $class->id, 'customer_id' => $this->customer->id, 'enrolled_on' => '2026-11-01']);

    $car = BookableResource::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Hilux', 'kind' => 'vehicule', 'active' => true]);
    booking($this, '2026-11-01 09:00', 60, ['resource_id' => $car->id, 'staff_member_id' => null]);

    $signals = collect((new Occupation($this->organization))->signals());

    expect($signals->firstWhere('type', 'saturation'))->toMatchArray(['kind' => 'staff', 'date' => '2026-11-10', 'rate' => 100])
        ->and($signals->where('type', 'creux')->where('kind', 'staff')->pluck('date')->all())->toContain('2026-11-11')
        ->and($signals->firstWhere('kind', 'class'))->toMatchArray(['type' => 'creux', 'rate' => 10])
        ->and($signals->firstWhere('kind', 'resource'))->toMatchArray(['type' => 'immobilisation', 'detail' => 'Aucune réservation depuis 9 jours']);
});

it('uses the store capacity when there is no staff member', function () {
    $this->mireille->delete();
    $this->store->update(['booking_capacity' => 2]);
    Appointment::create(['store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'starts_at' => Carbon::parse('2026-11-10 09:00'), 'duration_minutes' => 480, 'service' => 'Soin', 'status' => 'planned']);

    $day = (new Occupation($this->organization))->period(Carbon::parse('2026-11-10'), Carbon::parse('2026-11-11'));

    expect($day['rate'])->toBe(44); // 420 min dans les plages / 960 min (2 postes × 8 h)
});

it('saves the thresholds and shows the week on the dashboard and in the evening report', function () {
    booking($this, '2026-11-11 09:00', 60);

    $this->actingAs($this->admin)->put('/settings/occupation', ['low' => 40, 'high' => 95, 'window_days' => 3, 'class_low' => 50, 'class_window_days' => 5, 'idle_days' => 10])
        ->assertSessionHasNoErrors();
    expect($this->organization->fresh()->occupationSettings())->toMatchArray(['low' => 40, 'high' => 95, 'idle_days' => 10]);

    $this->actingAs($this->admin)->put('/settings/occupation', ['low' => 90, 'high' => 80, 'window_days' => 3, 'class_low' => 50, 'class_window_days' => 5, 'idle_days' => 10])
        ->assertSessionHasErrors('high');

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('occupation.rate', fn ($rate) => $rate !== null)
        ->has('occupation.heatmap.rows')
        ->where('occupation.signals.0.type', 'creux'));

    $report = (new DailyReport($this->organization, Carbon::parse('2026-11-10')))->build();
    expect($report['occupation']['creux'])->toBeGreaterThan(0);
});

it('stays hidden for a business without agenda, classes or resources', function () {
    $this->organization->setModules([]);
    $this->organization->save();

    expect(Occupation::applies($this->organization))->toBeFalse();
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('occupation', null));
    $this->actingAs($this->admin)->get('/settings/occupation')->assertRedirect();
});
