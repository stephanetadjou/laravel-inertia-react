<?php

use App\Models\Appointment;
use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Contact;
use App\Models\GroupClass;
use App\Models\Installment;
use App\Models\LearnerTraining;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\Training;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\CustomerSearch;
use App\Services\GroupClasses;
use App\Services\Learners;
use Database\Seeders\AutoEcoleDemoSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Mardi 10 novembre 2026.
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['sector' => 'formation', 'whatsapp_api_enabled' => false]);
    $this->organization->setModules(['formation', 'rdv', 'seances_groupe', 'abonnements', 'tranches', 'ressources']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->instructor = StaffMember::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Jean-Pierre', 'active' => true]);
    $this->plan = Plan::create(['organization_id' => $this->organization->id, 'name' => 'Forfait Permis B', 'price' => 300000, 'period' => 'season', 'fees' => [['label' => 'Inscription', 'amount' => 25000]], 'options' => [], 'active' => true]);
    $this->training = Training::create(['organization_id' => $this->organization->id, 'name' => 'Permis B', 'hours_planned' => 20, 'sessions_planned' => 4, 'duration_weeks' => 16, 'exams' => ['Code', 'Conduite'], 'plan_id' => $this->plan->id, 'active' => true]);
    $this->learner = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001122', 'name' => 'Grâce Mba', 'consent_status' => 'opt_in'])->customer;
});

function enrollGrace($test, string $start = '2026-10-01', array $extra = []): LearnerTraining
{
    $test->actingAs($test->admin)->post('/learners', [
        'customer_id' => $test->learner->id, 'training_id' => $test->training->id, 'store_id' => $test->store->id,
        'started_on' => $start, 'staff_member_id' => $test->instructor->id, 'with_plan' => true, 'installments' => 3, ...$extra,
    ])->assertSessionHasNoErrors();

    return LearnerTraining::where('customer_id', $test->learner->id)->latest('id')->firstOrFail();
}

function lessonFor($test, string $at, string $status = 'done', array $extra = []): Appointment
{
    return Appointment::create(['store_id' => $test->store->id, 'customer_id' => $test->learner->id, 'starts_at' => Carbon::parse($at), 'duration_minutes' => 90, 'service' => 'Leçon de conduite', 'status' => $status, ...$extra]);
}

it('enrolls a learner with the training plan paid in three installments', function () {
    $learner = enrollGrace($this);

    expect($learner->hours_planned)->toBe(20)
        ->and($learner->target_on)->toBe('2027-01-21')
        ->and($learner->subscription_id)->not->toBeNull();

    $amounts = Installment::where('payment_schedule_id', $learner->subscription->payment_schedule_id)->orderBy('due_on')->orderBy('id')->pluck('amount')->all();
    expect($amounts)->toBe([25000, 100000, 100000, 100000]);
});

