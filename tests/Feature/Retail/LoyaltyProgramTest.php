<?php

use App\Jobs\RecalculateLoyaltyJob;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\ImportBatch;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyRedemption;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerSearch;
use App\Services\LoyaltyEngine;
use App\Services\SalesImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-04 10:00'));
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $role = fn (string $name) => Role::where('organization_id', $this->organization->id)->where('name', $name)->value('id');
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Admin groupe')]);

    $this->adidas = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $this->mango = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mango']);
    $this->adidasGlass = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->adidas->id, 'name' => 'Glass', 'code' => 'ADI-GL']);
    $this->adidasMall = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->adidas->id, 'name' => 'Mall', 'code' => 'ADI-MA']);
    $this->mangoGlass = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->mango->id, 'name' => 'Glass', 'code' => 'MAN-GL']);

    $this->program = LoyaltyProgram::create(['organization_id' => $this->organization->id, 'name' => 'Fidélité 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $rule = ['threshold' => 400000, 'tranche_amount' => 10000, 'points_per_tranche' => 1, 'activation_bonus' => 50, 'tiers' => [['points' => 300, 'percent' => 10], ['points' => 350, 'percent' => 15]], 'validity_months' => 12, 'reset_after_use' => true];
    $this->program->rules()->create([...$rule, 'brand_id' => $this->adidas->id]);
    $this->program->rules()->create([...$rule, 'brand_id' => $this->mango->id, 'tiers' => [['points' => 350, 'percent' => 10], ['points' => 400, 'percent' => 15]]]);

    $this->buy = function (Store $store, int $amount, string $on, string $phone = '24162605054', array $extra = []) {
        $contact = Contact::where('store_id', $store->id)->where('phone_e164', $phone)->first()
            ?? Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => $phone, 'name' => 'Awa Ndong', 'consent_status' => 'opt_in']);

        return Purchase::create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => $amount, 'purchased_at' => $on, ...$extra]);
    };
    $this->card = fn (Brand $brand, string $phone = '24162605054') => LoyaltyCard::where('brand_id', $brand->id)
        ->where('customer_id', Customer::where('phone_e164', $phone)->value('id'))->first();
    $this->recalculate = fn () => app(LoyaltyEngine::class)->recalculate($this->organization);

    $this->importFile = function (string $name, array $lines) {
        $path = tempnam(sys_get_temp_dir(), 'ventes');
        $out = fopen($path, 'w');
        foreach ($lines as $line) {
            fputcsv($out, $line, ';', '"', '');
        }
        fclose($out);
        $import = app(SalesImport::class);
        $batch = $import->upload(new UploadedFile($path, $name, null, null, true), $this->admin);
        $import->validate($batch, $batch->mapping, null, $this->admin);
        $batch->update(['status' => ImportBatch::STATUS_PROCESSING]);
        $import->process($batch);

        return $batch->refresh();
    };
});

it('adds up every store of a brand and keeps brands apart', function () {
    ($this->buy)($this->adidasGlass, 300000, '2026-02-01');
    ($this->buy)($this->adidasMall, 130000, '2026-03-01');
    ($this->buy)($this->mangoGlass, 200000, '2026-03-02');
    ($this->recalculate)();

    expect(($this->card)($this->adidas)->points)->toBe(93)
        ->and(($this->card)($this->adidas)->status)->toBe('active')
        ->and(($this->card)($this->mango)->points)->toBe(0)
        ->and(($this->card)($this->mango)->status)->toBe('none')
        ->and((int) ($this->card)($this->mango)->amount_to_threshold)->toBe(200000);
});

it('only reads tickets inside the programme dates and ignores cancelled and anonymous ones', function () {
    ($this->buy)($this->adidasGlass, 900000, '2025-12-31');
    ($this->buy)($this->adidasGlass, 900000, '2026-02-01', extra: ['status' => Purchase::STATUS_CANCELLED]);
    Purchase::create(['contact_id' => null, 'store_id' => $this->adidasGlass->id, 'amount' => 900000, 'purchased_at' => '2026-02-01']);
    ($this->buy)($this->adidasGlass, 100000, '2026-03-01');
    ($this->recalculate)();

    expect((int) ($this->card)($this->adidas)->program_amount)->toBe(100000)
        ->and(($this->card)($this->adidas)->status)->toBe('none');
});

