<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ProspectStageChange;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'pro', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['rdv', 'catalogue', 'prospects']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Menentia Libreville']);
    $this->director = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $role = Role::create(['organization_id' => $this->organization->id, 'name' => 'Commercial', 'scope' => Role::SCOPE_ORGANIZATION, 'own_records_only' => true]);
    $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role->id, 'name' => 'Alice']);
    $this->bruno = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $role->id, 'name' => 'Bruno']);
});

function newProspect($test, User $by, string $name, string $phone, array $extra = []): Customer
{
    $test->actingAs($by)->post('/prospects', ['name' => $name, 'phone' => $phone, 'store_id' => $test->store->id, ...$extra])->assertSessionHas('success');

    return Customer::where('name', $name)->sole();
}

it('asks at sign-up whether the company prospects, with the stages of its sector', function () {
    $this->post('/register', [
        'organization_name' => 'Agence Digitale', 'sector' => 'pro', 'store_name' => 'Agence', 'name' => 'DG',
        'email' => 'dg@agence.test', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
        'answers' => ['prospects' => true],
    ]);

    $organization = Organization::where('name', 'Agence Digitale')->sole();
    expect($organization->hasModule('prospects'))->toBeTrue()
        ->and(collect($organization->moduleSettings('prospects')['stages'])->pluck('label')->all())->toBe(['Prospect', 'Visité', 'Démo faite', 'En essai', 'Client', 'Perdu']);
});

it('moves prospects through stages, automatically after a demo and the first sale', function () {
    $prospect = newProspect($this, $this->alice, 'Institut Awa', '077 11 22 33', ['company_name' => 'Institut Awa']);
    expect($prospect->prospect_stage)->toBe('prospect')->and($prospect->owner_user_id)->toBe($this->alice->id);

    // Démo honorée dans l'agenda → « Démo faite ».
    $demo = Appointment::create(['store_id' => $this->store->id, 'customer_id' => $prospect->id, 'starts_at' => now()->addHour(), 'duration_minutes' => 45, 'status' => 'planned', 'kind' => 'demo', 'created_by' => $this->alice->id]);
    $demo->update(['status' => 'done']);
    expect($prospect->fresh()->prospect_stage)->toBe('demo');

    // Perdu sans raison : refusé ; puis la première vente le fait passer client.
    $this->actingAs($this->alice)->post("/prospects/{$prospect->id}/stage", ['stage' => 'perdu'])->assertSessionHasErrors('lost_reason');
    Purchase::create(['contact_id' => $prospect->contacts()->first()->id, 'store_id' => $this->store->id, 'amount' => 25000, 'purchased_at' => now()]);
    expect($prospect->fresh()->prospect_stage)->toBe('client')
        ->and(ProspectStageChange::where('customer_id', $prospect->id)->pluck('to_stage')->all())->toBe(['prospect', 'demo', 'client']);
});

it('lists late prospects in the actions of the day, only to their sales rep', function () {
    $prospect = newProspect($this, $this->alice, 'Garage Ndong', '077 44 55 66');
    newProspect($this, $this->bruno, 'Salle Fitness Akanda', '066 12 34 56');

    expect((new ActionLists($this->alice))->rows('prospect_followup'))->toBeEmpty();

    $this->travelTo(now()->addDays(4));
    $rows = (new ActionLists($this->alice->fresh()))->rows('prospect_followup');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Garage Ndong')
        ->and($rows[0]['reason'])->toContain('« Prospect » depuis 4 j');
    expect((new ActionLists($this->director))->rows('prospect_followup'))->toHaveCount(2);

    $this->actingAs($this->alice)->post('/actions/done', ['action' => 'prospect_followup', 'customer_id' => $prospect->id]);
    expect((new ActionLists($this->alice->fresh()))->rows('prospect_followup'))->toBeEmpty();
});

it('shows a sales rep only their own prospects and records', function () {
    newProspect($this, $this->alice, 'Institut Awa', '077 11 22 33');
    $bruno = newProspect($this, $this->bruno, 'Cabinet Obame', '066 98 76 54');

    $this->actingAs($this->alice)->get('/prospects')->assertInertia(fn (Assert $page) => $page
        ->has('prospects', 1)->where('prospects.0.name', 'Institut Awa')->where('owners', []));
    $this->actingAs($this->alice)->get("/customers/{$bruno->id}")->assertForbidden();
    $this->actingAs($this->alice)->get('/customers')->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));
    $this->actingAs($this->director)->get('/prospects?view=list')->assertInertia(fn (Assert $page) => $page->has('prospects', 2));
});

it('assigns a prospect from a sales rep code and lets the director rename the stages', function () {
    $code = $this->bruno->referralCode();
    $prospect = newProspect($this, $this->director, 'Club Judo Owendo', '077 00 99 88', ['referral_code' => strtolower($code)]);
    expect($prospect->owner_user_id)->toBe($this->bruno->id);

    $this->actingAs($this->director)->put('/settings/prospects', [
        'stages' => [
            ['key' => 'prospect', 'label' => 'À appeler', 'kind' => 'a_contacter', 'delay_days' => 1],
            ['key' => '', 'label' => 'Devis envoyé', 'kind' => 'en_discussion', 'delay_days' => 4],
            ['key' => 'client', 'label' => 'Client', 'kind' => 'gagne', 'delay_days' => 0],
            ['key' => 'perdu', 'label' => 'Perdu', 'kind' => 'perdu', 'delay_days' => 0],
        ],
        'commission_rate' => 50,
    ])->assertSessionHas('success');

    $this->actingAs($this->director)->get('/prospects')->assertInertia(fn (Assert $page) => $page
        ->where('stages.0.label', 'À appeler')
        ->where('stages.1.key', 'devis_envoye'));
});