it('counts hours from honoured lessons and attended classes, never typed by hand', function () {
    $learner = enrollGrace($this);
    // La leçon se rattache seule au parcours en cours.
    lessonFor($this, '2026-10-20 10:00');
    lessonFor($this, '2026-10-27 10:00');
    lessonFor($this, '2026-11-03 10:00', 'no_show');
    lessonFor($this, '2026-11-12 10:00', 'planned');

    $class = GroupClass::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Code lundi', 'weekday' => 1, 'start_time' => '18:00', 'end_time' => '19:00', 'capacity' => 20, 'starts_on' => '2026-10-01', 'training_id' => $this->training->id]);
    ClassEnrollment::create(['group_class_id' => $class->id, 'customer_id' => $this->learner->id, 'enrolled_on' => '2026-10-01']);
    app(GroupClasses::class)->ensureSessions($this->organization->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20'));
    ClassSession::where('group_class_id', $class->id)->get()->each(fn ($s) => app(GroupClasses::class)->mark($s, [$this->learner->id => 'present'], $this->admin));

    $summary = app(Learners::class)->summaries(collect([$learner->fresh('training')]), $this->organization)[$learner->id];

    expect(Appointment::where('learner_training_id', $learner->id)->count())->toBe(4)
        ->and($summary['hours_done'])->toBe(3.0)
        ->and($summary['lessons_done'])->toBe(2)
        ->and($summary['no_shows'])->toBe(1)
        ->and($summary['sessions_done'])->toBe(3)
        // (3/20 + 3/4) / 2 = 45 %
        ->and($summary['percent'])->toBe(45)
        ->and($summary['next_lesson'])->toBe('2026-11-12T10:00')
        ->and($summary['alert'])->toBeNull();
});

it('flags a learner who stops coming and lists them in the actions of the day', function () {
    $learner = enrollGrace($this, '2026-09-01');
    lessonFor($this, '2026-10-05 10:00');

    $summary = app(Learners::class)->summaries(collect([$learner->fresh('training')]), $this->organization)[$learner->id];
    expect($summary['alert'])->toBe('Aucune leçon depuis 36 jours');

    $rows = (new ActionLists($this->admin))->rows('learners_dropping');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Grâce Mba')
        ->and($rows[0]['reason'])->toContain('Permis B')
        ->and($rows[0]['message'])->toContain('« Permis B »');

    // Une leçon réservée : plus « aucune leçon », mais toujours en retard sur le rythme.
    lessonFor($this, '2026-11-13 10:00', 'confirmed');
    expect((new ActionLists($this->admin))->rows('learners_dropping')[0]['reason'])->toStartWith('En retard');
});

it('flags a learner behind the expected pace', function () {
    $learner = enrollGrace($this, '2026-08-01', ['target_on' => '2026-11-20']);
    lessonFor($this, '2026-11-05 10:00');

    $summary = app(Learners::class)->summaries(collect([$learner->fresh('training')]), $this->organization)[$learner->id];
    expect($summary['alert'])->toStartWith('En retard');
});

it('counts exam attempts and completes the training when every exam is passed', function () {
    $learner = enrollGrace($this);

    $this->post("/learners/{$learner->id}/exams", ['type' => 'Code', 'exam_on' => '2026-10-20', 'result' => 'passed'])->assertSessionHasNoErrors();
    $this->post("/learners/{$learner->id}/exams", ['type' => 'Conduite', 'exam_on' => '2026-11-03', 'result' => 'failed']);
    $this->post("/learners/{$learner->id}/exams", ['type' => 'Conduite', 'exam_on' => '2026-11-24']);

    $retake = $learner->exams()->reorder()->where('type', 'Conduite')->latest('id')->first();
    expect($retake->attempt)->toBe(2)->and($learner->fresh()->status)->toBe('active');

    $this->put("/learner-exams/{$retake->id}", ['result' => 'passed'])->assertSessionHasNoErrors();
    expect($learner->fresh()->status)->toBe('completed')->and($learner->fresh()->ended_on)->toBe('2026-11-24');
});

it('refuses a vehicle already booked on an overlapping slot', function () {
    $car = BookableResource::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Peugeot 208', 'kind' => 'vehicule', 'active' => true]);
    lessonFor($this, '2026-11-12 10:00', 'planned', ['resource_id' => $car->id]);
    $other = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177003344', 'name' => 'Paul Nze'])->customer;

    $this->actingAs($this->admin)->post('/appointments', ['store_id' => $this->store->id, 'customer_id' => $other->id, 'starts_at' => '2026-11-12 11:00', 'duration_minutes' => 60, 'resource_id' => $car->id])
        ->assertSessionHasErrors('resource_id');
    $this->post('/appointments', ['store_id' => $this->store->id, 'customer_id' => $other->id, 'starts_at' => '2026-11-12 11:30', 'duration_minutes' => 60, 'resource_id' => $car->id])
        ->assertSessionHasNoErrors();
});

it('shows the learners pages and the training tab on the customer file', function () {
    $learner = enrollGrace($this);

    $this->get('/learners')->assertInertia(fn (Assert $page) => $page->component('Learners/Index')->has('learners', 1)->where('learners.0.training', 'Permis B'));
    $this->get("/learners/{$learner->id}")->assertInertia(fn (Assert $page) => $page->component('Learners/Show')->where('examTypes', ['Code', 'Conduite']));
    $this->get('/trainings')->assertInertia(fn (Assert $page) => $page->component('Learners/Trainings')->has('trainings', 1));
    $this->get('/resources')->assertOk();
    $this->get("/customers/{$this->learner->id}")->assertInertia(fn (Assert $page) => $page->has('learnerModule.trainings', 1));
});

it('hides the learner pages when the module is off', function () {
    $this->organization->setModules(['rdv']);
    $this->organization->save();

    $this->actingAs($this->admin)->get('/learners')->assertRedirect();
});

it('loads the driving school demo', function () {
    $this->seed(AutoEcoleDemoSeeder::class);
    $organization = Organization::where('slug', AutoEcoleDemoSeeder::SLUG)->firstOrFail();

    expect($organization->hasModule('formation'))->toBeTrue()
        ->and(LearnerTraining::where('organization_id', $organization->id)->count())->toBe(40)
        ->and(LearnerTraining::where('organization_id', $organization->id)->where('status', 'completed')->count())->toBe(5)
        ->and(app(Learners::class)->dropouts($organization, $organization->stores()->pluck('id')->all())->count())->toBeGreaterThanOrEqual(7);
});

it('never follows up a learner who finished the training, except for money still due', function () {
    $learner = enrollGrace($this, '2026-09-15');
    $this->post("/learners/{$learner->id}/exams", ['type' => 'Code', 'exam_on' => '2026-10-01', 'result' => 'passed']);
    $this->post("/learners/{$learner->id}/exams", ['type' => 'Conduite', 'exam_on' => '2026-11-02', 'result' => 'passed']);
    expect($learner->fresh()->status)->toBe('completed');

    // Vieux client inactif et tranche impayée : seule l'échéance reste à rappeler.
    $this->learner->forceFill(['lifecycle_stage' => 'churned', 'churn_risk' => 'high', 'purchases_count' => 1, 'last_purchase_at' => '2026-09-15'])->save();
    $lists = new ActionLists($this->admin);
    foreach (['at_risk', 'win_back', 'learners_dropping', 'learners_dropped'] as $key) {
        expect(collect($lists->rows($key))->pluck('customer_id'))->not->toContain($this->learner->id);
    }
    expect(collect($lists->rows('installment_due'))->pluck('customer_id'))->toContain($this->learner->id);

    // Ni campagne.
    $search = new CustomerSearch($this->admin, []);
    expect($search->recipients(null)->pluck('contacts.customer_id'))->not->toContain($this->learner->id);

    // Une nouvelle formation commencée : il redevient un apprenant à suivre.
    enrollGrace($this, '2026-11-05', ['with_plan' => false]);
    expect((new CustomerSearch($this->admin, []))->recipients(null)->pluck('contacts.customer_id'))->toContain($this->learner->id);
});

it('proposes to resume the training to learners who dropped out', function () {
    $learner = enrollGrace($this, '2026-08-01', ['with_plan' => false]);
    lessonFor($this, '2026-08-20 10:00');
    $this->put("/learners/{$learner->id}", ['status' => 'dropped', 'end_reason' => 'Manque de temps'])->assertSessionHasNoErrors();

    $rows = (new ActionLists($this->admin))->rows('learners_dropped');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['reason'])->toContain('Manque de temps')
        ->and($rows[0]['message'])->toContain('1.5 h sur 20 h')->toContain('reprendre');
});
