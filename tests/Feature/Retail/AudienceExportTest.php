<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BrandMetrics;
use App\Services\CustomerScoring;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'commerce', 'loyalty_settings' => ['enabled' => true, 'fcfa_per_point' => 1000]]);
    Role::seedDefaultsFor($this->organization);
    $this->brandA = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alpha']);
    $this->brandB = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Beta']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandA->id, 'name' => 'Alpha Centre']);
    $this->storeB = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id, 'name' => 'Beta Gare']);
    $role = fn (string $name) => Role::where('organization_id', $this->organization->id)->where('name', $name)->value('id');
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Admin groupe')]);
    $this->managerA = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Responsable de marque'), 'brand_id' => $this->brandA->id]);
    $this->operator = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role('Opérateur d’import')]);

    $buy = function (string $phone, string $name, Store $store, int $amount, int $daysAgo) {
        $contact = Contact::where('store_id', $store->id)->where('phone_e164', $phone)->first()
            ?? Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => $phone, 'name' => $name, 'consent_status' => 'opt_in']);
        Purchase::create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => $amount, 'purchased_at' => today()->subDays($daysAgo)]);
    };

    // Awa : Alpha et Beta, ancienne cliente ; Jean : nouveau client d'Alpha ce mois-ci ; Paul : Beta seulement.
    $buy('24162605054', 'Awa Ndong', $this->storeA, 50000, 200);
    $buy('24162605054', 'Awa Ndong', $this->storeB, 30000, 20);
    $buy('24174000000', 'Jean Mba', $this->storeA, 8000, 10);
    $buy('24166000000', 'Paul Obame', $this->storeB, 12000, 15);

    app(CustomerScoring::class)->refreshOrganization($this->organization);
    app(BrandMetrics::class)->refreshOrganization($this->organization);
});

it('exports the customers behind an analysis figure, with the relaunch columns, and audits it', function () {
    $from = today()->subDays(29)->toDateString();
    $to = today()->toDateString();

    // Nouveaux clients de la période (cohorte / chiffre clé « Nouveaux clients »).
    $csv = $this->actingAs($this->admin)->get("/audiences/export?first_from={$from}&first_to={$to}&label=Nouveaux")->assertOk()->streamedContent();
    expect($csv)->toContain('Nom;Type;Téléphone;"Code client";Marques;"Point de vente principal";"Segment RFM"')
        ->toContain('Jean Mba')->toContain('Paul Obame')->not->toContain('Awa Ndong');

    // Cellule de la matrice multimarque : clients d'Alpha qui achètent aussi chez Beta.
    $csv = $this->actingAs($this->admin)->get("/audiences/export?brand={$this->brandA->id}&with_brand={$this->brandB->id}")->streamedContent();
    expect($csv)->toContain('Awa Ndong')->toContain('"Alpha Centre"')->not->toContain('Jean Mba');

    // Clients ayant acheté sur la période, en Excel.
    $this->actingAs($this->admin)->get("/audiences/export?bought_from={$from}&bought_to={$to}&format=xlsx")->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(AuditLog::where('action', 'export')->count())->toBe(3)
        ->and(AuditLog::where('action', 'export')->first()->description)->toContain('Nouveaux');
});

it('keeps exports inside the perimeter and refuses roles without export right', function () {
    $csv = $this->actingAs($this->managerA)->get("/audiences/export?brand={$this->brandB->id}")->streamedContent();
    expect($csv)->not->toContain('Paul Obame')->toContain('Jean Mba');

    $this->actingAs($this->operator)->get('/audiences/export')->assertForbidden();
    $this->actingAs($this->operator)->get('/actions/export?list=at_risk')->assertForbidden();
});

it('creates a group from an analysis and opens it in the day actions with whatsapp links', function () {
    $this->actingAs($this->admin)->post('/audiences/group', ['name' => 'Multimarques', 'filters' => ['brands' => 'multi']])
        ->assertRedirect();

    $group = Group::where('name', 'Multimarques')->sole();
    expect($group->contacts()->distinct()->pluck('customer_id')->count())->toBe(1);

    $response = $this->actingAs($this->admin)->get("/actions?list=group&group={$group->id}")->assertOk();
    $rows = collect($response->viewData('page')['props']['rows']);
    expect($rows->pluck('name')->all())->toBe(['Awa Ndong'])
        ->and($rows->first()['whatsapp_url'])->toStartWith('https://wa.me/24162605054');

    $csv = $this->actingAs($this->admin)->get("/actions/export?list=group&group={$group->id}")->assertOk()->streamedContent();
    expect($csv)->toContain('Awa Ndong')->toContain('https://wa.me/');
});

it('accepts several RFM segments at once and ranges a day action list into a group', function () {
    $segments = Customer::pluck('rfm_segment')->unique()->implode(',');
    $this->actingAs($this->admin)->get('/customers?segment='.$segments)->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.total', 3));

    // Liste vide : rien n'est créé.
    $this->actingAs($this->admin)->post('/actions/group', ['list' => 'cross_sell', 'brand' => $this->brandA->id, 'name' => 'Découvrir Beta'])
        ->assertSessionHas('error');
    expect(Group::where('name', 'Découvrir Beta')->exists())->toBeFalse();

    $this->actingAs($this->admin)->post('/actions/group', ['list' => 'second_purchase', 'name' => 'Deuxième achat'])->assertRedirect();
});