it('gives the same cards and journal after a second run', function () {
    ($this->buy)($this->adidasGlass, 500000, '2026-02-01');
    ($this->recalculate)();
    $first = [LoyaltyCard::count(), LoyaltyMovement::count(), ($this->card)($this->adidas)->points];
    ($this->recalculate)();

    expect([LoyaltyCard::count(), LoyaltyMovement::count(), ($this->card)($this->adidas)->points])->toBe($first);
});

it('recalculates only the given customers', function () {
    ($this->buy)($this->adidasGlass, 500000, '2026-02-01');
    ($this->buy)($this->adidasGlass, 500000, '2026-02-01', '24174000000');
    ($this->recalculate)();
    $touched = Customer::where('phone_e164', '24162605054')->value('id');
    ($this->buy)($this->adidasGlass, 100000, '2026-03-01');
    ($this->buy)($this->adidasGlass, 100000, '2026-03-01', '24174000000');

    app(LoyaltyEngine::class)->recalculate($this->organization, [$touched]);

    expect(($this->card)($this->adidas)->points)->toBe(110)
        ->and(($this->card)($this->adidas, '24174000000')->points)->toBe(100);
});

it('imports the agreed file format and moves the points, once', function () {
    Queue::fake();
    $header = ['date_vente', 'enseigne', 'magasin', 'numero_ticket', 'telephone', 'montant_net', 'nom', 'prenom', 'type_ligne', 'remise_fidelite', 'sexe', 'date_naissance', 'quartier', 'email', 'vendeur'];
    $lines = [
        $header,
        // Deux lignes pour un ticket, sans famille ; deux enseignes ont un magasin « Glass ».
        ['2026-03-01', 'Mango', 'Glass', 'T1', '+24162605054', '250000', 'Ndong', 'Awa', 'vente', '', 'F', '1990-05-12', 'Louis', 'awa@example.test', 'Lucie'],
        ['2026-03-01', 'Mango', 'Glass', 'T1', '+24162605054', '180000', 'Ndong', 'Awa', 'vente', '', 'F', '1990-05-12', 'Louis', 'awa@example.test', 'Lucie'],
        ['2026-03-05', 'Mango', 'Glass', 'T2', '+24162605054', '-20000', 'Ndong', 'Awa', 'retour', '', '', '', '', '', ''],
        ['2026-03-10', 'Inconnue', 'Glass', 'T3', '+24162605054', '20000', 'Ndong', 'Awa', 'vente', '', '', '', '', '', ''],
    ];
    $batch = ($this->importFile)('ventes.csv', $lines);

    expect($batch->mapping)->toHaveKeys(['date', 'brand', 'store', 'receipt', 'phone', 'line_total', 'name', 'first_name', 'line_type', 'loyalty_discount', 'gender', 'birthdate', 'district', 'email'])
        ->and($batch->rejected_rows)->toBe(1)
        ->and($batch->rows()->where('status', 'rejected')->value('error_code'))->toBe('brand_unknown')
        ->and(Purchase::where('store_id', $this->mangoGlass->id)->count())->toBe(2);

    Queue::assertPushed(RecalculateLoyaltyJob::class, fn ($job) => $job->customerIds === [Customer::where('phone_e164', '24162605054')->value('id')]);

    $customer = Customer::where('phone_e164', '24162605054')->first();
    expect($customer->name)->toBe('Awa Ndong')
        ->and($customer->gender)->toBe('F')
        ->and($customer->birthdate->toDateString())->toBe('1990-05-12')
        ->and($customer->district)->toBe('Louis')
        ->and($customer->email)->toBe('awa@example.test')
        ->and(Purchase::where('receipt_number', 'T1')->first()->properties)->toMatchArray(['vendeur' => 'Lucie']);

    ($this->recalculate)();
    expect(($this->card)($this->mango)->points)->toBe(41 + 50);

    // Les mêmes tickets dans un autre fichier : aucun doublon.
    $again = ($this->importFile)('ventes-bis.csv', array_slice($lines, 0, 4));
    ($this->recalculate)();
    expect($again->duplicate_rows)->toBe(3)
        ->and(($this->card)($this->mango)->points)->toBe(41 + 50);
});

