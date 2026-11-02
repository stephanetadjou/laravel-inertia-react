<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'sport', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['seances_groupe', 'abonnements', 'tranches', 'beneficiaires', 'catalogue']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Club Akaé']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->staff = User::factory()->create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id]);
    $this->parent = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Mireille Mba', 'consent_status' => 'opt_in'])->customer;
});

function addChild($test, string $name, array $extra = [])
{
    return $test->actingAs($test->admin)->post("/customers/{$test->parent->id}/beneficiaries", [
        'name' => $name, 'birthdate' => '2017-03-12', 'relation' => 'parent', 'gender' => 'H', ...$extra,
    ]);
}

it('adds children without a phone number, each with their own customer record', function () {
    addChild($this, 'Léo Mba', ['health_note' => 'Asthme : ventoline dans le sac'])->assertSessionHas('success');
    addChild($this, 'Inès Mba', ['gender' => 'F'])->assertSessionHas('success');

    $children = Customer::where('responsible_id', $this->parent->id)->orderBy('name')->get();
    expect($children)->toHaveCount(2)
        ->and($children->pluck('phone_e164')->all())->toBe([null, null])
        ->and($children->first()->contacts()->first()->store_id)->toBe($this->store->id)
        ->and(Customer::where('name', 'Léo Mba')->first()->health_note)->toBe('Asthme : ventoline dans le sac')
        ->and(Customer::where('name', 'Léo Mba')->first()->isMinor())->toBeTrue();

    // Fiche du responsable : onglet « Personnes suivies ».
    $this->actingAs($this->admin)->get("/customers/{$this->parent->id}")->assertInertia(fn (Assert $page) => $page
        ->has('beneficiaries', 2)
        ->where('beneficiaryModule.label', 'Adhérent'));

    // Fiche de l'enfant : responsable affiché, WhatsApp vers le parent.
    $leo = Customer::where('name', 'Léo Mba')->first();
    $this->actingAs($this->admin)->get("/customers/{$leo->id}")->assertInertia(fn (Assert $page) => $page
        ->where('responsible.name', 'Mireille Mba')
        ->where('responsible.relation', 'Parent')
        ->where('healthNote.visible', true)
        ->where('whatsappUrl', 'https://wa.me/24177001122?text='));
});

it('restricts the health note to managers and the coach', function () {
    addChild($this, 'Léo Mba', ['health_note' => 'Allergie arachide']);
    $leo = Customer::where('name', 'Léo Mba')->first();

    $this->actingAs($this->staff)->get("/customers/{$leo->id}")->assertInertia(fn (Assert $page) => $page
        ->where('healthNote.visible', false)
        ->where('healthNote.text', null)
        ->where('healthNote.has_note', true));
    $this->actingAs($this->staff)->put("/customers/{$leo->id}/health-note", ['health_note' => 'x'])->assertForbidden();
    $this->actingAs($this->admin)->put("/customers/{$leo->id}/health-note", ['health_note' => 'Allergie arachide et lactose'])->assertSessionHas('success');

    expect($leo->fresh()->health_note)->toBe('Allergie arachide et lactose');
});

it('keeps a responsible for minors and for people without a number', function () {
    addChild($this, 'Léo Mba');
    $leo = Customer::where('name', 'Léo Mba')->first();

    $this->actingAs($this->admin)->put("/customers/{$leo->id}/responsible", ['responsible_id' => null])->assertSessionHasErrors('responsible_id');

    $other = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24166009988', 'name' => 'Paul Mba'])->customer;
    $this->actingAs($this->admin)->put("/customers/{$leo->id}/responsible", ['responsible_id' => $other->id, 'relation' => 'tuteur'])->assertSessionHas('success');
    expect($leo->fresh()->responsible_id)->toBe($other->id);
});

it('sends follow-ups of a followed person to the responsible', function () {
    addChild($this, 'Léo Mba');
    $leo = Customer::where('name', 'Léo Mba')->first();
    Customer::whereKey($leo->id)->update(['churn_risk' => 'high', 'lifecycle_stage' => 'at_risk', 'last_purchase_at' => now()->subDays(40)]);

    $rows = (new ActionLists($this->admin))->rows('at_risk');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Léo Mba')
        ->and($rows[0]['recipient_name'])->toBe('Mireille Mba')
        ->and($rows[0]['phone_e164'])->toBe('24177001122')
        ->and($rows[0]['message'])->toStartWith('Bonjour Mireille');
});

it('lets the fiche of a person without a number be edited', function () {
    addChild($this, 'Léo Mba');
    $leo = Customer::where('name', 'Léo Mba')->first();

    $this->actingAs($this->admin)->put("/customers/{$leo->id}", ['name' => 'Léo Mba Ondo', 'phone_e164' => '', 'birthdate' => '2017-03-12'])->assertSessionHas('success');

    expect($leo->fresh()->name)->toBe('Léo Mba Ondo')->and($leo->fresh()->phone_e164)->toBeNull();
});

it('hides people followed when the module is off', function () {
    $this->organization->setModules(['seances_groupe']);
    $this->organization->save();

    addChild($this, 'Léo Mba')->assertRedirect('/dashboard');
    $this->actingAs($this->admin)->get("/customers/{$this->parent->id}")->assertInertia(fn (Assert $page) => $page->where('beneficiaryModule', null));
});
