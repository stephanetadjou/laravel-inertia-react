<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cours de groupe : séances datées créées au fil du calendrier, inscriptions
 * et appel. Une présence sur une carte de séances décompte une séance.
 */
class GroupClasses
{
    /** Horizon des séances préparées à l'avance. */
    public const HORIZON_DAYS = 90;

    /**
     * Crée les séances manquantes des cours entre $from (inclus) et $to (exclu).
     *
     * @param  Collection<int, GroupClass>|null  $classes  cours concernés (défaut : tous les cours actifs de l'organisation)
     */
    public function ensureSessions(int $organizationId, Carbon $from, Carbon $to, ?Collection $classes = null): void
    {
        $classes ??= GroupClass::where('organization_id', $organizationId)->where('active', true)->get();
        $limit = today()->addDays(self::HORIZON_DAYS);
        $rows = [];

        foreach ($classes as $class) {
            $start = Carbon::parse($class->starts_on)->max($from)->startOfDay();
            $end = $to->copy()->min($limit);
            if ($class->ends_on !== null) {
                $end = $end->min(Carbon::parse($class->ends_on)->addDay());
            }

            $day = $start->copy();
            $shift = ($class->weekday - $day->dayOfWeekIso + 7) % 7;
            $day->addDays($shift);

            for (; $day->lt($end); $day->addWeek()) {
                $rows[] = [
                    'group_class_id' => $class->id,
                    'organization_id' => $class->organization_id,
                    'session_date' => $day->toDateString(),
                    'start_time' => $class->start_time,
                    'end_time' => $class->end_time,
                    'status' => ClassSession::STATUS_SCHEDULED,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('class_sessions')->insertOrIgnore($chunk);
        }
    }

    public function enroll(GroupClass $class, Customer $customer, ?Subscription $subscription = null, ?Carbon $on = null): ClassEnrollment
    {
        $count = $class->activeEnrollments()->where('customer_id', '!=', $customer->id)->count();
        if ($count >= $class->capacity) {
            throw ValidationException::withMessages(['customer_id' => "Le cours « {$class->name} » est complet ({$class->capacity} places)."]);
        }

        $subscription ??= Subscription::current()
            ->where(fn ($q) => $q->where('beneficiary_id', $customer->id)->orWhere(fn ($w) => $w->whereNull('beneficiary_id')->where('customer_id', $customer->id)))
            ->latest('id')->first();

        return ClassEnrollment::updateOrCreate(
            ['group_class_id' => $class->id, 'customer_id' => $customer->id],
            ['subscription_id' => $subscription?->id, 'enrolled_on' => ($on ?? today())->toDateString(), 'ended_on' => null],
        );
    }

    public function unenroll(ClassEnrollment $enrollment): void
    {
        $enrollment->update(['ended_on' => today()->toDateString()]);
    }

    /**
     * Appel : présent / absent / excusé pour chaque personne. Une présence sur
     * une carte de séances la décompte ; la retirer la rend.
     *
     * @param  array<int, string>  $marks  [customer_id => statut]
     */
    public function mark(ClassSession $session, array $marks, ?User $user = null): int
    {
        if ($session->isCancelled()) {
            throw ValidationException::withMessages(['marks' => 'Cette séance est annulée : pas d’appel.']);
        }

        $enrolled = $session->groupClass->enrollments()->with('subscription.plan')->get()->keyBy('customer_id');
        $points = (int) ($session->groupClass->store->organization?->moduleSettings('fidelite')['points_per_attendance'] ?? 0);
        $count = 0;

        DB::transaction(function () use ($session, $marks, $user, $enrolled, $points, &$count) {
            foreach ($marks as $customerId => $status) {
                if (! isset(Attendance::STATUSES[$status]) || ! $enrolled->has((int) $customerId)) {
                    continue;
                }

                $existing = Attendance::where('class_session_id', $session->id)->where('customer_id', $customerId)->first();
                $wasPresent = $existing?->status === 'present';
                Attendance::updateOrCreate(['class_session_id' => $session->id, 'customer_id' => $customerId], ['status' => $status, 'marked_by' => $user?->id]);
                $count++;

                $subscription = $enrolled[(int) $customerId]->subscription;
                if ($subscription?->plan?->period === 'sessions' && $wasPresent !== ($status === 'present')) {
                    $subscription->update(['sessions_left' => max(0, (int) $subscription->sessions_left + ($status === 'present' ? -1 : 1))]);
                }

                if ($points > 0 && ! $wasPresent && $status === 'present') {
                    Loyalty::for($session->groupClass->store->organization)->attendance(Customer::find($customerId), $points, $session, $user);
                }
            }
        });

        return $count;
    }

    /** Personnes inscrites à la date de la séance. @return Collection<int, Customer> */
    public function participants(ClassSession $session): Collection
    {
        return Customer::whereIn('id', $session->groupClass->enrollments()
            ->where('enrolled_on', '<=', $session->session_date)
            ->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>', $session->session_date))
            ->select('customer_id'))
            ->with('responsible:id,name,phone_e164')
            ->orderBy('name')
            ->get();
    }
}
