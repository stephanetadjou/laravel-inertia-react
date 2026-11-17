<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\LearnerExam;
use App\Models\LearnerTraining;
use App\Models\Organization;
use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suivi des apprenants (module « formation ») : heures faites, progression,
 * examens et décrochage, calculés à partir de ce qui existe déjà :
 * - leçons individuelles = rendez-vous honorés rattachés au parcours
 *   (appointments.learner_training_id, rattachement automatique à la création) ;
 * - séances collectives = présences aux cours de la formation
 *   (group_classes.training_id) depuis le début du parcours ;
 * - paiements = formule et échéancier (modules abonnements / tranches).
 *
 * Rien n'est saisi à la main : les heures faites et l'alerte de décrochage
 * se recalculent à chaque lecture.
 */
class Learners
{
    /** Statuts d'un parcours pas fini : l'apprenant peut encore être relancé. */
    public const UNFINISHED = [LearnerTraining::STATUS_ACTIVE, 'paused', 'dropped'];

    /**
     * Apprenants formés : au moins une formation terminée et aucune en cours,
     * en pause ou abandonnée. Ils n'ont plus besoin du centre : ni relance,
     * ni alerte de départ, ni campagne (seules restent les tranches encore
     * dues et les demandes qu'ils ont faites eux-mêmes).
     */
    public static function trainedQuery(int $organizationId): Builder
    {
        return DB::table('learner_trainings')
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->whereNotIn('customer_id', DB::table('learner_trainings')->where('organization_id', $organizationId)->whereIn('status', self::UNFINISHED)->select('customer_id'))
            ->select('customer_id');
    }

    /**
     * Abandons récents (6 derniers mois) sans nouvelle inscription : à
     * relancer pour reprendre la formation.
     *
     * @param  array<int, int>  $storeIds
     * @return Collection<int, array{learner: LearnerTraining, summary: array<string, mixed>}>
     */
    public function dropped(Organization $organization, array $storeIds): Collection
    {
        $learners = LearnerTraining::where('organization_id', $organization->id)
            ->where('status', 'dropped')
            ->whereIn('store_id', $storeIds)
            ->where('ended_on', '>=', today()->subMonths(6)->toDateString())
            ->whereNotIn('customer_id', LearnerTraining::where('organization_id', $organization->id)->whereIn('status', [LearnerTraining::STATUS_ACTIVE, 'paused', 'completed'])->select('customer_id'))
            ->with(['training', 'customer', 'staffMember:id,name'])
            ->orderByDesc('ended_on')
            ->get();
        $summaries = $this->summaries($learners, $organization);

        return $learners->map(fn (LearnerTraining $l) => ['learner' => $l, 'summary' => $summaries[$l->id]])->values();
    }

    /** La leçon créée compte dans le parcours en cours de l'apprenant (le plus récent). */
    public static function linkAppointment(Appointment $appointment): void
    {
        if ($appointment->learner_training_id !== null || $appointment->customer_id === null) {
            return;
        }

        $id = LearnerTraining::where('customer_id', $appointment->customer_id)
            ->whereIn('status', [LearnerTraining::STATUS_ACTIVE, 'paused'])
            ->orderByDesc('started_on')
            ->value('id');

        if ($id !== null) {
            $appointment->learner_training_id = $id;
            $appointment->saveQuietly();
        }
    }

    /**
     * Inscription d'un apprenant à une formation ; avec la formule de la
     * formation (module abonnements), l'échéancier se prépare en N tranches.
     *
     * @param  array{customer_id: int, training_id: int, store_id: int, started_on: string, target_on?: string|null, staff_member_id?: int|null, extra_hours?: int|null, with_plan?: bool, installments?: int|null, notes?: string|null}  $data
     */
    public function enroll(User $user, array $data): LearnerTraining
    {
        $organization = $user->organization;
        $training = Training::where('organization_id', $organization->id)->findOrFail($data['training_id']);
        $start = Carbon::parse($data['started_on'])->toDateString();
        $target = filled($data['target_on'] ?? null)
            ? Carbon::parse($data['target_on'])->toDateString()
            : ($training->duration_weeks ? Carbon::parse($start)->addWeeks($training->duration_weeks)->toDateString() : null);

        return DB::transaction(function () use ($user, $organization, $training, $data, $start, $target) {
            $subscriptionId = null;
            if (! empty($data['with_plan']) && $training->plan_id && $organization->hasModule('abonnements')) {
                $subscriptionId = app(Subscriptions::class)->create($user, [
                    'customer_id' => (int) $data['customer_id'],
                    'plan_id' => $training->plan_id,
                    'store_id' => (int) $data['store_id'],
                    'starts_on' => $start,
                    'ends_on' => $target,
                    'season_installments' => max(1, (int) ($data['installments'] ?? 1)),
                ])->id;
            }

            $learner = LearnerTraining::create([
                'organization_id' => $organization->id,
                'store_id' => (int) $data['store_id'],
                'customer_id' => (int) $data['customer_id'],
                'training_id' => $training->id,
                'subscription_id' => $subscriptionId,
                'staff_member_id' => $data['staff_member_id'] ?? null,
                'started_on' => $start,
                'target_on' => $target,
                'hours_planned' => $training->hours_planned,
                'extra_hours' => (int) ($data['extra_hours'] ?? 0),
                'sessions_planned' => $training->sessions_planned,
                'status' => LearnerTraining::STATUS_ACTIVE,
                'notes' => $data['notes'] ?? null,
            ]);

            // Leçons déjà prises depuis le début du parcours, pas encore rattachées.
            Appointment::where('customer_id', $learner->customer_id)
                ->whereNull('learner_training_id')
                ->where('starts_at', '>=', $start)
                ->update(['learner_training_id' => $learner->id]);

            Audit::record('learner.enrolled', $learner, "Inscription à « {$training->name} »", [], $user);

            return $learner;
        });
    }

