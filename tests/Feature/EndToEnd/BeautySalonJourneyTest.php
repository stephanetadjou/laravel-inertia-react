<?php

use App\Mail\DailyReportMail;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Closure;
use App\Models\Purchase;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\Loyalty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
 * Parcours complet d'un salon de beauté sur rendez-vous : inscription,
 * prestations, horaires, pause et congés, intervenante, réservation en ligne,
 * confirmation, rappel WhatsApp (lien wa.me), encaissement, fidélité,
 * rapport du soir.
 */

beforeEach(function () {
    // Lundi 19 octobre 2026, 8 h.
    $this->travelTo(Carbon::parse('2026-10-19 08:00'));
});

it('runs the beauty salon journey from sign-up to the evening report', function () {
    Mail::fake();

    // 1. Inscription, secteur beauté : agenda disponible.
    $this->post('/register', [
        'organization_name' => 'Salon Élégance',
        'sector' => 'beaute',
        'store_name' => 'Élégance Louis',
        'name' => 'Clarisse Mba',
        'email' => 'clarisse@elegance.test',
        'password' => 'Secret-123456',
        'password_confirmation' => 'Secret-123456',
        // Le salon vend aussi des produits en complément de ses prestations.
        'answers' => ['produits' => true],
    ])->assertRedirect(route('dashboard.index'));

    $admin = User::where('email', 'clarisse@elegance.test')->sole();
    $organization = $admin->organization;
    $store = Store::where('organization_id', $organization->id)->sole();
    expect($organization->hasAgenda())->toBeTrue();
    $this->actingAs($admin)->get('/appointments')->assertOk();

    // 2. Prestation et produit.
    $this->actingAs($admin)->post('/catalog', ['type' => 'service', 'name' => 'Coupe brushing', 'price' => 8000, 'duration_minutes' => 60])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/catalog', ['type' => 'product', 'name' => 'Shampoing', 'price' => 6000])->assertSessionHasNoErrors();
    $coupe = CatalogItem::where('organization_id', $organization->id)->where('name', 'Coupe brushing')->sole();
    $shampoing = CatalogItem::where('organization_id', $organization->id)->where('name', 'Shampoing')->sole();

    // 3. Horaires, pause de midi, congé du mercredi, intervenante.
    $week = [1 => ['open' => '09:00', 'close' => '18:00'], 2 => ['open' => '09:00', 'close' => '18:00'], 3 => ['open' => '09:00', 'close' => '18:00'], 4 => null, 5 => null, 6 => null, 7 => null];
    $this->actingAs($admin)->put("/settings/booking/stores/{$store->id}", [
        'hours' => $week, 'capacity' => 2, 'phone' => '077 00 01 11', 'break' => ['start' => '12:00', 'end' => '13:00'],
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/settings/closures', ['label' => 'Congé annuel', 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21'])->assertSessionHasNoErrors();
    expect(Closure::count())->toBe(1);

    $this->actingAs($admin)->post('/settings/staff', [
        'name' => 'Sandrine', 'store_id' => $store->id, 'color' => '#00C896', 'own_hours' => false,
        'service_ids' => [$coupe->id], 'bookable_online' => true,
    ])->assertSessionHasNoErrors();
    $sandrine = StaffMember::sole();

    $this->actingAs($admin)->put('/settings/booking', [
        'enabled' => true, 'auto_confirm' => false, 'slot_step' => 30, 'min_notice_minutes' => 60, 'horizon_days' => 30,
        'welcome' => 'Bienvenue au salon !', 'bookable_ids' => [$coupe->id],
    ])->assertSessionHasNoErrors();

    $this->actingAs($admin)->put('/settings/loyalty', [
        'enabled' => true, 'fcfa_per_point' => 1000, 'starts_on' => '2026-01-01',
        'tiers' => [['name' => 'Découverte', 'min_points' => 0], ['name' => 'Or', 'min_points' => 100]],
    ])->assertSessionHasNoErrors();

    // 4. Réservation en ligne, sans compte : pause et congé respectés.
    auth()->logout();
    $slug = $organization->slug;
    $this->get("/rdv/{$slug}")->assertOk()->assertInertia(fn ($page) => $page->component('Booking/Show'));

    $slots = $this->getJson("/rdv/{$slug}/creneaux?store={$store->id}&items[]={$coupe->id}&date=2026-10-20")->assertOk()->json('slots');
    expect($slots)->toContain('10:00', '13:00')->not->toContain('11:30', '12:00', '12:30');
    expect($this->getJson("/rdv/{$slug}/creneaux?store={$store->id}&items[]={$coupe->id}&date=2026-10-21")->json('slots'))->toBe([]);

    $this->post("/rdv/{$slug}", [
        'store' => $store->id, 'items' => [$coupe->id], 'staff' => $sandrine->id,
        'date' => '2026-10-20', 'time' => '10:00', 'name' => 'Estelle Moussavou', 'phone' => '077 12 34 56', 'consent' => true,
    ])->assertRedirect();

    $appointment = Appointment::sole();
    expect($appointment->status)->toBe('requested')
        ->and($appointment->staff_member_id)->toBe($sandrine->id)
        ->and($appointment->starts_at->format('Y-m-d H:i'))->toBe('2026-10-20 10:00');

    // 5. Le salon confirme, puis rappelle la cliente sur WhatsApp (lien wa.me prérempli).
    $this->actingAs($admin)->patch("/appointments/{$appointment->id}/status", ['status' => 'confirmed'])->assertRedirect();
    $this->actingAs($admin)->get('/actions?list=appointment_reminder')->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows', 1)
            ->where('rows.0.name', 'Estelle Moussavou')
            ->where('rows.0.whatsapp_url', fn ($url) => str_starts_with($url, 'https://wa.me/24177123456?text=')));
    $this->actingAs($admin)->post('/actions/done', [
        'action' => 'appointment_reminder', 'customer_id' => $appointment->customer_id, 'appointment_id' => $appointment->id, 'store_id' => $store->id,
    ])->assertRedirect();
    $this->actingAs($admin)->get('/actions?list=appointment_reminder')->assertInertia(fn ($page) => $page->has('rows', 0));

    // 6. Le jour du rendez-vous : encaissement depuis le RDV, points de fidélité.
    $this->travelTo(Carbon::parse('2026-10-20 11:15'));
    $this->actingAs($admin)->post("/appointments/{$appointment->id}/checkout", [
        'payment_method' => 'airtel_money',
        'lines' => [
            ['catalog_item_id' => $coupe->id, 'name' => 'Coupe brushing', 'quantity' => 1, 'unit_price' => 8000],
            ['catalog_item_id' => $shampoing->id, 'name' => 'Shampoing', 'quantity' => 1, 'unit_price' => 6000],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $purchase = Purchase::with('items')->sole();
    $customer = $appointment->customer->fresh();
    expect($appointment->fresh()->status)->toBe('done')
        ->and($purchase->amount)->toBe(14000)
        ->and($purchase->payment_method)->toBe('airtel_money')
        ->and($purchase->items)->toHaveCount(2)
        ->and($customer->purchases_count)->toBe(1)
        ->and(Loyalty::for($organization->fresh())->balance($customer))->toBe(14);

    // 7. Rapport du soir envoyé au dirigeant après 21 h.
    $this->actingAs($admin)->put('/settings/reports', ['enabled' => true, 'recipients' => ['dg@elegance.test'], 'send_at' => '21:00'])->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-10-20 21:05'));
    $this->artisan('crm:daily-report')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, fn ($mail) => $mail->hasTo('dg@elegance.test') && $mail->report['day'] === '2026-10-20');
    $this->actingAs($admin)->get('/reports/daily?date=2026-10-20')->assertOk();
});
