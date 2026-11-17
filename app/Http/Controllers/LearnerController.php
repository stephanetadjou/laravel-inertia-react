<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\LearnerExam;
use App\Models\LearnerTraining;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\Training;
use App\Models\User;
use App\Services\Audit;
use App\Services\Learners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Suivi des apprenants (module « formation ») : liste des parcours avec
 * heures faites, progression, prochain examen et alerte de décrochage ;
 * inscription à une formation ; détail d'un parcours (leçons, présences,
 * examens).
 */
class LearnerController extends Controller
{
    public function __construct(private Learners $learners) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...array_keys(LearnerTraining::STATUSES), 'all'])],
            'training' => ['nullable', 'integer'],
            'staff' => ['nullable', 'integer'],
            'alert' => ['nullable', 'boolean'],
            'exam' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? LearnerTraining::STATUS_ACTIVE;

        $learners = LearnerTraining::where('organization_id', $user->organization_id)
            ->whereIn('store_id', $storeIds)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters['training'] ?? null, fn ($q, $id) => $q->where('training_id', $id))
            ->when($filters['staff'] ?? null, fn ($q, $id) => $q->where('staff_member_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$text}%")->orWhere('phone_e164', 'like', '%'.preg_replace('/\D+/', '', $text).'%')))
            ->with(['training', 'customer:id,name,phone_e164,responsible_id', 'staffMember:id,name', 'store:id,name'])
            ->orderByDesc('started_on')
            ->limit(500)
            ->get();
        $summaries = $this->learners->summaries($learners, $user->organization);

        $rows = $learners->map(fn (LearnerTraining $l) => self::present($l, $summaries[$l->id]))
            ->when($filters['alert'] ?? false, fn ($c) => $c->filter(fn ($r) => $r['alert'] !== null))
            ->when($filters['exam'] ?? false, fn ($c) => $c->filter(fn ($r) => $r['next_exam'] !== null)->sortBy(fn ($r) => $r['next_exam']['date']))
            ->values();

        $active = LearnerTraining::where('organization_id', $user->organization_id)->whereIn('store_id', $storeIds)->active();

        return Inertia::render('Learners/Index', [
            'learners' => $rows,
            'filters' => ['status' => $status, 'training' => $filters['training'] ?? null, 'staff' => $filters['staff'] ?? null, 'alert' => (bool) ($filters['alert'] ?? false), 'exam' => (bool) ($filters['exam'] ?? false), 'q' => $filters['q'] ?? ''],
            'stats' => [
                'active' => (clone $active)->count(),
                'alerts' => $status === LearnerTraining::STATUS_ACTIVE ? $rows->whereNotNull('alert')->count() : null,
                'exams' => LearnerExam::where('organization_id', $user->organization_id)->where('result', 'scheduled')
                    ->whereHas('learnerTraining', fn ($q) => $q->whereIn('store_id', $storeIds))
                    ->whereBetween('exam_on', [today()->toDateString(), today()->addDays(30)->toDateString()])->count(),
                'completed' => LearnerTraining::where('organization_id', $user->organization_id)->whereIn('store_id', $storeIds)->where('status', 'completed')
                    ->where('ended_on', '>=', today()->subDays(90)->toDateString())->count(),
            ],
            'statuses' => LearnerTraining::STATUSES,
            ...self::formOptions($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('organization_id', $user->organization_id)],
            'training_id' => ['required', 'integer', Rule::exists('trainings', 'id')->where('organization_id', $user->organization_id)],
            'store_id' => ['required', 'integer', Rule::in(PlanController::storeIds($user))],
            'started_on' => ['required', 'date'],
            'target_on' => ['nullable', 'date', 'after:started_on'],
            'staff_member_id' => ['nullable', 'integer', Rule::in(self::staff($user)->pluck('id'))],
            'extra_hours' => ['nullable', 'integer', 'min:0', 'max:500'],
            'with_plan' => ['nullable', 'boolean'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'customer_id.required' => 'Choisissez l’apprenant.',
            'target_on.after' => 'L’objectif doit suivre le début.',
        ]);

        $learner = $this->learners->enroll($user, $data);
        $name = $learner->customer->name;

        return redirect("/learners/{$learner->id}")->with('success', "{$name} est inscrit(e) à « {$learner->training->name} ».");
    }

    public function show(Request $request, LearnerTraining $learner): Response
    {
        $user = $request->user();
        $this->authorizeLearner($request, $learner);
        $learner->load(['training', 'customer', 'staffMember:id,name', 'store:id,name', 'subscription.plan:id,name']);
        $summary = $this->learners->summaries(collect([$learner]), $user->organization)[$learner->id];

        $lessons = Appointment::where('learner_training_id', $learner->id)
            ->with(['staffMember:id,name', 'resource:id,name,reference'])
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'starts_at' => $a->starts_at->format('Y-m-d\TH:i'),
                'duration_minutes' => $a->duration_minutes,
                'service' => $a->service,
                'status' => $a->status,
                'status_label' => $a->statusLabel(),
                'staff' => $a->staffMember?->name,
                'resource' => $a->resource?->label(),
                'notes' => $a->notes,
            ]);

        $schedule = $learner->subscription?->schedule()->with('installments')->first();

        return Inertia::render('Learners/Show', [
            'learner' => [
                ...self::present($learner, $summary),
                'notes' => $learner->notes,
                'end_reason' => $learner->end_reason,
                'ended_on' => $learner->ended_on,
                'extra_hours' => $learner->extra_hours,
                'hours_planned' => $learner->hours_planned,
                'customer_phone' => $learner->customer->recipient()->phone_e164,
                'subscription' => $learner->subscription ? [
                    'plan' => $learner->subscription->plan?->name,
                    'total' => $schedule ? (int) $schedule->installments->sum('amount') : 0,
                    'paid' => $schedule ? (int) $schedule->installments->whereNotNull('paid_at')->sum('amount') : 0,
                    'overdue' => $schedule ? $schedule->installments->filter(fn ($i) => $i->status() === Installment::STATUS_OVERDUE)->count() : 0,
                    'schedule_id' => $schedule?->id,
                ] : null,
            ],
            'lessons' => $lessons,
            'exams' => $learner->exams->map(fn (LearnerExam $e) => self::presentExam($e))->values(),
            'examTypes' => $learner->training->examTypes(),
            'results' => LearnerExam::RESULTS,
            'statuses' => LearnerTraining::STATUSES,
            'staff' => self::staff($user)->map(fn (StaffMember $s) => ['id' => $s->id, 'name' => $s->name])->values(),
        ]);
    }

    public function update(Request $request, LearnerTraining $learner): RedirectResponse
    {
        $this->authorizeLearner($request, $learner);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(LearnerTraining::STATUSES))],
            'staff_member_id' => ['nullable', 'integer', Rule::in(self::staff($request->user())->pluck('id'))],
            'extra_hours' => ['nullable', 'integer', 'min:0', 'max:500'],
            'target_on' => ['nullable', 'date'],
            'end_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $ending = in_array($data['status'], ['completed', 'dropped'], true);
        $learner->update([
            ...$data,
            'extra_hours' => (int) ($data['extra_hours'] ?? 0),
            'ended_on' => $ending ? ($learner->ended_on ?? today()->toDateString()) : null,
            'end_reason' => $ending ? ($data['end_reason'] ?? $learner->end_reason) : null,
        ]);
        Audit::record('learner.updated', $learner, "Parcours « {$learner->training->name} » : ".$learner->statusLabel(), [], $request->user());

        return back()->with('success', 'Parcours mis à jour.');
    }

    public function storeExam(Request $request, LearnerTraining $learner): RedirectResponse
    {
        $this->authorizeLearner($request, $learner);
        $data = $request->validate([
            'type' => ['required', 'string', 'max:60'],
            'exam_on' => ['required', 'date'],
            'exam_time' => ['nullable', 'date_format:H:i'],
            'result' => ['nullable', Rule::in(array_keys(LearnerExam::RESULTS))],
            'score' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $exam = $this->learners->scheduleExam($learner, $data);

        return back()->with('success', "Examen « {$exam->type} » enregistré (tentative {$exam->attempt}).");
    }

    public function updateExam(Request $request, LearnerExam $exam): RedirectResponse
    {
        $this->authorizeLearner($request, $exam->learnerTraining);
        $data = $request->validate([
            'result' => ['required', Rule::in(array_keys(LearnerExam::RESULTS))],
            'score' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->learners->updateExam($exam, $data);

        return back()->with('success', "Résultat de l’examen « {$exam->type} » : ".$exam->fresh()->resultLabel().'.');
    }

    /**
     * Parcours de la fiche client (onglet « Formation »).
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(Customer $customer, User $user): array
    {
        $learners = LearnerTraining::where('customer_id', $customer->id)
            ->whereIn('store_id', PlanController::storeIds($user))
            ->with(['training', 'staffMember:id,name', 'store:id,name'])
            ->orderByDesc('started_on')
            ->get();
        $summaries = app(Learners::class)->summaries($learners, $user->organization);

        return [
            'trainings' => $learners->map(fn (LearnerTraining $l) => self::present($l, $summaries[$l->id]))->values(),
            'exams' => LearnerExam::whereIn('learner_training_id', $learners->pluck('id'))->orderByDesc('exam_on')->get()->map(fn (LearnerExam $e) => self::presentExam($e))->values(),
            ...self::formOptions($user),
        ];
    }

    /** @return array<string, mixed> */
    public static function formOptions(User $user): array
    {
        return [
            'trainingOptions' => Training::where('organization_id', $user->organization_id)->where('active', true)->orderBy('name')->get()
                ->map(fn (Training $t) => ['id' => $t->id, 'name' => $t->name, 'hours_planned' => $t->hours_planned, 'sessions_planned' => $t->sessions_planned, 'duration_weeks' => $t->duration_weeks, 'has_plan' => $t->plan_id !== null])->values(),
            'stores' => Store::whereIn('id', PlanController::storeIds($user))->orderBy('name')->get(['id', 'name']),
            'staff' => self::staff($user)->map(fn (StaffMember $s) => ['id' => $s->id, 'name' => $s->name, 'store_id' => $s->store_id])->values(),
            'hasPlans' => $user->organization->hasModule('abonnements'),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function present(LearnerTraining $l, array $summary): array
    {
        return [
            'id' => $l->id,
            'customer_id' => $l->customer_id,
            'customer_name' => $l->customer?->name,
            'training_id' => $l->training_id,
            'training' => $l->training?->name,
            'store' => $l->store?->name,
            'staff_member_id' => $l->staff_member_id,
            'staff' => $l->staffMember?->name,
            'started_on' => $l->started_on,
            'target_on' => $l->target_on,
            'status' => $l->status,
            'status_label' => $l->statusLabel(),
            ...$summary,
        ];
    }

    /** @return array<string, mixed> */
    public static function presentExam(LearnerExam $e): array
    {
        return [
            'id' => $e->id,
            'learner_training_id' => $e->learner_training_id,
            'type' => $e->type,
            'exam_on' => $e->exam_on,
            'exam_time' => $e->exam_time,
            'attempt' => $e->attempt,
            'result' => $e->result,
            'result_label' => $e->resultLabel(),
            'score' => $e->score,
            'notes' => $e->notes,
        ];
    }

    /** @return Collection<int, StaffMember> */
    private static function staff(User $user)
    {
        return StaffMember::whereIn('store_id', PlanController::storeIds($user))->where('active', true)->orderBy('name')->get(['id', 'name', 'store_id']);
    }

    private function authorizeLearner(Request $request, LearnerTraining $learner): void
    {
        if ($learner->organization_id !== $request->user()->organization_id || ! in_array($learner->store_id, PlanController::storeIds($request->user()), true)) {
            throw new HttpException(403, 'Ce parcours n’est pas dans votre périmètre.');
        }
    }
}
