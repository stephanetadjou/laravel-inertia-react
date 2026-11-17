<?php

use App\Models\Appointment;
use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\PaymentSchedule;
use App\Models\Purchase;
use App\Models\Rental;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\Fleet;
use App\Services\Occupation;
use App\Support\SectorPresets;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Mardi 10 novembre 2026, 9 h.
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'vehicules', 'whatsapp_api_enabled' => false]);
    SectorPresets::apply($this->organization, true);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->customer = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'SARL Ogooué Mines', 'consent_status' => 'opt_in'])->customer;
    $this->hilux = vehicle($this, 'Toyota Hilux', '4x4', ['price_day' => 45000, 'price_month' => 900000]);
    $this->yaris = vehicle($this, 'Toyota Yaris', 'citadine', ['price_day' => 20000]);
});

function vehicle($test, string $name, string $category, array $extra = []): BookableResource
{
    return BookableResource::create(['organization_id' => $test->organization->id, 'store_id' => $test->store->id, 'name' => $name, 'kind' => 'vehicule', 'category' => $category, 'active' => true, 'status' => 'available', 'mileage' => 50000, ...$extra]);
}

function rent($test, BookableResource $vehicle, string $from, string $to, array $extra = [])
{
    return $test->actingAs($test->admin)->post('/rentals', [
        'resource_id' => $vehicle->id, 'customer_id' => $test->customer->id, 'store_id' => $test->store->id,
        'kind' => 'short', 'starts_at' => $from, 'ends_at' => $to, 'rate' => $vehicle->price_day, 'rate_period' => 'day', ...$extra,
    ]);
}

it('sets up the vehicle sector with its modules and fleet page', function () {
    expect($this->organization->hasModule('vehicules'))->toBeTrue()
        ->and($this->organization->hasModule('tranches'))->toBeTrue()
        ->and(Organization::SECTORS)->toHaveKey('vehicules');

    $this->actingAs($this->admin)->get('/vehicles')->assertInertia(fn (Assert $page) => $page
        ->component('Vehicles/Index')
        ->has('planning.rows', 2)
        ->has('planning.days', 14)
        ->where('summary.free_tomorrow', 2));
});

it('rents a vehicle, refuses overlaps, and bills late returns on give-back', function () {
    rent($this, $this->hilux, '2026-11-10 08:00', '2026-11-12 08:00')->assertSessionHasNoErrors();
    $rental = Rental::firstOrFail();
    expect($rental->amount)->toBe(90000);

    rent($this, $this->hilux, '2026-11-11 10:00', '2026-11-13 10:00')->assertSessionHasErrors('resource_id');

    $this->post("/rentals/{$rental->id}/depart", ['km' => 50100, 'fuel' => 100])->assertSessionHasNoErrors();
    expect($rental->fresh()->status)->toBe('ongoing');

    // Rendu le 13 au lieu du 12 : un jour de plus facturé.
    $this->travelTo(Carbon::parse('2026-11-13 08:00'));
    $this->post("/rentals/{$rental->id}/return", ['km' => 50900, 'fuel' => 60, 'payment_method' => 'cash'])->assertSessionHasNoErrors();

    $rental->refresh();
    expect($rental->status)->toBe('returned')
        ->and($rental->amount)->toBe(135000)
        ->and($this->hilux->fresh()->mileage)->toBe(50900)
        ->and(Purchase::find($rental->purchase_id)->amount)->toBe(135000);
});

it('creates monthly installments for a long rental and proposes the renewal', function () {
    rent($this, $this->hilux, '2026-09-01 08:00', '2026-12-01 08:00', ['kind' => 'long', 'rate' => 900000, 'rate_period' => 'month'])->assertSessionHasNoErrors();
    $rental = Rental::firstOrFail();

    expect($rental->amount)->toBe(2700000)
        ->and(PaymentSchedule::find($rental->payment_schedule_id)->installments()->count())->toBe(3);

    $rental->update(['status' => 'ongoing']);
    $rows = (new ActionLists($this->admin))->rows('rental_ending');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['message'])->toContain('Toyota Hilux')->toContain('1 décembre');
});

