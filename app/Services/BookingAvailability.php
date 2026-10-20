<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Créneaux libres d'un établissement pour la réservation en ligne.
 *
 * Un créneau est proposé s'il commence après le délai minimum, avant
 * l'horizon, tient entièrement dans les horaires du jour et si le nombre de
 * rendez-vous qui le chevauchent (à confirmer, prévus, confirmés, honorés)
 * reste sous la capacité de l'établissement. Les heures sont celles de
 * l'établissement, enregistrées telles quelles (comme dans l'agenda).
 */
class BookingAvailability
{
    /**
     * @param  array{slot_step: int, min_notice_minutes: int, horizon_days: int}  $settings
     */
    public function __construct(private Store $store, private array $settings) {}

    /**
     * Créneaux libres d'une journée (« 09:00 », « 09:30 »…).
     *
     * @return list<string>
     */
    public function slots(Carbon $day, int $duration): array
    {
        $day = $day->copy()->startOfDay();

        if (! $this->withinHorizon($day)) {
            return [];
        }

        $hours = $this->store->bookingHours()[$day->dayOfWeekIso] ?? null;

        if ($hours === null) {
            return [];
        }

        $open = $day->copy()->setTimeFromTimeString($hours['open']);
        $close = $day->copy()->setTimeFromTimeString($hours['close']);
        $earliest = now()->addMinutes($this->settings['min_notice_minutes']);
        $busy = $this->busy($day, $day->copy()->addDay());
        $step = max(5, $this->settings['slot_step']);
        $slots = [];

        for ($start = $open->copy(); $start->copy()->addMinutes($duration)->lte($close); $start->addMinutes($step)) {
            if ($start->lt($earliest)) {
                continue;
            }

            if ($this->fits($busy, $start, $start->copy()->addMinutes($duration))) {
                $slots[] = $start->format('H:i');
            }
        }

        return $slots;
    }

    /**
     * Jours de l'horizon avec au moins un créneau libre.
     *
     * @return list<string> dates AAAA-MM-JJ
     */
    public function openDays(int $duration): array
    {
        $days = [];

        for ($day = today(), $i = 0; $i <= $this->settings['horizon_days']; $i++, $day = $day->copy()->addDay()) {
            if ($this->slots($day, $duration) !== []) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    /** Le créneau est-il encore libre ? (à appeler dans la transaction verrouillée de la réservation) */
    public function isFree(Carbon $start, int $duration): bool
    {
        return in_array($start->format('H:i'), $this->slots($start, $duration), true);
    }

    private function withinHorizon(Carbon $day): bool
    {
        return $day->gte(today()) && $day->lte(today()->addDays($this->settings['horizon_days']));
    }

    /**
     * @return Collection<int, array{0: Carbon, 1: Carbon}>
     */
    private function busy(Carbon $from, Carbon $to): Collection
    {
        return Appointment::where('store_id', $this->store->id)
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            // Un rendez-vous de la veille ne déborde pas au-delà de 10 h de durée.
            ->where('starts_at', '>=', $from->copy()->subHours(10))
            ->where('starts_at', '<', $to)
            ->get(['starts_at', 'duration_minutes'])
            ->map(fn (Appointment $a) => [$a->starts_at, $a->starts_at->copy()->addMinutes($a->duration_minutes)]);
    }

    /** @param Collection<int, array{0: Carbon, 1: Carbon}> $busy */
    private function fits(Collection $busy, Carbon $start, Carbon $end): bool
    {
        $overlapping = $busy->filter(fn ($b) => $b[0]->lt($end) && $b[1]->gt($start))->count();

        return $overlapping < max(1, (int) $this->store->booking_capacity);
    }
}
