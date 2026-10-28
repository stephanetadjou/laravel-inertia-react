<?php

use App\Http\Controllers\ScheduleSettingsController;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Closure;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\BookingAvailability;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Lundi 2 novembre 2026, 7 h.
    $this->travelTo(Carbon::parse('2026-11-02 07:00'));
    $this->organization = Organization::factory()->create([
        'whatsapp_api_enabled' => false,
        'booking_settings' => ['enabled' => true, 'slot_step' => 60, 'min_notice_minutes' => 0, 'horizon_days' => 14],
    ]);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $brand->id,
        'booking_hours' => [1 => ['open' => '09:00', 'close' => '17:00'], 2 => ['open' => '09:00', 'close' => '17:00'], 3 => ['open' => '09:00', 'close' => '17:00'], 4 => null, 5 => null, 6 => null, 7 => null],
        'booking_capacity' => 1,
    ]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
    $this->coupe = CatalogItem::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Coupe', 'duration_minutes' => 60]);
    $this->tresses = CatalogItem::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Tresses', 'duration_minutes' => 60]);
    $this->settings = $this->organization->bookingSettings();
    $this->customer = Customer::factory()->create(['organization_id' => $this->organization->id]);
});

function availability(): BookingAvailability
{
    return new BookingAvailability(test()->store->fresh(), test()->settings);
}

it('skips the daily break', function () {
    $this->store->update(['booking_break' => ['start' => '12:00', 'end' => '14:00']]);

    expect(availability()->slots(Carbon::parse('2026-11-03'), 60))->toBe(['09:00', '10:00', '11:00', '14:00', '15:00', '16:00']);
});

it('offers nothing on holidays and leave, for one store or the whole company', function () {
    Closure::create(['organization_id' => $this->organization->id, 'store_id' => null, 'label' => 'Toussaint', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02']);
    Closure::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'label' => 'Congés', 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-11']);

    expect(availability()->slots(Carbon::parse('2026-11-02'), 60))->toBe([])
        ->and(availability()->slots(Carbon::parse('2026-11-10'), 60))->toBe([])
        ->and(availability()->openDays(60))->toBe(['2026-11-03', '2026-11-04', '2026-11-16']);

    $this->actingAs($this->admin)->get('/appointments?view=month&date=2026-11-10')
        ->assertInertia(fn ($page) => $page->where('closures', fn ($c) => collect($c)->pluck('label')->sort()->values()->all() === ['Congés · '.$this->store->name, 'Toussaint']));
});

it('books per staff member: hours, services, busy slots and least busy assignment', function () {
    $grace = StaffMember::factory()->create(['store_id' => $this->store->id, 'name' => 'Grâce']);
    $mireille = StaffMember::factory()->create(['store_id' => $this->store->id, 'name' => 'Mireille', 'hours' => [2 => ['open' => '13:00', 'close' => '17:00']]]);
    $mireille->services()->sync([$this->tresses->id]);
    $day = Carbon::parse('2026-11-03');

    // Coupe : seule Grâce la réalise.
    expect(availability()->staffFor([$this->coupe->id])->pluck('name')->all())->toBe(['Grâce'])
        ->and(availability()->staffFor([$this->tresses->id])->pluck('name')->all())->toBe(['Grâce', 'Mireille']);

    // Grâce prise à 10 h : la coupe n'est plus possible à 10 h, les tresses non plus (Mireille ne commence qu'à 13 h).
    Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'staff_member_id' => $grace->id, 'starts_at' => '2026-11-03 10:00', 'duration_minutes' => 60]);
    expect(availability()->slots($day, 60, [$this->coupe->id]))->not->toContain('10:00')
        ->and(availability()->slots($day, 60, [$this->tresses->id]))->not->toContain('10:00')
        ->and(availability()->slots($day, 60, [$this->tresses->id], $mireille->id))->toBe(['13:00', '14:00', '15:00', '16:00']);

    // À 14 h, les deux sont libres : Mireille (aucun rendez-vous ce jour) est attribuée.
    expect(availability()->assign(Carbon::parse('2026-11-03 14:00'), 60, [$this->tresses->id], null)->id)->toBe($mireille->id);

    // Un rendez-vous sans intervenant occupe une place.
    Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'starts_at' => '2026-11-03 15:00', 'duration_minutes' => 60]);
    expect(availability()->slots($day, 60, [$this->tresses->id]))->toContain('15:00')
        ->and(availability()->slots($day, 60, [$this->coupe->id]))->not->toContain('15:00');
});