it('flags late returns, idle vehicles, the safety stock and old stock for sale', function () {
    // Le Hilux est sorti depuis le 5 et devait revenir le 8.
    Rental::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'resource_id' => $this->hilux->id, 'customer_id' => $this->customer->id,
        'kind' => 'short', 'status' => 'ongoing', 'starts_at' => '2026-11-05 08:00', 'ends_at' => '2026-11-08 08:00', 'rate' => 45000, 'amount' => 135000]);
    // La Yaris a été louée une fois, il y a 20 jours.
    $old = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177009988', 'name' => 'Paul Obame', 'consent_status' => 'opt_in'])->customer;
    Rental::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'resource_id' => $this->yaris->id, 'customer_id' => $old->id,
        'kind' => 'short', 'status' => 'returned', 'starts_at' => '2026-10-19 08:00', 'ends_at' => '2026-10-21 08:00', 'returned_at' => '2026-10-21 08:00', 'rate' => 20000, 'amount' => 40000]);
    $this->yaris->forceFill(['created_at' => '2026-09-01'])->save();
    vehicle($this, 'Peugeot 3008', 'suv', ['status' => 'for_sale', 'sale_price' => 9000000, 'for_sale_since' => '2026-08-01']);

    $late = (new ActionLists($this->admin))->rows('rental_late');
    expect($late)->toHaveCount(1)->and($late[0]['reason'])->toContain('2 j de retard');

    $idle = (new ActionLists($this->admin))->rows('idle_vehicles');
    expect($idle)->toHaveCount(1)->and($idle[0]['name'])->toBe('Paul Obame')->and($idle[0]['message'])->toContain('Toyota Yaris');

    // Hilux en retard + Yaris seule libre : sous le stock de sécurité (2).
    expect(Fleet::availableOn($this->organization->id, today()->addDay())->pluck('name')->all())->toBe(['Toyota Yaris']);
    $signals = collect((new Occupation($this->organization))->signals());
    expect($signals->firstWhere('kind', 'fleet'))->toMatchArray(['type' => 'saturation', 'detail' => '1 véhicule(s) libre(s) (stock de sécurité : 2)'])
        ->and($signals->firstWhere('kind', 'sale')['detail'])->toBe('À vendre depuis 101 jours')
        ->and($signals->where('kind', 'resource')->pluck('label')->all())->toContain('Toyota Yaris');
});

it('sells a vehicle for sale and records the sale', function () {
    $car = vehicle($this, 'Peugeot 3008', 'suv', ['status' => 'for_sale', 'sale_price' => 9000000, 'for_sale_since' => '2026-10-01']);

    $this->actingAs($this->admin)->post("/vehicles/{$car->id}/sell", ['customer_id' => $this->customer->id, 'store_id' => $this->store->id, 'price' => 8500000, 'payment_method' => 'transfer'])
        ->assertSessionHasNoErrors();

    expect($car->fresh()->status)->toBe('sold')
        ->and(Purchase::where('source', 'rental')->sum('amount'))->toBe(8500000);
});

it('suggests free slots and under-filled classes to the right customers', function () {
    $organization = Organization::factory()->create(['sector' => 'beaute', 'whatsapp_api_enabled' => false]);
    $organization->setModules(['rdv']);
    $organization->save();
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    StaffMember::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'name' => 'Mireille', 'active' => true]);
    $awa = Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177005566', 'name' => 'Awa Ndong', 'consent_status' => 'opt_in'])->customer;
    Appointment::create(['store_id' => $store->id, 'customer_id' => $awa->id, 'starts_at' => Carbon::parse('2026-10-01 10:00'), 'duration_minutes' => 60, 'service' => 'Tresses', 'status' => 'done']);

    $rows = (new ActionLists($admin))->rows('fill_slots');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Awa Ndong')
        ->and($rows[0]['message'])->toContain('demain avec Mireille');
});