it('resets the points from the loyalty discount column of the import', function () {
    Queue::fake();
    ($this->buy)($this->adidasGlass, 2500000, '2026-02-01');
    ($this->importFile)('remise.csv', [
        ['date_vente', 'enseigne', 'magasin', 'numero_ticket', 'telephone', 'montant_net', 'nom', 'remise_fidelite'],
        ['2026-04-01', 'Adidas', 'ADI-GL', 'R1', '+24162605054', '90000', 'Awa Ndong', '10%'],
    ]);
    ($this->recalculate)();

    $ticket = Purchase::where('receipt_number', 'R1')->first();
    expect($ticket->loyalty_discount_percent)->toBe(10)
        ->and(($this->card)($this->adidas)->points)->toBe(9)
        ->and((int) LoyaltyMovement::where('type', 'discount_used')->value('amount'))->toBe(10000);
});

it('applies a discount to the current programme only, and to an older card that is still valid otherwise', function () {
    Queue::fake();
    $old = LoyaltyProgram::create(['organization_id' => $this->organization->id, 'name' => 'Fidélité 2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    $old->rules()->create($this->program->rules()->where('brand_id', $this->adidas->id)->first()->only(['brand_id', 'threshold', 'tranche_amount', 'points_per_tranche', 'activation_bonus', 'tiers', 'validity_months', 'reset_after_use']));

    ($this->buy)($this->adidasGlass, 500000, '2025-12-01');
    ($this->buy)($this->adidasGlass, 500000, '2026-02-01');
    ($this->buy)($this->adidasGlass, 100000, '2026-03-01', extra: ['loyalty_discount_percent' => 10]);
    ($this->recalculate)();
    $cards = fn () => LoyaltyCard::where('brand_id', $this->adidas->id)->orderBy('loyalty_program_id')->get()->keyBy('loyalty_program_id');

    expect($cards()[$this->program->id]->points)->toBe(10)
        ->and($cards()[$old->id]->points)->toBe(100)
        ->and(LoyaltyMovement::where('type', 'discount_ignored')->count())->toBe(0);

    // Seule la carte 2025 est active : c'est elle qui sert.
    Purchase::where('amount', 500000)->whereDate('purchased_at', '2026-02-01')->update(['amount' => 10000]);
    ($this->recalculate)();

    expect($cards()[$this->program->id]->status)->toBe('none')
        ->and($cards()[$old->id]->points)->toBe(0)
        ->and($cards()[$old->id]->discounts_used)->toBe(1);
});

it('records the discount from the customer page', function () {
    ($this->buy)($this->adidasGlass, 2500000, '2026-02-01');
    ($this->recalculate)();
    $card = ($this->card)($this->adidas);

    $this->actingAs($this->admin)->post("/customers/{$card->customer_id}/loyalty-cards/{$card->id}/redeem", [
        'store_id' => $this->adidasMall->id, 'tier_points' => 300, 'amount' => 25000, 'used_on' => '2026-11-03',
    ])->assertSessionHasNoErrors();

    expect(($this->card)($this->adidas)->points)->toBe(0)
        ->and(($this->card)($this->adidas)->status)->toBe('active')
        ->and(LoyaltyRedemption::count())->toBe(1);

    // Un magasin d'une autre enseigne est refusé.
    $this->actingAs($this->admin)->post("/customers/{$card->customer_id}/loyalty-cards/".($this->card)($this->adidas)->id.'/redeem', [
        'store_id' => $this->mangoGlass->id, 'tier_points' => 300, 'amount' => 25000, 'used_on' => '2026-11-03',
    ])->assertSessionHasErrors('store_id');
});

it('shows one block per brand on the customer page', function () {
    ($this->buy)($this->adidasGlass, 500000, '2026-02-01');
    ($this->buy)($this->mangoGlass, 100000, '2026-02-01');
    ($this->recalculate)();

    $this->actingAs($this->admin)->get('/customers/'.($this->card)($this->adidas)->customer_id)
        ->assertInertia(fn ($page) => $page->has('loyaltyCards', 2));
});

it('filters customers by brand, status and tier, crossed with RFM, and saves them as a group', function () {
    ($this->buy)($this->adidasGlass, 2500000, '2026-02-01');
    ($this->buy)($this->adidasGlass, 350000, '2026-02-01', '24174000000');
    ($this->buy)($this->mangoGlass, 500000, '2026-02-01', '24166000000');
    ($this->recalculate)();
    Customer::where('phone_e164', '24162605054')->update(['rfm_segment' => 'champions']);

    $phones = fn (array $filters) => (new CustomerSearch($this->admin, $filters))->baseQuery()->pluck('phone_e164')->sort()->values()->all();

    expect($phones(['loyalty_brand' => $this->adidas->id, 'loyalty_status' => 'active']))->toBe(['24162605054'])
        ->and($phones(['loyalty_brand' => $this->adidas->id, 'loyalty_status' => 'near_threshold']))->toBe(['24174000000'])
        ->and($phones(['loyalty_status' => 'active']))->toBe(['24162605054', '24166000000'])
        ->and($phones(['loyalty_tier' => 10]))->toBe(['24162605054'])
        ->and($phones(['loyalty_status' => 'active', 'segment' => 'champions']))->toBe(['24162605054'])
        ->and($phones(['loyalty_program' => $this->program->id, 'loyalty_status' => 'none']))->toBe(['24174000000']);

    $this->actingAs($this->admin)->post('/audiences/group', ['name' => 'Palier 10', 'filters' => ['loyalty_tier' => 10]])->assertRedirect();
    expect(Group::where('name', 'Palier 10')->first()->contacts()->count())->toBe(1);
});

it('lets each tier give any kind of reward, on the card, the button and the import', function () {
    Queue::fake();
    $rule = $this->program->rules()->where('brand_id', $this->adidas->id)->first();
    $rule->update(['tiers' => [['points' => 60, 'type' => 'voucher', 'amount' => 15000], ['points' => 70, 'type' => 'gift', 'label' => 'Casquette offerte']]]);

    ($this->buy)($this->adidasGlass, 150000 + 100000, '2026-02-01');
    ($this->buy)($this->adidasGlass, 150000, '2026-02-02');
    ($this->buy)($this->adidasGlass, 110000, '2026-02-03');
    ($this->recalculate)();
    $card = ($this->card)($this->adidas);

    expect($card->points)->toBe(101)
        ->and($card->reward_label)->toBe('Casquette offerte')
        ->and((new CustomerSearch($this->admin, ['loyalty_tier' => 'Casquette offerte']))->baseQuery()->count())->toBe(1);

    $this->actingAs($this->admin)->post("/customers/{$card->customer_id}/loyalty-cards/{$card->id}/redeem", [
        'store_id' => $this->adidasGlass->id, 'tier_points' => 60, 'used_on' => '2026-03-01',
    ])->assertSessionHasNoErrors();

    $used = LoyaltyMovement::where('type', 'discount_used')->first();
    expect(($this->card)($this->adidas)->points)->toBe(0)
        ->and($used->note)->toContain('Bon d’achat')
        ->and((int) $used->amount)->toBe(15000);

    ($this->buy)($this->adidasGlass, 800000, '2026-03-02');
    ($this->importFile)('cadeau.csv', [
        ['date_vente', 'enseigne', 'magasin', 'numero_ticket', 'telephone', 'montant_net', 'nom', 'remise_fidelite'],
        ['2026-04-01', 'Adidas', 'ADI-GL', 'C1', '+24162605054', '20000', 'Awa Ndong', 'Casquette offerte'],
    ]);
    ($this->recalculate)();

    expect(Purchase::where('receipt_number', 'C1')->first()->loyalty_discount_percent)->toBe(0)
        ->and(($this->card)($this->adidas)->points)->toBe(2)
        ->and(LoyaltyMovement::where('type', 'discount_used')->latest('id')->first()->note)->toBe('Casquette offerte');
});

it('applies one grid to every brand when the programme uses the same rules, and can switch back', function () {
    Queue::fake();
    $this->program->update(['ends_on' => '2026-05-31']);
    $grid = ['threshold' => 100000, 'tranche_amount' => 5000, 'points_per_tranche' => 2, 'activation_bonus' => 10, 'tiers' => [['points' => 90, 'type' => 'perk', 'label' => 'Retouches offertes']], 'validity_months' => 6, 'reset_after_use' => true];
    $payload = ['name' => 'Été', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31', 'same_rules' => true, 'rules' => [
        ['brand_id' => $this->adidas->id, ...$grid],
        ['brand_id' => $this->mango->id, ...$grid, 'threshold' => 999],
    ]];

    $this->actingAs($this->admin)->post('/settings/loyalty-programs', $payload)->assertSessionHasNoErrors();
    $program = LoyaltyProgram::where('name', 'Été')->first();
    expect($program->same_rules)->toBeTrue()
        ->and($program->rules()->pluck('threshold')->all())->toBe([100000, 100000]);

    $this->actingAs($this->admin)->put("/settings/loyalty-programs/{$program->id}", [...$payload, 'same_rules' => false])->assertSessionHasNoErrors();
    expect($program->fresh()->same_rules)->toBeFalse()
        ->and($program->rules()->where('brand_id', $this->mango->id)->value('threshold'))->toBe(999);
});

it('refuses a reward left empty', function () {
    Queue::fake();
    $this->program->update(['ends_on' => '2026-05-31']);

    $this->actingAs($this->admin)->post('/settings/loyalty-programs', ['name' => 'Été', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31', 'rules' => [[
        'brand_id' => $this->adidas->id, 'threshold' => 1, 'tranche_amount' => 1, 'points_per_tranche' => 1, 'activation_bonus' => 0,
        'tiers' => [['points' => 10, 'type' => 'gift', 'label' => '']], 'validity_months' => 6, 'reset_after_use' => true,
    ]]])->assertSessionHasErrors('rules');
});

