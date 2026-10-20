<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Lundi 19 octobre 2026, 8 h.
    $this->travelTo(Carbon::parse('2026-10-19 08:00'));
    $this->organization = Organization::factory()->create([
        'name' => 'Salon Awa',
        'whatsapp_api_enabled' => false,
        'booking_settings' => ['enabled' => true, 'slot_step' => 30, 'min_notice_minutes' => 120, 'horizon_days' => 14],
    ]);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $brand->id,
        'name' => 'Salon Louis',
        'booking_capacity' => 1,
        'booking_phone' => '24177000111',
        'booking_hours' => [1 => ['open' => '09:00', 'close' => '12:00'], 2 => ['open' => '09:00', 'close' => '12:00'], 3 => null, 4 => null, 5 => null, 6 => null, 7 => null],
    ]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
    $this->coupe = CatalogItem::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Coupe', 'price' => 8000, 'duration_minutes' => 60]);
    $this->hidden = CatalogItem::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Secret', 'bookable_online' => false]);
});

function bookingPayload(array $overrides = []): array
{
    return [
        'store' => test()->store->id,
        'items' => [test()->coupe->id],
        'date' => '2026-10-20',
        'time' => '10:00',
        'name' => 'Estelle Moussavou',
        'phone' => '077 12 34 56',
        ...$overrides,
    ];
}

it('shows the public page only when online booking is open', function () {
    $this->get("/rdv/{$this->organization->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Booking/Show')
            ->where('services', fn ($services) => collect($services)->pluck('name')->all() === ['Coupe']));

    $this->organization->update(['booking_settings' => ['enabled' => false]]);
    $this->get("/rdv/{$this->organization->slug}")->assertNotFound();
});

it('offers free slots inside opening hours, after the minimum notice', function () {
    // Aujourd'hui : 9 h – 12 h, délai de 2 h à partir de 8 h → 10 h et 10 h 30 ; un rendez-vous d'1 h doit finir avant midi.
    $this->getJson("/rdv/{$this->organization->slug}/creneaux?store={$this->store->id}&items[]={$this->coupe->id}&date=2026-10-19")
        ->assertOk()
        ->assertJson(['slots' => ['10:00', '10:30', '11:00'], 'duration' => 60])
        ->assertJsonPath('days', ['2026-10-19', '2026-10-20', '2026-10-26', '2026-10-27', '2026-11-02']);

    Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => Customer::factory()->create(['organization_id' => $this->organization->id])->id, 'starts_at' => '2026-10-20 10:00', 'duration_minutes' => 60]);

    $this->getJson("/rdv/{$this->organization->slug}/creneaux?store={$this->store->id}&items[]={$this->coupe->id}&date=2026-10-20")
        ->assertJson(['slots' => ['09:00', '11:00']]);
});

it('books online: client created, appointment to confirm, consent recorded', function () {
    $response = $this->post("/rdv/{$this->organization->slug}", bookingPayload(['consent' => true]));

    $appointment = Appointment::with('items')->sole();
    $response->assertRedirect(route('booking.confirmation', [$this->organization->slug, $appointment->cancel_token]));

    $contact = Contact::where('phone_e164', '24177123456')->sole();
    expect($appointment->status)->toBe('requested')
        ->and($appointment->source)->toBe('online')
        ->and($appointment->service)->toBe('Coupe')
        ->and($appointment->duration_minutes)->toBe(60)
        ->and($appointment->starts_at->format('Y-m-d H:i'))->toBe('2026-10-20 10:00')
        ->and($appointment->customer_id)->toBe($contact->customer_id)
        ->and($contact->consent_status)->toBe('opt_in')
        ->and($contact->consent_source)->toBe('reservation_en_ligne');

    $this->get(route('booking.confirmation', [$this->organization->slug, $appointment->cancel_token]))
        ->assertInertia(fn ($page) => $page
            ->component('Booking/Confirmation')
            ->where('appointment.status', 'requested')
            ->where('whatsappUrl', fn ($url) => str_starts_with($url, 'https://wa.me/24177000111?text=')));

    $items = collect(app(NotificationService::class)->forUser($this->admin)['items'])->keyBy('key');
    expect($items['booking_requests']['count'])->toBe(1);
});

