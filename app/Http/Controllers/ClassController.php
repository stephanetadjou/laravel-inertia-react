<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\CatalogItem;
use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\GroupClasses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agenda › Cours (module « seances_groupe ») : cours réguliers, inscrits,
 * séances de la semaine et appel rapide (pensé pour le téléphone du coach).
 */
class ClassController extends Controller
{
    public function index(Request $request, GroupClasses $service): Response
    {
        $user = $request->user();
        $storeIds = PlanController::storeIds($user);
        $monday = today()->startOfWeek();
        $service->ensureSessions($user->organization_id, $monday, $monday->copy()->addWeeks(2));

        $classes = GroupClass::whereIn('store_id', $storeIds)
            ->with(['store:id,name', 'coach:id,name', 'catalogItem:id,name'])
            ->withCount('activeEnrollments')
            ->orderByDesc('active')->orderBy('weekday')->orderBy('start_time')
            ->get();

        return Inertia::render('Classes/Index', [
            'classes' => $classes->map(fn (GroupClass $c) => self::presentClass($c)),
            'week' => ClassSession::whereIn('group_class_id', $classes->pluck('id'))
                ->where('session_date', '>=', $monday->toDateString())
                ->where('session_date', '<', $monday->copy()->addWeek()->toDateString())
                ->with('groupClass:id,name,store_id,capacity')
                ->withCount(['attendances as present_count' => fn ($q) => $q->where('status', 'present')])
                ->orderBy('session_date')->orderBy('start_time')
                ->get()
                ->map(fn (ClassSession $s) => self::presentSession($s)),
            ...$this->formOptions($user),
        ]);
    }