it('lets the client choose a staff member on the booking page', function () {
    $grace = StaffMember::factory()->create(['store_id' => $this->store->id, 'name' => 'Grâce']);
    StaffMember::factory()->create(['store_id' => $this->store->id, 'name' => 'Ruth']);
    StaffMember::factory()->create(['store_id' => $this->store->id, 'name' => 'Cachée', 'bookable_online' => false]);
    $slug = $this->organization->slug;

    $this->getJson("/rdv/{$slug}/creneaux?store={$this->store->id}&items[]={$this->coupe->id}&date=2026-11-03")
        ->assertJsonPath('staff.*.name', ['Grâce', 'Ruth']);

    $this->post("/rdv/{$slug}", [
        'store' => $this->store->id, 'items' => [$this->coupe->id], 'date' => '2026-11-03', 'time' => '10:00',
        'name' => 'Estelle', 'phone' => '077 12 34 56', 'staff' => $grace->id,
    ])->assertSessionHasNoErrors();

    expect(Appointment::sole()->staff_member_id)->toBe($grace->id);

    // Grâce n'est plus libre à 10 h ; Ruth l'est encore.
    $this->getJson("/rdv/{$slug}/creneaux?store={$this->store->id}&items[]={$this->coupe->id}&date=2026-11-03&staff={$grace->id}")
        ->assertJson(fn ($json) => $json->where('slots', fn ($s) => ! collect($s)->contains('10:00'))->etc());
    $this->getJson("/rdv/{$slug}/creneaux?store={$this->store->id}&items[]={$this->coupe->id}&date=2026-11-03")
        ->assertJson(fn ($json) => $json->where('slots', fn ($s) => collect($s)->contains('10:00'))->etc());
});

it('manages staff and closures from the settings', function () {
    $this->actingAs($this->admin)->post('/settings/staff', [
        'name' => 'Sandrine', 'store_id' => $this->store->id, 'color' => '#00C896', 'own_hours' => true,
        'hours' => [1 => ['open' => '08:00', 'close' => '12:00']], 'service_ids' => [$this->coupe->id], 'bookable_online' => true,
    ])->assertSessionHasNoErrors();

    $sandrine = StaffMember::sole();
    expect($sandrine->workingHours()[1])->toBe(['open' => '08:00', 'close' => '12:00'])
        ->and($sandrine->workingHours()[2])->toBeNull()
        ->and($sandrine->services->pluck('id')->all())->toBe([$this->coupe->id]);

    $this->actingAs($this->admin)->post("/settings/staff/{$sandrine->id}/toggle");
    expect($sandrine->fresh()->active)->toBeFalse();

    $this->actingAs($this->admin)->post('/settings/closures', ['label' => 'Inventaire', 'starts_on' => '2026-12-28', 'ends_on' => '2026-12-26'])
        ->assertSessionHasErrors('ends_on');
    $this->actingAs($this->admin)->post('/settings/closures', ['label' => 'Inventaire', 'starts_on' => '2026-12-28'])->assertSessionHasNoErrors();
    expect(Closure::sole()->ends_on->toDateString())->toBe('2026-12-28');

    $this->actingAs($this->admin)->post('/settings/closures/holidays', ['year' => 2027])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/settings/closures/holidays', ['year' => 2027]);
    expect(Closure::whereYear('starts_on', 2027)->count())->toBe(10)
        ->and(Closure::where('label', 'Lundi de Pâques')->sole()->starts_on->toDateString())->toBe('2027-03-29');

    $this->actingAs($this->admin)->get('/settings/staff')->assertOk()->assertInertia(fn ($page) => $page->component('Settings/Staff'));
    $this->actingAs($this->admin)->get('/settings/closures')->assertOk()->assertInertia(fn ($page) => $page->component('Settings/Closures'));
});

it('computes Easter and keeps settings to the own company', function () {
    expect(ScheduleSettingsController::easter(2026)->toDateString())->toBe('2026-04-05')
        ->and(ScheduleSettingsController::easter(2027)->toDateString())->toBe('2027-03-28');

    $other = StaffMember::factory()->create();
    $this->actingAs($this->admin)->post("/settings/staff/{$other->id}/toggle")->assertForbidden();

    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->store->id,
    ]);
    $this->actingAs($manager)->get('/settings/staff')->assertForbidden();
});
