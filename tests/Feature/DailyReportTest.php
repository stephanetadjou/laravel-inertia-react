<?php

use App\Mail\DailyReportMail;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\CustomerFollowUp;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\DailyReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // Mardi 27 octobre 2026, 20 h.
    $this->travelTo(Carbon::parse('2026-10-27 20:00'));
    $this->organization = Organization::factory()->create(['name' => 'Institut Élégance', 'whatsapp_api_enabled' => false]);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Louis']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'email' => 'dg@elegance.ga']);
    $this->grace = StaffMember::factory()->create(['store_id' => $this->store->id, 'name' => 'Grâce']);
    $this->tresses = CatalogItem::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Tresses', 'price' => 20000]);

    $this->estelle = Contact::factory()->create(['store_id' => $this->store->id, 'name' => 'Estelle Ndong']);
    $this->brice = Contact::factory()->create(['store_id' => $this->store->id, 'name' => 'Brice Ondo']);
});

function appointmentAt(Contact $contact, string $at, string $status, array $extra = []): Appointment
{
    return Appointment::factory()->create(['store_id' => $contact->store_id, 'customer_id' => $contact->customer_id, 'starts_at' => $at, 'status' => $status, ...$extra]);
}

function saleOn(Contact $contact, string $day, int $amount, array $lines = [], ?string $method = 'cash'): Purchase
{
    $purchase = Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $contact->store_id, 'amount' => $amount, 'quantity' => 1, 'purchased_at' => $day, 'payment_method' => $method]);
    foreach ($lines as $line) {
        $purchase->items()->create($line);
    }

    return $purchase;
}

it('sums up the day, tomorrow and the actions for the manager', function () {
    $done = appointmentAt($this->estelle, '2026-10-27 10:00', 'done', ['staff_member_id' => $this->grace->id]);
    appointmentAt($this->brice, '2026-10-27 16:30', 'no_show', ['service' => 'Coupe homme']);
    appointmentAt($this->brice, '2026-09-10 10:00', 'no_show');
    appointmentAt($this->brice, '2026-08-20 10:00', 'no_show');
    appointmentAt($this->estelle, '2026-10-27 11:00', 'cancelled');
    appointmentAt($this->estelle, '2026-10-28 09:00', 'confirmed');
    appointmentAt($this->brice, '2026-10-28 11:00', 'requested');

    $sale = saleOn($this->estelle, '2026-10-27', 26000, [
        ['type' => 'service', 'name' => 'Tresses', 'quantity' => 1, 'unit_price' => 20000, 'amount' => 20000],
        ['type' => 'product', 'name' => 'Huile', 'quantity' => 1, 'unit_price' => 6000, 'amount' => 6000],
    ], 'airtel_money');
    $done->update(['purchase_id' => $sale->id]);
    saleOn($this->brice, '2026-10-27', 4000);
    saleOn($this->brice, '2026-10-20', 20000);
    CustomerFollowUp::create(['customer_id' => $this->estelle->customer_id, 'action' => 'at_risk', 'done_at' => '2026-10-20 10:00']);
    $this->organization->update(['report_settings' => ['monthly_target' => 600000]]);

    $r = (new DailyReport($this->organization->fresh(), today()))->build();

    expect($r['kpis'])->toMatchArray([
        'revenue' => 30000,
        'revenue_vs_last_week' => 50.0,
        'done' => 1,
        'planned' => 3,
        'cancelled' => 1,
        'no_shows' => 1,
        'no_show_rate' => 50.0,
        'sales' => 2,
        'average_basket' => 15000,
        'recovered' => 1,
        'recovered_amount' => 26000,
    ])
        ->and($r['tomorrow'])->toMatchArray(['count' => 2, 'confirmed' => 1, 'to_confirm' => 1])
        ->and($r['by_store'][0])->toMatchArray(['services' => 20000, 'products' => 6000, 'revenue' => 30000])
        ->and($r['services'][0])->toBe(['name' => 'Tresses', 'count' => 1, 'revenue' => 20000])
        ->and(collect($r['payments'])->pluck('label', 'amount')->all())->toBe([26000 => 'Airtel Money', 4000 => 'Espèces'])
        ->and($r['team'][0])->toMatchArray(['name' => 'Grâce', 'appointments' => 1, 'revenue' => 26000])
        ->and($r['absents'][0])->toMatchArray(['name' => 'Brice Ondo', 'count' => 3, 'remark' => '3e absence : demander un acompte'])
        ->and($r['target'])->toMatchArray(['goal' => 600000, 'done' => 50000])
        ->and($r['series'])->toHaveCount(14)
        ->and($r['summary'])->toContain('30 000 FCFA encaissés')->toContain('Brice Ondo pour la 3e fois')->toContain('Demain, 1 rendez-vous sur 2 n’est pas confirmé')
        ->and($r['actions'][0])->toContain('Confirmer le rendez-vous de demain');

    $this->actingAs($this->admin)->get('/reports/daily?date=2026-10-27')
        ->assertOk()
        ->assertSee('Rapport du mardi 27 octobre 2026')
        ->assertSee('Brice Ondo');
});

