<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerScoring;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 19)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['whatsapp_api_enabled' => false]);
    $this->brand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Salon Awa']);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'name' => 'Salon Awa Louis']);
    $this->other = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'name' => 'Salon Awa Akanda']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);

    $buy = function (Contact $contact, array $daysAgo, int $amount = 20000) {
        foreach ($daysAgo as $days) {
            Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $contact->store_id, 'amount' => $amount, 'purchased_at' => now()->subDays($days)]);
        }
    };

    // Aïcha : tous les 10 jours, puis plus rien depuis 40 jours → risque élevé.
    $this->aicha = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha Mba', 'consent_status' => 'unknown']);
    $buy($this->aicha, [70, 60, 50, 40], 50000);

    // Brice : client régulier et récent.
    $this->brice = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24166210347', 'name' => 'Brice Obame', 'consent_status' => 'opt_in']);
    $buy($this->brice, [30, 20, 10, 2], 30000);

    // Carine : un seul achat il y a 12 jours → deuxième achat à déclencher.
    $this->carine = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24174000111', 'name' => 'Carine Ndong', 'consent_status' => 'unknown']);
    $buy($this->carine, [12]);

    // Didier : même profil qu'Aïcha mais a répondu « Stop ».
    $this->didier = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24174000222', 'name' => 'Didier Ella', 'consent_status' => 'opt_out']);
    $buy($this->didier, [70, 60, 50, 40], 50000);

    $this->artisan('crm:refresh-lifecycle')->assertSuccessful();
});

it('stores RFM segment, score and churn risk for every customer', function () {
    $aicha = $this->aicha->customer->fresh();
    $brice = $this->brice->customer->fresh();
    $carine = $this->carine->customer->fresh();

    expect($aicha->avg_purchase_interval_days)->toBe(10)
        ->and($aicha->churn_risk)->toBe('high')
        ->and($aicha->next_purchase_expected_at->toDateString())->toBe(now()->subDays(30)->toDateString())
        ->and($brice->churn_risk)->toBeNull()
        ->and($brice->rfm_segment)->not->toBeNull()
        ->and($brice->score)->toBeGreaterThan($aicha->score)
        ->and($carine->rfm_segment)->toBe('new')
        ->and($carine->churn_risk)->toBeNull();

    $nobody = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24174000333'])->customer;
    app(CustomerScoring::class)->refreshOrganization($this->organization);

    expect($nobody->fresh()->rfm_segment)->toBe('none')->and($nobody->fresh()->score)->toBeNull();
});

it('computes the churn risk from the usual purchase rhythm', function () {
    expect(CustomerScoring::churnRisk(25, 10, 'active'))->toBe('medium')
        ->and(CustomerScoring::churnRisk(31, 10, 'active'))->toBe('high')
        ->and(CustomerScoring::churnRisk(15, 10, 'active'))->toBeNull()
        ->and(CustomerScoring::churnRisk(5, 10, 'at_risk'))->toBe('high')
        ->and(CustomerScoring::churnRisk(400, 10, 'churned'))->toBeNull();
});

it('lists at-risk customers with a prefilled wa.me link, never an opted-out one', function () {
    $this->actingAs($this->admin)->get('/actions?list=at_risk')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Actions/Index')
            ->where('active', 'at_risk')
            ->where('rows', function ($rows) {
                $rows = collect($rows);
                expect($rows->pluck('name')->all())->toBe(['Aïcha Mba'])
                    ->and($rows[0]['message'])->toStartWith('Bonjour Aïcha, cela fait un moment')
                    ->and($rows[0]['message'])->toContain('Salon Awa Louis')
                    ->and($rows[0]['whatsapp_url'])->toStartWith('https://wa.me/24177451288?text=Bonjour%20A')
                    ->and($rows[0]['reason'])->toContain("d'habitude tous les 10 jours");

                return true;
            })
            ->where('lists', fn ($lists) => collect($lists)->firstWhere('key', 'second_purchase')['count'] === 1));
});

it('removes a customer from the list once the follow-up is recorded', function () {
    $customer = $this->aicha->customer;

    $this->actingAs($this->admin)->post('/actions/done', [
        'action' => 'at_risk',
        'customer_id' => $customer->id,
        'store_id' => $this->store->id,
    ])->assertRedirect();

    expect(CustomerFollowUp::where('customer_id', $customer->id)->sole())
        ->action->toBe('at_risk')
        ->channel->toBe('whatsapp_manual')
        ->user_id->toBe($this->admin->id);

    $this->actingAs($this->admin)->get('/actions?list=at_risk')
        ->assertInertia(fn ($page) => $page->where('rows', []));

    // Le délai de la liste passé, le client revient.
    $this->travel(15)->days();
    $this->actingAs($this->admin)->get('/actions?list=at_risk')
        ->assertInertia(fn ($page) => $page->where('rows.0.name', 'Aïcha Mba'));
});

it('uses the messages customised by the company', function () {
    $this->actingAs($this->admin)->put('/actions/messages', [
        'messages' => ['second_purchase' => 'Coucou {prenom} de {boutique} !'],
    ])->assertRedirect();

    $this->actingAs($this->admin)->get('/actions?list=second_purchase')
        ->assertInertia(fn ($page) => $page->where('rows.0.message', 'Coucou Carine de Salon Awa Louis !'));
});