it('gives the last free slot to the first request only', function () {
    $this->post("/rdv/{$this->organization->slug}", bookingPayload())->assertSessionHasNoErrors();
    $this->post("/rdv/{$this->organization->slug}", bookingPayload(['phone' => '066 21 03 47', 'name' => 'Brice']))
        ->assertSessionHasErrors(['time' => 'Ce créneau vient d’être pris. Choisissez un autre horaire.']);

    expect(Appointment::count())->toBe(1);

    // Avec deux postes, la seconde demande passe aussi.
    $this->store->update(['booking_capacity' => 2]);
    $this->post("/rdv/{$this->organization->slug}", bookingPayload(['phone' => '066 21 03 47', 'name' => 'Brice']))->assertSessionHasNoErrors();
    expect(Appointment::count())->toBe(2);
});

it('refuses closed days, hidden services, robots and too many pending requests', function () {
    $slug = $this->organization->slug;

    $this->post("/rdv/{$slug}", bookingPayload(['date' => '2026-10-21']))->assertSessionHasErrors('time');
    $this->post("/rdv/{$slug}", bookingPayload(['items' => [$this->hidden->id]]))->assertSessionHasErrors('items');
    $this->post("/rdv/{$slug}", bookingPayload(['website' => 'http://spam']))->assertSessionHasErrors('website');

    foreach (['09:00', '10:00', '11:00'] as $time) {
        $this->post("/rdv/{$slug}", bookingPayload(['date' => '2026-10-26', 'time' => $time]))->assertSessionHasNoErrors();
    }
    $this->post("/rdv/{$slug}", bookingPayload(['date' => '2026-10-27', 'time' => '09:00']))->assertSessionHasErrors('phone');
});

it('confirms directly when the company chose it, and lets the client cancel', function () {
    $this->organization->update(['booking_settings' => [...$this->organization->booking_settings, 'auto_confirm' => true]]);

    $this->post("/rdv/{$this->organization->slug}", bookingPayload());
    $appointment = Appointment::sole();
    expect($appointment->status)->toBe('confirmed');

    $this->post(route('booking.cancel', [$this->organization->slug, $appointment->cancel_token]))->assertRedirect();
    expect($appointment->fresh()->status)->toBe('cancelled');

    $this->get(route('booking.confirmation', [$this->organization->slug, 'mauvais-jeton']))->assertNotFound();
});

it('lets managers set opening hours, capacity and bookable services', function () {
    $this->actingAs($this->admin)->put('/settings/booking', [
        'enabled' => true, 'auto_confirm' => false, 'slot_step' => 15, 'min_notice_minutes' => 60, 'horizon_days' => 30,
        'welcome' => 'Bienvenue !', 'bookable_ids' => [$this->hidden->id],
    ])->assertSessionHasNoErrors();

    expect($this->organization->fresh()->bookingSettings()['slot_step'])->toBe(15)
        ->and($this->coupe->fresh()->bookable_online)->toBeFalse()
        ->and($this->hidden->fresh()->bookable_online)->toBeTrue();

    $this->actingAs($this->admin)->put("/settings/booking/stores/{$this->store->id}", [
        'hours' => [1 => ['open' => '08:00', 'close' => '17:00'], 7 => ['open' => '10:00', 'close' => '09:00']],
        'capacity' => 3,
    ])->assertSessionHasErrors('hours.7');

    $this->actingAs($this->admin)->put("/settings/booking/stores/{$this->store->id}", [
        'hours' => [1 => ['open' => '08:00', 'close' => '17:00'], 6 => ['open' => '10:00', 'close' => '14:00']],
        'capacity' => 3,
        'phone' => '077 00 01 11',
    ])->assertSessionHasNoErrors();

    $store = $this->store->fresh();
    expect($store->bookingHours()[1])->toBe(['open' => '08:00', 'close' => '17:00'])
        ->and($store->bookingHours()[2])->toBeNull()
        ->and($store->booking_capacity)->toBe(3)
        ->and($store->booking_phone)->toBe('24177000111');

    $this->actingAs($this->admin)->get('/settings/booking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Booking')->where('url', route('booking.show', $this->organization->slug)));
});

it('does not let slot searches use up the booking quota', function () {
    foreach (range(1, 15) as $i) {
        $this->getJson("/rdv/{$this->organization->slug}/creneaux?store={$this->store->id}&items[]={$this->coupe->id}&date=2026-10-20")->assertOk();
    }

    $this->post("/rdv/{$this->organization->slug}", bookingPayload())->assertSessionHasNoErrors();
});
