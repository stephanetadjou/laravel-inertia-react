<?php

use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupClass;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\GroupClasses;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Lundi 12 octobre 2026.
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(8, 0));
    $this->organization = Organization::factory()->create(['sector' => 'sport', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['seances_groupe', 'beneficiaires']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Akaé Beach']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->parent = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Mireille Mba', 'consent_status' => 'opt_in'])->customer;
    $this->actingAs($this->admin)->post("/customers/{$this->parent->id}/beneficiaries", ['name' => 'Léo Mba', 'relation' => 'parent']);
    $this->leo = Customer::where('name', 'Léo Mba')->firstOrFail();
    // Football le vendredi, depuis le 2 octobre.
    $this->class = GroupClass::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Football', 'weekday' => 5, 'start_time' => '15:00', 'end_time' => '16:30', 'capacity' => 20, 'starts_on' => '2026-10-02']);
    app(GroupClasses::class)->enroll($this->class, $this->leo, null, Carbon::parse('2026-10-01'));
    app(GroupClasses::class)->ensureSessions($this->organization->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01'));
});

it('cancels a session for bad weather and lists the parents to warn', function () {
    $session = ClassSession::where('session_date', '2026-10-16')->firstOrFail();

    $this->actingAs($this->admin)->post("/class-sessions/{$session->id}/cancel", ['reason' => 'weather', 'note' => 'terrain inondé'])->assertSessionHas('success');
    expect($session->fresh()->status)->toBe('cancelled')->and($session->fresh()->cancel_reason)->toBe('weather');

    $rows = (new ActionLists($this->admin))->rows('class_cancelled');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Léo Mba')
        ->and($rows[0]['recipient_name'])->toBe('Mireille Mba')
        ->and($rows[0]['message'])->toContain('Football')->toContain('vendredi 16 octobre')->toContain('intempérie');

    // Prévenu une fois : il sort de la liste.
    $this->actingAs($this->admin)->post('/actions/done', ['action' => 'class_cancelled', 'customer_id' => $this->leo->id, 'reference' => $rows[0]['reference']]);
    expect((new ActionLists($this->admin))->rows('class_cancelled'))->toBeEmpty();

    // Pas d'appel sur une séance annulée.
    $this->actingAs($this->admin)->post("/class-sessions/{$session->id}/attendance", ['marks' => [$this->leo->id => 'present']])->assertSessionHasErrors('marks');
});

it('prepares a campaign group when the WhatsApp API is on', function () {
    $this->organization->update(['whatsapp_api_enabled' => true]);
    $this->admin->unsetRelation('organization');
    $session = ClassSession::where('session_date', '2026-10-16')->firstOrFail();

    $this->actingAs($this->admin)->post("/class-sessions/{$session->id}/cancel", ['reason' => 'weather']);

    $group = Group::where('name', 'Annulation Football 2026-10-16')->firstOrFail();
    expect($group->contacts()->pluck('customer_id')->all())->toBe([$this->parent->id]);
});

it('proposes a makeup on the chosen day after two cancellations and announces it', function () {
    $first = ClassSession::where('session_date', '2026-10-16')->firstOrFail();
    $second = ClassSession::where('session_date', '2026-10-23')->firstOrFail();

    $this->actingAs($this->admin)->post("/class-sessions/{$first->id}/cancel", ['reason' => 'weather']);
    $this->actingAs($this->admin)->get("/classes/{$this->class->id}")->assertInertia(fn (Assert $page) => $page->where('makeup', null));

    $this->actingAs($this->admin)->post("/class-sessions/{$second->id}/cancel", ['reason' => 'weather']);
    // Samedi suivant (jour de rattrapage par défaut).
    $this->actingAs($this->admin)->get("/classes/{$this->class->id}")->assertInertia(fn (Assert $page) => $page
        ->where('makeup.cancelled', 2)
        ->where('makeup.date', '2026-10-17'));

    $this->actingAs($this->admin)->post("/classes/{$this->class->id}/makeup", ['date' => '2026-10-24'])->assertSessionHas('success');
    $makeup = ClassSession::whereNotNull('makeup_of_id')->firstOrFail();
    expect($makeup->session_date)->toBe('2026-10-24')->and($makeup->makeup_of_id)->toBe($second->id);

    $rows = (new ActionLists($this->admin))->rows('class_makeup');
    expect($rows)->toHaveCount(1)->and($rows[0]['message'])->toContain('rattrapé le samedi 24 octobre à 15h00');

    $this->actingAs($this->admin)->get("/classes/{$this->class->id}")->assertInertia(fn (Assert $page) => $page->where('makeup', null));
});
