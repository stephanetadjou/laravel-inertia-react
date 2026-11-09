<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Planning des séances d'un abonnement : chaque jour et heure choisis
 * devient un rendez-vous dans l'agenda (avec son intervenant si la séance
 * est suivie), sur un horizon glissant de 8 semaines prolongé chaque nuit
 * (crm:subscription-planning). Rejouable : un créneau déjà présent n'est pas
 * recréé ; changer le planning remplace les séances à venir non honorées.
 */
class SubscriptionPlanning
{
    public const HORIZON_WEEKS = 8;

    public const WEEKDAYS = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    /** Remplace les séances à venir (planning modifié, ou abonnement arrêté). */
    public static function replace(Subscription $subscription): int
    {
        self::futureSessions($subscription)->delete();

        return self::sync($subscription);
    }

    /** Crée les séances manquantes de l'horizon ; renvoie le nombre de séances créées. */
    public static function sync(Subscription $subscription): int
    {
        $planning = self::normalize($subscription->planning);
        $organization = $subscription->store?->organization;

        if ($planning === [] || $subscription->status !== Subscription::STATUS_ACTIVE || ! $organization?->hasAgenda()) {
            return 0;
        }

        $tz = $organization->timezone ?: config('app.timezone');
        $from = Carbon::now($tz)->startOfDay()->max(Carbon::parse($subscription->starts_on->toDateString(), $tz));
        $to = Carbon::now($tz)->addWeeks(self::HORIZON_WEEKS)->endOfDay();
        if ($subscription->ends_on !== null) {
            $to = $to->min(Carbon::parse($subscription->ends_on->toDateString(), $tz)->endOfDay());
        }

        $member = $subscription->member();
        $existing = Appointment::where('subscription_id', $subscription->id)->pluck('starts_at')
            ->map(fn ($at) => $at->copy()->setTimezone($tz)->format('Y-m-d H:i'))->flip();
        $created = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            foreach ($planning as $slot) {
                if ($slot['weekday'] !== $day->dayOfWeekIso) {
                    continue;
                }
                $start = Carbon::parse($day->toDateString().' '.$slot['time'], $tz);
                if ($start->isPast() || isset($existing[$start->format('Y-m-d H:i')])) {
                    continue;
                }

                Appointment::create([
                    'store_id' => $subscription->store_id,
                    'customer_id' => $member->id,
                    'subscription_id' => $subscription->id,
                    'staff_member_id' => $subscription->coached ? $subscription->staff_member_id : null,
                    'starts_at' => $start->copy()->setTimezone(config('app.timezone')),
                    'duration_minutes' => $subscription->session_minutes ?: 60,
                    'service' => ($subscription->plan?->name ?? 'Séance').' · '.($subscription->coached ? 'séance suivie' : 'en autonomie'),
                    'status' => 'planned',
                    'source' => 'subscription',
                    'kind' => 'rdv',
                ]);
                $created++;
            }
        }

        return $created;
    }

    /** « lun., mer. 18h00 · ven. 07h00 » */
    public static function summary(?array $planning): ?string
    {
        $planning = self::normalize($planning);

        return $planning === [] ? null : collect($planning)->groupBy('time')
            ->map(fn ($slots, $time) => $slots->map(fn ($s) => self::WEEKDAYS[$s['weekday']])->implode(', ').' '.str_replace(':', 'h', $time))
            ->implode(' · ');
    }

    /** @return list<array{weekday: int, time: string}> */
    public static function normalize(?array $planning): array
    {
        return collect($planning ?? [])
            ->filter(fn ($s) => is_array($s) && isset(self::WEEKDAYS[(int) ($s['weekday'] ?? 0)]) && preg_match('/^\d{2}:\d{2}$/', (string) ($s['time'] ?? '')))
            ->map(fn ($s) => ['weekday' => (int) $s['weekday'], 'time' => (string) $s['time']])
            ->unique(fn ($s) => $s['weekday'].$s['time'])
            ->sortBy(fn ($s) => $s['weekday'].$s['time'])
            ->values()->all();
    }

    private static function futureSessions(Subscription $subscription)
    {
        return Appointment::where('subscription_id', $subscription->id)
            ->where('starts_at', '>=', now())
            ->whereIn('status', ['planned', 'requested', 'confirmed'])
            ->whereNull('purchase_id');
    }
}
