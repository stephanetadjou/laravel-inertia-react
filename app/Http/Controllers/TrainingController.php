<?php

namespace App\Http\Controllers;

use App\Models\GroupClass;
use App\Models\LearnerTraining;
use App\Models\Plan;
use App\Models\Training;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Formations du centre (module « formation ») : heures de leçons et séances
 * collectives prévues, durée conseillée, examens, formule de paiement et
 * cours collectifs comptés dans la formation.
 */
class TrainingController extends Controller
{
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        return Inertia::render('Learners/Trainings', [
            'trainings' => Training::where('organization_id', $organizationId)
                ->with(['plan:id,name', 'groupClasses:id,name,training_id'])
                ->withCount(['learners as active_count' => fn ($q) => $q->where('status', LearnerTraining::STATUS_ACTIVE)])
                ->withCount(['learners as completed_count' => fn ($q) => $q->where('status', 'completed')])
                ->orderByDesc('active')
                ->orderBy('name')
                ->get()
                ->map(fn (Training $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'hours_planned' => $t->hours_planned,
                    'sessions_planned' => $t->sessions_planned,
                    'duration_weeks' => $t->duration_weeks,
                    'exams' => $t->examTypes(),
                    'plan_id' => $t->plan_id,
                    'plan' => $t->plan?->name,
                    'class_ids' => $t->groupClasses->pluck('id'),
                    'classes' => $t->groupClasses->pluck('name'),
                    'active' => $t->active,
                    'active_count' => $t->active_count,
                    'completed_count' => $t->completed_count,
                ]),
            'plans' => $request->user()->organization->hasModule('abonnements')
                ? Plan::where('organization_id', $organizationId)->where('active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'classes' => $request->user()->organization->hasModule('seances_groupe')
                ? GroupClass::where('organization_id', $organizationId)->where('active', true)->orderBy('name')->get(['id', 'name', 'training_id'])
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $training = Training::create([...$this->attributes($data), 'organization_id' => $request->user()->organization_id]);
        $this->syncClasses($request, $training, $data['class_ids'] ?? []);

        return back()->with('success', "Formation « {$training->name} » créée.");
    }

    public function update(Request $request, Training $training): RedirectResponse
    {
        $this->authorizeOwn($request, $training);
        $data = $this->validated($request);
        $training->update([...$this->attributes($data), 'active' => (bool) ($data['active'] ?? true)]);
        $this->syncClasses($request, $training, $data['class_ids'] ?? []);

        return back()->with('success', "Formation « {$training->name} » mise à jour.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $organizationId = $request->user()->organization_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'hours_planned' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'sessions_planned' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'duration_weeks' => ['nullable', 'integer', 'min:1', 'max:260'],
            'exams' => ['nullable', 'array', 'max:10'],
            'exams.*' => ['nullable', 'string', 'max:60'],
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')->where('organization_id', $organizationId)],
            'class_ids' => ['nullable', 'array'],
            'class_ids.*' => ['integer'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'hours_planned' => (int) ($data['hours_planned'] ?? 0),
            'sessions_planned' => (int) ($data['sessions_planned'] ?? 0),
            'duration_weeks' => $data['duration_weeks'] ?? null,
            'exams' => array_values(array_filter(array_map(fn ($e) => trim((string) $e), $data['exams'] ?? []))),
            'plan_id' => $data['plan_id'] ?? null,
        ];
    }

    /** @param  list<int>  $ids */
    private function syncClasses(Request $request, Training $training, array $ids): void
    {
        $scope = GroupClass::where('organization_id', $request->user()->organization_id);
        (clone $scope)->where('training_id', $training->id)->whereNotIn('id', $ids)->update(['training_id' => null]);
        (clone $scope)->whereKey($ids)->update(['training_id' => $training->id]);
    }

    private function authorizeOwn(Request $request, Training $training): void
    {
        if ($training->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cette formation n’appartient pas à votre entreprise.');
        }
    }
}