it('limits a store manager to the customers of their store', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->other->id,
    ]);

    $this->actingAs($manager)->get('/actions?list=at_risk')
        ->assertInertia(fn ($page) => $page->where('rows', []));

    $this->actingAs($manager)->post('/actions/done', ['action' => 'at_risk', 'customer_id' => $this->aicha->customer_id])
        ->assertForbidden();

    $this->actingAs($manager)->put('/actions/messages', ['messages' => ['at_risk' => 'x']])->assertForbidden();
});

it('books an appointment for a new phone number and reminds it the day before', function () {
    $this->actingAs($this->admin)->post('/appointments', [
        'store_id' => $this->store->id,
        'phone' => '077 12 34 56',
        'name' => 'Estelle Moussavou',
        'starts_at' => now()->addDay()->setTime(15, 30)->format('Y-m-d\TH:i'),
        'service' => 'Tresses',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $appointment = Appointment::sole();
    $customer = $appointment->customer;

    expect($customer->name)->toBe('Estelle Moussavou')
        ->and($customer->phone_e164)->toBe('24177123456')
        ->and(Contact::where('customer_id', $customer->id)->sole()->store_id)->toBe($this->store->id)
        ->and($appointment->status)->toBe('planned');

    $this->actingAs($this->admin)->get('/actions?list=appointment_reminder')
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.appointment_id', $appointment->id)
            ->where('rows.0.reason', 'Rendez-vous demain à 15h30 (Tresses)')
            ->where('rows.0.message', fn ($m) => str_contains($m, 'le mardi 20 octobre à 15h30')));

    $this->actingAs($this->admin)->post('/actions/done', [
        'action' => 'appointment_reminder',
        'customer_id' => $customer->id,
        'appointment_id' => $appointment->id,
    ]);

    $this->actingAs($this->admin)->get('/actions?list=appointment_reminder')
        ->assertInertia(fn ($page) => $page->where('rows', []));

    $this->actingAs($this->admin)->get('/appointments?view=week')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Appointments/Index')
            ->where('appointments.0.customer_name', 'Estelle Moussavou'));
});

it('lists missed appointments until a new one is booked', function () {
    $customer = $this->carine->customer;
    $missed = Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => $customer->id, 'starts_at' => now()->subDays(2)]);

    $this->actingAs($this->admin)->patch("/appointments/{$missed->id}/status", ['status' => 'no_show'])->assertRedirect();

    $this->actingAs($this->admin)->get('/actions?list=no_show')
        ->assertInertia(fn ($page) => $page->where('rows.0.customer_id', $customer->id));

    $this->actingAs($this->admin)->post('/appointments', [
        'store_id' => $this->store->id,
        'customer_id' => $customer->id,
        'starts_at' => now()->addDays(5)->format('Y-m-d\TH:i'),
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/actions?list=no_show')
        ->assertInertia(fn ($page) => $page->where('rows', []));
});

it('refuses appointments outside the user scope', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->other->id,
    ]);
    $appointment = Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => $this->aicha->customer_id]);

    $this->actingAs($manager)->patch("/appointments/{$appointment->id}/status", ['status' => 'done'])->assertForbidden();
    $this->actingAs($manager)->post('/appointments', ['store_id' => $this->store->id, 'phone' => '077123456', 'starts_at' => now()->addDay()->toDateTimeString()])
        ->assertSessionHasErrors('store_id');
});

it('shows score, churn alert, appointments and follow-ups on the customer page', function () {
    $customer = $this->aicha->customer;
    Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => $customer->id]);
    CustomerFollowUp::create(['customer_id' => $customer->id, 'action' => 'at_risk', 'user_id' => $this->admin->id, 'done_at' => now()]);

    $this->actingAs($this->admin)->get("/customers/{$customer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scoring.churn_risk', 'high')
            ->where('scoring.avg_interval_days', 10)
            ->where('appointments.0.store_name', 'Salon Awa Louis')
            ->where('followUps.0.label', 'Clients à risque à relancer')
            ->where('whatsappUrl', fn ($url) => str_starts_with($url, 'https://wa.me/24177451288'))
            ->where('apiEnabled', false));

    $this->actingAs($this->admin)->get('/customers?risk=any')
        ->assertInertia(fn ($page) => $page->where('customers.total', 2)->where('riskCount', 2));

    $this->actingAs($this->admin)->get('/customers?segment=new')
        ->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.name', 'Carine Ndong'));
});

it('hides API features until the company enables the WhatsApp API', function () {
    $this->actingAs($this->admin)->get('/campaigns')->assertRedirect(route('actions.index'));
    $this->actingAs($this->admin)->get('/templates')->assertRedirect(route('actions.index'));
    $this->actingAs($this->admin)->get('/settings/whatsapp')->assertRedirect(route('actions.index'));

    $this->actingAs($this->admin)->put('/organisation/whatsapp-api', ['enabled' => true])->assertRedirect();

    expect($this->organization->fresh()->usesWhatsAppApi())->toBeTrue();
    $this->actingAs($this->admin)->get('/campaigns')->assertOk();
});

it('starts a newly registered company without the WhatsApp API', function () {
    auth()->logout();

    $this->post('/register', [
        'organization_name' => 'Institut Nyota',
        'store_name' => 'Nyota Centre',
        'name' => 'Nadia Ondo',
        'email' => 'nadia@example.com',
        'password' => 'Secret-123456',
        'password_confirmation' => 'Secret-123456',
    ])->assertRedirect(route('dashboard.index'));

    expect(Organization::where('name', 'Institut Nyota')->sole()->usesWhatsAppApi())->toBeFalse()
        ->and(Customer::count())->toBe(4);
});
