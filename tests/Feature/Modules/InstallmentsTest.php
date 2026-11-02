<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\PaymentSchedule;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\CustomerScoring;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 0));
    $this->organization = Organization::factory()->create(['sector' => 'sport', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['tranches', 'beneficiaires']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Club Akaé']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->parent = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Mireille Mba', 'consent_status' => 'opt_in'])->customer;
    $this->actingAs($this->admin)->post("/customers/{$this->parent->id}/beneficiaries", ['name' => 'Léo Mba', 'relation' => 'parent']);
    $this->leo = Customer::where('name', 'Léo Mba')->firstOrFail();
});

it('splits an amount into monthly installments, the parent paying for the child', function () {
    $this->actingAs($this->admin)->post('/payment-schedules', [
        'customer_id' => $this->leo->id,
        'store_id' => $this->store->id,
        'label' => 'Kimono et licence',
        'total' => 30000,
        'count' => 4,
        'first_due_on' => '2026-11-05',
        'pay_first_now' => true,
        'payment_method' => 'airtel_money',
    ])->assertSessionHas('success');

    $schedule = PaymentSchedule::firstOrFail();
    $installments = $schedule->installments;

    expect($schedule->customer_id)->toBe($this->parent->id)
        ->and($schedule->beneficiary_id)->toBe($this->leo->id)
        ->and($installments->pluck('amount')->all())->toBe([7500, 7500, 7500, 7500])
        ->and($installments->pluck('due_on')->map->toDateString()->all())->toBe(['2026-11-05', '2026-12-05', '2027-01-05', '2027-02-05'])
        ->and($installments[0]->status())->toBe('paid')
        ->and($installments[1]->status())->toBe('due');

    // La tranche payée est une vente sur la fiche de l'enfant.
    $sale = Purchase::findOrFail($installments[0]->purchase_id);
    expect($sale->amount)->toBe(7500)
        ->and($sale->source)->toBe('installment')
        ->and($sale->payment_method)->toBe('airtel_money')
        ->and($sale->contact->customer_id)->toBe($this->leo->id)
        ->and($this->leo->fresh()->purchases_count)->toBe(1);

    $this->actingAs($this->admin)->get("/installments/{$installments[0]->id}/receipt")->assertOk()->assertSee('Mireille Mba')->assertSee('Léo Mba')->assertSee('7 500');
    $this->actingAs($this->admin)->get("/installments/{$installments[1]->id}/receipt")->assertNotFound();
});

it('lists overdue installments and records a payment as a sale', function () {
    $this->actingAs($this->admin)->post('/payment-schedules', [
        'customer_id' => $this->parent->id, 'store_id' => $this->store->id, 'label' => 'Stage vacances',
        'total' => 20000, 'count' => 2, 'first_due_on' => '2026-10-20',
    ]);
    $late = Installment::orderBy('number')->first();

    $this->actingAs($this->admin)->get('/installments?status=overdue')->assertInertia(fn (Assert $page) => $page
        ->component('Installments/Index')
        ->has('installments.data', 1)
        ->where('installments.data.0.status', 'overdue')
        ->where('stats.overdue_amount', 10000));

    $this->actingAs($this->admin)->post("/installments/{$late->id}/pay", ['payment_method' => 'cash'])->assertSessionHas('success');
    expect($late->fresh()->status())->toBe('paid')
        ->and(Purchase::where('source', 'installment')->sum('amount'))->toBe(10000);

    // Déjà payée : pas de double encaissement.
    $this->actingAs($this->admin)->post("/installments/{$late->id}/pay")->assertSessionHasErrors('installment');
});

it('reminds the payer at the chosen dates, once per reminder', function () {
    $this->actingAs($this->admin)->post('/payment-schedules', [
        'customer_id' => $this->leo->id, 'store_id' => $this->store->id, 'label' => 'Mensualité',
        'total' => 26000, 'count' => 2, 'first_due_on' => '2026-11-04',
    ]);

    // J-2 : rappel proposé, au parent, pour l'enfant.
    $rows = (new ActionLists($this->admin))->rows('installment_due');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['customer_id'])->toBe($this->parent->id)
        ->and($rows[0]['reason'])->toContain('Échéance dans 2 jour(s)')->toContain('pour Léo Mba')
        ->and($rows[0]['message'])->toContain('13 000 FCFA');

    $this->actingAs($this->admin)->post('/actions/done', ['action' => 'installment_due', 'customer_id' => $this->parent->id, 'reference' => $rows[0]['reference']]);
    expect((new ActionLists($this->admin))->rows('installment_due'))->toBeEmpty();

    // Le jour même : nouvelle relance.
    $this->travelTo(now()->setDate(2026, 11, 4));
    expect((new ActionLists($this->admin))->rows('installment_due'))->toHaveCount(1);

    // Le module décoché : la liste disparaît des Actions du jour.
    $this->organization->setModules(['beneficiaires']);
    $this->organization->save();
    $this->admin->unsetRelation('organization');
    $this->actingAs($this->admin)->get('/actions')->assertInertia(fn (Assert $page) => $page
        ->where('lists', fn ($lists) => ! collect($lists)->contains('key', 'installment_due')));
    $this->actingAs($this->admin)->get('/installments')->assertRedirect('/dashboard');
});

it('shows the schedules on both fiches', function () {
    $this->actingAs($this->admin)->post('/payment-schedules', [
        'customer_id' => $this->leo->id, 'store_id' => $this->store->id, 'label' => 'Kimono', 'total' => 30000, 'count' => 3, 'first_due_on' => '2026-11-05',
    ]);

    $this->actingAs($this->admin)->get("/customers/{$this->parent->id}")->assertInertia(fn (Assert $page) => $page->has('schedules', 1)->where('schedules.0.beneficiary', 'Léo Mba'));
    $this->actingAs($this->admin)->get("/customers/{$this->leo->id}")->assertInertia(fn (Assert $page) => $page->has('schedules.0.installments', 3));
});

it('counts what a parent pays for a child on the parent fiche, without counting the sale twice', function () {
    $this->actingAs($this->admin)->post('/payment-schedules', [
        'customer_id' => $this->leo->id, 'store_id' => $this->store->id, 'label' => 'Judo',
        'total' => 30000, 'count' => 2, 'first_due_on' => '2026-11-02', 'pay_first_now' => true, 'payment_method' => 'cash',
    ]);
    CustomerScoring::class;
    app(CustomerScoring::class)->refreshOrganization($this->organization);

    $parent = $this->parent->fresh();
    $leo = $this->leo->fresh();

    // Le parent devient client (étape, segment, score) ; la vente reste celle de l'enfant.
    expect($parent->purchases_count)->toBe(1)
        ->and($parent->lifecycle_stage)->toBe('new')
        ->and($parent->total_spent)->toBe(0)
        ->and($parent->followed_spent)->toBe(15000)
        ->and($parent->rfm_segment)->not->toBe('none')
        ->and($parent->score)->not->toBeNull()
        ->and($leo->total_spent)->toBe(15000)
        ->and(Purchase::sum('amount'))->toBe(15000);

    $this->actingAs($this->admin)->get("/customers/{$parent->id}")->assertInertia(fn (Assert $page) => $page
        ->where('beneficiaryModule.followed_spent', 15000)
        ->where('rfm.frequency', 1)
        ->where('rfm.segment.key', fn ($key) => $key !== 'none'));
});