it('no longer counts a finished programme as close to a threshold or a tier', function () {
    ($this->buy)($this->adidasGlass, 350000, '2026-02-01');
    ($this->recalculate)();
    $near = fn () => (new CustomerSearch($this->admin, ['loyalty_status' => 'near_threshold']))->baseQuery()->count();

    expect($near())->toBe(1);
    $this->travelTo(Carbon::parse('2027-01-05'));
    expect($near())->toBe(0);
});

it('lets any company create a programme, refuses overlapping brands, and recalculates', function () {
    Queue::fake();
    $payload = ['name' => 'Été', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31', 'rules' => [[
        'brand_id' => $this->adidas->id, 'threshold' => 100000, 'tranche_amount' => 5000, 'points_per_tranche' => 2, 'activation_bonus' => 0,
        'tiers' => [['points' => 90, 'percent' => 20], ['points' => 40, 'percent' => 5]], 'validity_months' => 6, 'reset_after_use' => false,
    ]]];

    $this->actingAs($this->admin)->post('/settings/loyalty-programs', $payload)->assertSessionHasErrors('rules');

    $this->program->update(['ends_on' => '2026-05-31']);
    $this->actingAs($this->admin)->post('/settings/loyalty-programs', $payload)->assertSessionHasNoErrors();

    $program = LoyaltyProgram::where('name', 'Été')->first();
    expect($program->rules->first()->tiers[0])->toBe(['points' => 40, 'percent' => 5])
        ->and($program->rules->first()->reset_after_use)->toBeFalse();
    Queue::assertPushed(RecalculateLoyaltyJob::class);

    $this->actingAs($this->admin)->get('/settings/loyalty-programs')->assertRedirect('/settings/loyalty');
    $this->actingAs($this->admin)->get('/settings/loyalty')->assertInertia(fn ($page) => $page->component('Settings/LoyaltyPrograms'));
    $this->actingAs($this->admin)->put("/settings/loyalty-programs/{$program->id}", [...$payload, 'name' => 'Été 2026'])->assertSessionHasNoErrors();
    expect($program->fresh()->name)->toBe('Été 2026');
});

it('expires cards with the daily command', function () {
    ($this->buy)($this->adidasGlass, 500000, '2026-02-01');
    ($this->recalculate)();
    $this->travelTo(Carbon::parse('2027-02-02 03:00'));

    $this->artisan('fidelite:recalculer', ['--organization' => $this->organization->id])->assertSuccessful();

    expect(($this->card)($this->adidas)->status)->toBe('expired')
        ->and(($this->card)($this->adidas)->points)->toBe(0);
});

it('leaves companies without a programme untouched', function () {
    Queue::fake();
    LoyaltyEngine::queue(Organization::factory()->create()->id, [1]);

    Queue::assertNothingPushed();
});
