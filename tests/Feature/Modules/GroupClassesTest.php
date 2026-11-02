<?php

use App\Models\Attendance;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\LoyaltyTransaction;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Mercredi 7 octobre 2026.
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'sport', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['seances_groupe', 'abonnements', 'tranches', 'beneficiaires', 'fidelite'], ['fidelite' => ['points_per_attendance' => 5]]);
    $this->organization->loyalty_settings = ['enabled' => true, 'fcfa_per_point' => 1000, 'starts_on' => '2026-01-01'];
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Sacré-Cœur']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->coachUser = User::factory()->create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id]);
    $this->coach = StaffMember::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'user_id' => $this->coachUser->id, 'name' => 'Coach Serge', 'active' => true]);
    $this->parent = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Mireille Mba', 'consent_status' => 'opt_in'])->customer;
    $this->actingAs($this->admin)->post("/customers/{$this->parent->id}/beneficiaries", ['name' => 'Léo Mba', 'relation' => 'parent', 'birthdate' => '2017-03-01', 'health_note' => 'Asthme']);
    $this->leo = Customer::where('name', 'Léo Mba')->firstOrFail();
});

function judoClass($test): GroupClass
{
    $test->actingAs($test->admin)->post('/classes', [
        'name' => 'Judo 6-9 ans', 'store_id' => $test->store->id, 'staff_member_id' => $test->coach->id,
        'weekday' => 6, 'start_time' => '10:30', 'end_time' => '11:30',
    ])->assertRedirect();

    return GroupClass::firstOrFail();
}

it('creates a weekly class whose sessions appear in the agenda', function () {
    $class = judoClass($this);
    expect($class->capacity)->toBe(20)->and($class->scheduleLabel())->toBe('Samedi 10h30 – 11h30');

    $this->actingAs($this->admin)->get('/appointments?view=week&date=2026-10-07')->assertInertia(fn (Assert $page) => $page
        ->where('rdvEnabled', false)
        ->has('appointments', 0)
        ->has('classSessions', 1)
        ->where('classSessions.0.date', '2026-10-10')
        ->where('classSessions.0.name', 'Judo 6-9 ans'));

    $this->actingAs($this->admin)->get('/classes')->assertInertia(fn (Assert $page) => $page->component('Classes/Index')->has('classes', 1)->has('week', 1));
});

it('enrolls a child, takes the roll call and counts presences on the card', function () {
    $class = judoClass($this);
    $card = Plan::create(['organization_id' => $this->organization->id, 'name' => 'Carte 10 séances', 'price' => 25000, 'period' => 'sessions', 'sessions_count' => 10]);
    $this->actingAs($this->admin)->post('/subscriptions', ['customer_id' => $this->leo->id, 'plan_id' => $card->id, 'store_id' => $this->store->id, 'starts_on' => '2026-10-07']);
    $this->actingAs($this->admin)->post("/classes/{$class->id}/enrollments", ['customer_id' => $this->leo->id])->assertSessionHas('success');

    $this->actingAs($this->admin)->get("/classes/{$class->id}");
    $session = ClassSession::where('session_date', '2026-10-10')->firstOrFail();

    // Le coach du cours voit la note de santé pendant l'appel.
    $this->actingAs($this->coachUser)->get("/class-sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Classes/Session')
        ->has('participants', 1)
        ->where('participants.0.health', 'Asthme')
        ->where('participants.0.responsible', 'Mireille Mba'));

    $this->actingAs($this->coachUser)->post("/class-sessions/{$session->id}/attendance", ['marks' => [$this->leo->id => 'present']])->assertSessionHas('success');

    expect(Attendance::where('customer_id', $this->leo->id)->value('status'))->toBe('present')
        ->and(Subscription::first()->sessions_left)->toBe(9)
        ->and(LoyaltyTransaction::where('customer_id', $this->leo->id)->where('type', 'attendance')->sum('points'))->toBe(5);

    // Corriger en absent rend la séance de la carte.
    $this->actingAs($this->admin)->post("/class-sessions/{$session->id}/attendance", ['marks' => [$this->leo->id => 'absent']]);
    expect(Subscription::first()->sessions_left)->toBe(10);

    $this->actingAs($this->admin)->get("/customers/{$this->leo->id}")->assertInertia(fn (Assert $page) => $page
        ->where('attendanceModule.classes.0.name', 'Judo 6-9 ans')
        ->where('attendanceModule.counts.absent', 1));
});

it('refuses enrollments beyond the capacity', function () {
    $class = judoClass($this);
    $class->update(['capacity' => 1]);
    $this->actingAs($this->admin)->post("/classes/{$class->id}/enrollments", ['customer_id' => $this->leo->id])->assertSessionHas('success');
    $this->actingAs($this->admin)->post("/classes/{$class->id}/enrollments", ['customer_id' => $this->parent->id])->assertSessionHasErrors('customer_id');
});

it('keeps classes out of reach without the module', function () {
    $this->organization->setModules(['abonnements']);
    $this->organization->save();
    $this->admin->unsetRelation('organization');

    $this->actingAs($this->admin)->get('/classes')->assertRedirect('/dashboard');
    $this->actingAs($this->admin)->get('/appointments')->assertRedirect('/dashboard');
});