    /** Nouvel examen : la tentative suit les précédentes du même type. */
    public function scheduleExam(LearnerTraining $learner, array $data): LearnerExam
    {
        $attempt = $learner->exams()->where('type', $data['type'])->whereIn('result', ['failed', 'absent', 'passed'])->count() + 1;

        $exam = LearnerExam::create([
            'organization_id' => $learner->organization_id,
            'learner_training_id' => $learner->id,
            'customer_id' => $learner->customer_id,
            'type' => $data['type'],
            'exam_on' => Carbon::parse($data['exam_on'])->toDateString(),
            'exam_time' => $data['exam_time'] ?? null,
            'attempt' => $attempt,
            'result' => $data['result'] ?? 'scheduled',
            'score' => $data['score'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->checkCompletion($learner);

        return $exam;
    }

    public function updateExam(LearnerExam $exam, array $data): void
    {
        $exam->update(array_intersect_key($data, array_flip(['result', 'score', 'notes', 'exam_on', 'exam_time'])));
        $this->checkCompletion($exam->learnerTraining);
    }

    /** Tous les examens de la formation réussis : le parcours est terminé. */
    public function checkCompletion(LearnerTraining $learner): void
    {
        $types = $learner->training->examTypes();
        if ($types === [] || ! in_array($learner->status, [LearnerTraining::STATUS_ACTIVE, 'paused'], true)) {
            return;
        }

        $passed = $learner->exams()->where('result', 'passed')->pluck('exam_on', 'type');
        if (collect($types)->every(fn ($t) => isset($passed[$t]))) {
            $learner->update(['status' => 'completed', 'ended_on' => $passed->max(), 'end_reason' => 'Examens réussis']);

            // Formé : plus d'alerte de départ (cf. CustomerScoring).
            DB::table('customers')->whereIn('id', self::trainedQuery($learner->organization_id))->where('id', $learner->customer_id)->update(['churn_risk' => null]);
        }
    }

    /**
     * Progression de chaque parcours, en trois requêtes pour toute la liste.
     *
     * @param  Collection<int, LearnerTraining>  $learners  (training chargé)
     * @return array<int, array<string, mixed>> par id de parcours
     */
    public function summaries(Collection $learners, Organization $organization): array
    {
        if ($learners->isEmpty()) {
            return [];
        }

        $ids = $learners->pluck('id')->all();
        $now = now()->format('Y-m-d H:i:s');
        $open = "'".implode("','", Appointment::OPEN_STATUSES)."'";

        $lessons = DB::table('appointments')
            ->whereIn('learner_training_id', $ids)
            ->groupBy('learner_training_id')
            ->selectRaw("learner_training_id,
                sum(case when status = 'done' then duration_minutes else 0 end) as done_minutes,
                sum(case when status = 'done' then 1 else 0 end) as done_count,
                max(case when status = 'done' then starts_at end) as last_done,
                min(case when status in ({$open}) and starts_at >= ? then starts_at end) as next_at,
                sum(case when status in ({$open}) and starts_at >= ? then duration_minutes else 0 end) as booked_minutes,
                sum(case when status = 'no_show' then 1 else 0 end) as no_shows", [$now, $now])
            ->get()
            ->keyBy('learner_training_id');

        $presence = DB::table('attendances')
            ->join('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
            ->join('group_classes', 'group_classes.id', '=', 'class_sessions.group_class_id')
            ->whereIn('group_classes.training_id', $learners->pluck('training_id')->unique()->all())
            ->whereIn('attendances.customer_id', $learners->pluck('customer_id')->unique()->all())
            ->where('attendances.status', 'present')
            ->get(['attendances.customer_id', 'group_classes.training_id', 'class_sessions.session_date', 'class_sessions.start_time', 'class_sessions.end_time'])
            ->groupBy(fn ($r) => $r->customer_id.'-'.$r->training_id);

        $exams = LearnerExam::whereIn('learner_training_id', $ids)->orderBy('exam_on')->orderBy('id')->get()->groupBy('learner_training_id');
        $settings = $organization->moduleSettings('formation');

        return $learners->mapWithKeys(function (LearnerTraining $l) use ($lessons, $presence, $exams, $settings) {
            $row = $lessons[$l->id] ?? null;
            $sessions = collect($presence[$l->customer_id.'-'.$l->training_id] ?? [])->filter(fn ($s) => $s->session_date >= $l->started_on && ($l->ended_on === null || $s->session_date <= $l->ended_on));
            $hoursDone = round(((int) ($row->done_minutes ?? 0)) / 60, 1);
            $hoursTotal = $l->totalHours();
            $sessionsDone = $sessions->count();

            $parts = [];
            if ($hoursTotal > 0) {
                $parts[] = min(1, $hoursDone / $hoursTotal);
            }
            if ($l->sessions_planned > 0) {
                $parts[] = min(1, $sessionsDone / $l->sessions_planned);
            }
            $percent = $l->status === 'completed' ? 100 : ($parts === [] ? 0 : (int) round(array_sum($parts) / count($parts) * 100));

            $lastDone = $row?->last_done ? Carbon::parse($row->last_done) : null;
            $lastSession = $sessions->max('session_date');
            $last = collect([$lastDone?->toDateString(), $lastSession])->filter()->max();

            /** @var Collection<int, LearnerExam> $list */
            $list = $exams[$l->id] ?? collect();
            $next = $list->first(fn (LearnerExam $e) => $e->result === 'scheduled' && $e->exam_on >= today()->toDateString());

            return [$l->id => [
                'hours_done' => $hoursDone,
                'hours_total' => $hoursTotal,
                'hours_booked' => round(((int) ($row->booked_minutes ?? 0)) / 60, 1),
                'lessons_done' => (int) ($row->done_count ?? 0),
                'no_shows' => (int) ($row->no_shows ?? 0),
                'sessions_done' => $sessionsDone,
                'sessions_planned' => (int) $l->sessions_planned,
                'percent' => $percent,
                'last_activity' => $last,
                'next_lesson' => $row?->next_at ? Carbon::parse($row->next_at)->format('Y-m-d\TH:i') : null,
                'next_exam' => $next ? ['type' => $next->type, 'date' => $next->exam_on, 'attempt' => $next->attempt] : null,
                'exams_passed' => $list->where('result', 'passed')->pluck('type')->unique()->values()->all(),
                'exams_failed' => $list->where('result', 'failed')->count(),
                'alert' => $this->alert($l, $percent, $last, $row?->next_at !== null, $list, $settings),
            ]];
        })->all();
    }

    /**
     * Motif de décrochage d'un parcours en cours, null s'il n'y en a pas :
     * aucune leçon ni présence depuis N jours (sans leçon à venir), retard
     * sur le rythme attendu ou échecs répétés au même examen.
     *
     * @param  Collection<int, LearnerExam>  $exams
     * @param  array<string, mixed>  $settings
     */
    private function alert(LearnerTraining $l, int $percent, ?string $last, bool $hasNext, Collection $exams, array $settings): ?string
    {
        if ($l->status !== LearnerTraining::STATUS_ACTIVE) {
            return null;
        }

        $days = (int) Carbon::parse($last ?? $l->started_on)->diffInDays(today());
        $threshold = max(1, (int) $settings['dropout_days']);
        if (! $hasNext && $days >= $threshold) {
            return $last ? "Aucune leçon depuis {$days} jours" : "Inscrit depuis {$days} jours, aucune leçon";
        }

        $failed = $exams->where('result', 'failed')->countBy('type')->filter(fn ($n) => $n >= 2);
        if ($failed->isNotEmpty() && ! $exams->contains(fn ($e) => $e->result === 'scheduled')) {
            $type = $failed->keys()->first();

            return "{$failed[$type]} échecs à l’examen « {$type} », pas de nouvelle date";
        }

        if ($l->target_on && $l->target_on > $l->started_on) {
            $span = max(1, Carbon::parse($l->started_on)->diffInDays(Carbon::parse($l->target_on)));
            $elapsed = min(1, max(0, Carbon::parse($l->started_on)->diffInDays(today(), false) / $span));
            $expected = (int) round($elapsed * 100);
            if ($expected - $percent >= (int) $settings['behind_percent']) {
                return "En retard : {$percent} % fait, {$expected} % attendu à ce stade";
            }
        }

        return null;
    }

    /**
     * Parcours en cours avec une alerte, dans le périmètre.
     *
     * @param  array<int, int>  $storeIds
     * @return Collection<int, array{learner: LearnerTraining, summary: array<string, mixed>}>
     */
    public function dropouts(Organization $organization, array $storeIds): Collection
    {
        $learners = LearnerTraining::where('organization_id', $organization->id)
            ->active()
            ->whereIn('store_id', $storeIds)
            ->with(['training', 'customer', 'staffMember:id,name'])
            ->get();
        $summaries = $this->summaries($learners, $organization);

        return $learners
            ->filter(fn (LearnerTraining $l) => $summaries[$l->id]['alert'] !== null)
            ->map(fn (LearnerTraining $l) => ['learner' => $l, 'summary' => $summaries[$l->id]])
            ->values();
    }
}
