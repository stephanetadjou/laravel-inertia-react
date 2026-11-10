<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'beaute', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['rdv']);
    $this->organization->save();
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->customer = Customer::factory()->create(['organization_id' => $this->organization->id]);
});

it('shows the agenda cards: month totals, upcoming and cashed-in revenue with a 6-month series', function () {
    $make = fn (array $attrs) => Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => $this->customer->id, ...$attrs]);

    // Octobre : 2 rendez-vous dont 1 terminé et encaissé.
    $purchase = Purchase::factory()->create(['store_id' => $this->store->id, 'amount' => 15000, 'purchased_at' => now()->setDate(2026, 10, 5)]);
    $make(['starts_at' => now()->setDate(2026, 10, 5)->setTime(10, 0), 'status' => 'done', 'purchase_id' => $purchase->id]);
    $make(['starts_at' => now()->setDate(2026, 10, 12)->setTime(10, 0), 'status' => 'no_show']);
    // Novembre : 1 terminé, 2 à venir (dont 1 à confirmer), 1 annulé (ignoré).
    $make(['starts_at' => now()->setDate(2026, 11, 3)->setTime(10, 0), 'status' => 'done']);
    $make(['starts_at' => now()->setDate(2026, 11, 12)->setTime(10, 0), 'status' => 'planned']);
    $make(['starts_at' => now()->setDate(2026, 11, 13)->setTime(10, 0), 'status' => 'requested']);
    $make(['starts_at' => now()->setDate(2026, 11, 14)->setTime(10, 0), 'status' => 'cancelled']);

    $this->actingAs($this->admin)->get('/appointments')->assertInertia(fn (Assert $page) => $page
        ->where('stats.months', ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
        ->where('stats.total', [0, 0, 0, 0, 2, 3])
        ->where('stats.done', [0, 0, 0, 0, 1, 1])
        ->where('stats.revenue', [0, 0, 0, 0, 15000, 0])
        ->where('stats.upcoming', 2)
        ->where('stats.toConfirm', 1)
        ->where('stats.upcomingWeeks.0', 2)
        ->where('bookingUrl', $this->organization->bookingUrl())
        ->where('reminders.available', false));
});

it('turns the automatic reminders on and off from the agenda', function () {
    $this->organization->update(['whatsapp_api_enabled' => true]);

    $this->actingAs($this->admin)->put('/appointments/settings/reminders', ['enabled' => true])->assertRedirect();
    expect($this->organization->fresh()->hasModule('rappels_rdv'))->toBeTrue()
        ->and($this->organization->fresh()->hasModule('rdv'))->toBeTrue();

    $this->actingAs($this->admin)->put('/appointments/settings/reminders', ['enabled' => false])->assertRedirect();
    expect($this->organization->fresh()->hasModule('rappels_rdv'))->toBeFalse();
});

it('refuses the reminders switch to a user who cannot manage the organization', function () {
    $user = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Responsable de marque')->id]);

    $this->actingAs($user)->put('/appointments/settings/reminders', ['enabled' => true])->assertForbidden();
    expect($this->organization->fresh()->hasModule('rappels_rdv'))->toBeFalse();
});