it('sends the report once a day after the chosen time, to the recipients', function () {
    Mail::fake();
    $this->organization->update(['report_settings' => ['enabled' => true, 'recipients' => ['dg@elegance.ga'], 'send_at' => '21:00']]);

    $this->artisan('crm:daily-report')->assertSuccessful();
    Mail::assertNothingSent();

    $this->travelTo(Carbon::parse('2026-10-27 21:05'));
    $this->artisan('crm:daily-report')->assertSuccessful();
    $this->artisan('crm:daily-report')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, 1);
    Mail::assertSent(DailyReportMail::class, fn ($mail) => $mail->hasTo('dg@elegance.ga') && $mail->report['day'] === '2026-10-27');
    expect($this->organization->fresh()->reportSettings()['last_sent_on'])->toBe('2026-10-27');
});

it('lets managers set the report and send themselves a test', function () {
    Mail::fake();

    $this->actingAs($this->admin)->put('/settings/reports', ['enabled' => true, 'recipients' => [], 'send_at' => '21:00'])
        ->assertSessionHasErrors('recipients');
    $this->actingAs($this->admin)->put('/settings/reports', ['enabled' => true, 'recipients' => ['pas-un-email'], 'send_at' => '21:00'])
        ->assertSessionHasErrors('recipients.0');
    $this->actingAs($this->admin)->put('/settings/reports', ['enabled' => true, 'recipients' => ['dg@elegance.ga'], 'send_at' => '20:30', 'monthly_target' => 18000000])
        ->assertSessionHasNoErrors();

    expect($this->organization->fresh()->reportSettings())->toMatchArray(['enabled' => true, 'send_at' => '20:30', 'monthly_target' => 18000000]);

    $this->actingAs($this->admin)->post('/settings/reports/test')->assertRedirect();
    Mail::assertSent(DailyReportMail::class, fn ($mail) => $mail->hasTo('dg@elegance.ga'));

    $this->actingAs($this->admin)->get('/settings/reports')->assertOk()->assertInertia(fn ($page) => $page->component('Settings/Reports'));

    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->store->id,
    ]);
    $this->actingAs($manager)->get('/reports/daily')->assertForbidden();
});

it('records the payment method at checkout', function () {
    $appointment = appointmentAt($this->estelle, '2026-10-27 10:00', 'confirmed');

    $this->actingAs($this->admin)->post("/appointments/{$appointment->id}/checkout", [
        'payment_method' => 'moov_money',
        'lines' => [['catalog_item_id' => $this->tresses->id, 'name' => 'Tresses', 'quantity' => 1, 'unit_price' => 20000]],
    ])->assertSessionHasNoErrors();

    expect(Purchase::sole()->payment_method)->toBe('moov_money');
});