    public function show(Request $request, GroupClass $class, GroupClasses $service): Response
    {
        $user = $request->user();
        $this->authorizeClass($user, $class);
        $service->ensureSessions($user->organization_id, today()->subWeeks(1), today()->addWeeks(4), collect([$class]));
        $class->load(['store:id,name', 'coach:id,name', 'catalogItem:id,name'])->loadCount('activeEnrollments');

        $sessions = $class->sessions()
            ->where('session_date', '<', today()->addWeeks(4)->toDateString())
            ->withCount(['attendances as present_count' => fn ($q) => $q->where('status', 'present')])
            ->orderByDesc('session_date')
            ->take(16)
            ->get();
        $enrollments = $class->activeEnrollments()->with(['customer.responsible:id,name', 'subscription.plan:id,name'])->get();
        $absences = Attendance::whereIn('class_session_id', $class->sessions()->select('id'))
            ->whereIn('customer_id', $enrollments->pluck('customer_id'))
            ->get()
            ->groupBy('customer_id');

        return Inertia::render('Classes/Show', [
            'groupClass' => self::presentClass($class),
            'enrollments' => $enrollments->sortBy(fn ($e) => $e->customer->name)->values()->map(function (ClassEnrollment $e) use ($absences) {
                $marks = $absences->get($e->customer_id, collect());

                return [
                    'id' => $e->id,
                    'customer_id' => $e->customer_id,
                    'name' => $e->customer->name,
                    'age' => $e->customer->age(),
                    'responsible' => $e->customer->responsible?->name,
                    'plan' => $e->subscription?->plan?->name,
                    'present' => $marks->where('status', 'present')->count(),
                    'absent' => $marks->where('status', 'absent')->count(),
                    'since' => $e->enrolled_on,
                ];
            }),
            'sessions' => $sessions->map(fn (ClassSession $s) => self::presentSession($s->setRelation('groupClass', $class))),
            ...$this->formOptions($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $class = GroupClass::create([...$this->validateClass($request), 'organization_id' => $user->organization_id]);

        return redirect()->route('classes.show', $class)->with('success', "Cours « {$class->name} » créé : inscrivez maintenant les participants.");
    }

    public function update(Request $request, GroupClass $class): RedirectResponse
    {
        $this->authorizeClass($request->user(), $class);
        $data = $this->validateClass($request);
        $class->update($data);

        // Séances à venir non annulées : nouvel horaire.
        $class->sessions()->where('session_date', '>=', today()->toDateString())->where('status', ClassSession::STATUS_SCHEDULED)->whereNull('makeup_of_id')
            ->whereDoesntHave('attendances')->delete();

        return back()->with('success', "Cours « {$class->name} » enregistré.");
    }

    public function enroll(Request $request, GroupClass $class, GroupClasses $service): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeClass($user, $class);
        $data = $request->validate(['customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('organization_id', $user->organization_id)]]);
        $customer = Customer::findOrFail($data['customer_id']);

        $service->enroll($class, $customer);

        return back()->with('success', "{$customer->name} est inscrit(e) au cours « {$class->name} ».");
    }

    public function unenroll(Request $request, GroupClass $class, ClassEnrollment $enrollment, GroupClasses $service): RedirectResponse
    {
        $this->authorizeClass($request->user(), $class);
        abort_unless($enrollment->group_class_id === $class->id, 404);
        $service->unenroll($enrollment);

        return back()->with('success', 'Inscription arrêtée.');
    }

    /** Appel d'une séance : liste des inscrits, un geste par personne. */
    public function session(Request $request, ClassSession $session, GroupClasses $service): Response
    {
        $user = $request->user();
        $this->authorizeClass($user, $session->groupClass);
        $marks = $session->attendances()->pluck('status', 'customer_id');

        return Inertia::render('Classes/Session', [
            'session' => self::presentSession($session) + [
                'cancel_reason' => $session->cancel_reason ? ClassSession::CANCEL_REASONS[$session->cancel_reason] ?? $session->cancel_reason : null,
                'cancel_note' => $session->cancel_note,
                'makeup_of' => $session->makeupOf ? $session->makeupOf->label() : null,
            ],
            'participants' => $service->participants($session)->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'age' => $c->age(),
                'responsible' => $c->responsible?->name,
                'status' => $marks[$c->id] ?? null,
                'health' => filled($c->health_note) && $c->healthNoteVisibleTo($user) ? $c->health_note : null,
            ]),
            'statuses' => Attendance::STATUSES,
            'cancelReasons' => ClassSession::CANCEL_REASONS,
        ]);
    }

    public function attendance(Request $request, ClassSession $session, GroupClasses $service): RedirectResponse
    {
        $this->authorizeClass($request->user(), $session->groupClass);
        $data = $request->validate(['marks' => ['required', 'array'], 'marks.*' => [Rule::in(array_keys(Attendance::STATUSES))]]);
        $count = $service->mark($session, $data['marks'], $request->user());

        return back()->with('success', "Appel enregistré ({$count} personne(s)).");
    }

    /** @return array<string, mixed> */
    private function validateClass(Request $request): array
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'store_id' => ['required', 'integer', Rule::in(PlanController::storeIds($user))],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('organization_id', $user->organization_id)],
            'staff_member_id' => ['nullable', 'integer', Rule::exists('staff_members', 'id')->where('organization_id', $user->organization_id)],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'active' => ['nullable', 'boolean'],
        ]);

        return [
            ...$data,
            'capacity' => $data['capacity'] ?? (int) $user->organization->moduleSettings('seances_groupe')['capacity'],
            'starts_on' => isset($data['starts_on']) ? Carbon::parse($data['starts_on'])->toDateString() : today()->toDateString(),
            'ends_on' => isset($data['ends_on']) ? Carbon::parse($data['ends_on'])->toDateString() : null,
            'active' => $data['active'] ?? true,
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(User $user): array
    {
        $storeIds = PlanController::storeIds($user);

        return [
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
            'coaches' => StaffMember::whereIn('store_id', $storeIds)->where('active', true)->orderBy('name')->get(['id', 'name', 'store_id']),
            'activities' => CatalogItem::where('organization_id', $user->organization_id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'weekdays' => GroupClass::WEEKDAYS,
            'defaultCapacity' => (int) $user->organization->moduleSettings('seances_groupe')['capacity'],
        ];
    }

    /** @return array<string, mixed> */
    public static function presentClass(GroupClass $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'store_id' => $c->store_id,
            'store_name' => $c->store?->name,
            'catalog_item_id' => $c->catalog_item_id,
            'activity' => $c->catalogItem?->name,
            'staff_member_id' => $c->staff_member_id,
            'coach' => $c->coach?->name,
            'weekday' => $c->weekday,
            'start_time' => $c->start_time,
            'end_time' => $c->end_time,
            'schedule' => $c->scheduleLabel(),
            'capacity' => $c->capacity,
            'enrolled' => (int) ($c->active_enrollments_count ?? $c->activeEnrollments()->count()),
            'starts_on' => $c->starts_on,
            'ends_on' => $c->ends_on,
            'active' => $c->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function presentSession(ClassSession $s): array
    {
        return [
            'id' => $s->id,
            'class_id' => $s->group_class_id,
            'class_name' => $s->groupClass?->name,
            'date' => $s->session_date,
            'label' => $s->label(),
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'status' => $s->status,
            'is_makeup' => $s->makeup_of_id !== null,
            'present' => (int) ($s->present_count ?? 0),
            'past' => $s->session_date <= today()->toDateString(),
        ];
    }

    /**
     * Onglet « Cours » de la fiche client : cours suivis et dernières présences.
     *
     * @return array<string, mixed>
     */
    public static function forCustomer(Customer $customer): array
    {
        $enrollments = ClassEnrollment::where('customer_id', $customer->id)->with('groupClass.store:id,name')->get();
        $attendances = Attendance::where('customer_id', $customer->id)->with('session.groupClass:id,name')->latest('id')->get();

        return [
            'classes' => $enrollments->map(fn (ClassEnrollment $e) => [
                'id' => $e->group_class_id,
                'name' => $e->groupClass->name,
                'schedule' => $e->groupClass->scheduleLabel(),
                'store' => $e->groupClass->store?->name,
                'active' => $e->ended_on === null,
            ])->values(),
            'counts' => collect(Attendance::STATUSES)->map(fn ($label, $key) => $attendances->where('status', $key)->count()),
            'recent' => $attendances->take(12)->map(fn (Attendance $a) => [
                'session_id' => $a->class_session_id,
                'class' => $a->session?->groupClass?->name,
                'date' => $a->session?->session_date,
                'status' => $a->status,
            ])->values(),
        ];
    }

    private function authorizeClass(User $user, GroupClass $class): void
    {
        abort_unless($class->organization_id === $user->organization_id && in_array($class->store_id, PlanController::storeIds($user), true), 404);
    }
}
