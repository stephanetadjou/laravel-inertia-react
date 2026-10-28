<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Closure;
use App\Models\StaffMember;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Créneaux libres d'un établissement pour la réservation en ligne.
 *
 * Un créneau est proposé s'il commence après le délai minimum, avant
 * l'horizon, tient entièrement dans les horaires du jour, ne chevauche pas
 * la pause quotidienne, ne tombe pas un jour de fermeture (férié, congés) et
 * s'il reste de la place :
 * - établissement avec intervenants réservables : un intervenant qui réalise
 *   les prestations, travaille à cette heure et n'a pas de rendez-vous
 *   (le client peut en choisir un, sinon le moins chargé du jour est pris) ;
 * - sinon : nombre de rendez-vous qui chevauchent le créneau sous la capacité.
 *
 * Statuts qui occupent un créneau : à confirmer, prévu, confirmé, honoré.
 * Les heures sont celles de l'établissement, enregistrées telles quelles.
 */
class BookingAvailability
{
    /** @var Collection<int, StaffMember>|null */
    private ?Collection $staff = null;

    /**
     * @param  array{slot_step: int, min_notice_minutes: int, horizon_days: int}  $settings
     */
    public function __construct(private Store $store, private array $settings) {}

    /**
     * Intervenants proposés en ligne pour ces prestations.
     *
     * @param  array<int, int>  $serviceIds
     * @return Collection<int, StaffMember>
     */
    public function staffFor(array $serviceIds): Collection
    {
        return $this->bookableStaff()->filter(fn (StaffMember $s) => $s->canPerform($serviceIds))->values();
    }

    /**
     * Créneaux libres d'une journée (« 09:00 », « 09:30 »…).
     *
     * @param  array<int, int>  $serviceIds
     * @return list<string>
     */
    public function slots(Carbon $day, int $duration, array $serviceIds = [], ?int $staffId = null): array
    {
        $day = $day->copy()->startOfDay();

        if (! $this->withinHorizon($day) || $this->closed($day)) {
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
            $end = $start->copy()->addMinutes($duration);

            if ($start->lt($earliest) || $this->duringBreak($start, $end)) {
                continue;
            }

            if ($this->hasRoom($busy, $start, $end, $serviceIds, $staffId)) {
                $slots[] = $start->format('H:i');
            }
        }

        return $slots;
    }

    /**
     * Jours de l'horizon avec au moins un créneau libre.
     *
     * @param  array<int, int>  $serviceIds
     * @return list<string> dates AAAA-MM-JJ
     */
    public function openDays(int $duration, array $serviceIds = [], ?int $staffId = null): array
    {
        $days = [];

        for ($day = today(), $i = 0; $i <= $this->settings['horizon_days']; $i++, $day = $day->copy()->addDay()) {
            if ($this->slots($day, $duration, $serviceIds, $staffId) !== []) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    /**
     * Le créneau est-il encore libre ? (à appeler dans la transaction verrouillée de la réservation)
     *
     * @param  array<int, int>  $serviceIds
     */
    public function isFree(Carbon $start, int $duration, array $serviceIds = [], ?int $staffId = null): bool
    {
        return in_array($start->format('H:i'), $this->slots($start, $duration, $serviceIds, $staffId), true);
    }

    /**
     * Intervenant du rendez-vous : celui choisi s'il est libre, sinon le moins
     * chargé du jour parmi ceux qui sont libres. null si l'établissement
     * fonctionne sans intervenants.
     *
     * @param  array<int, int>  $serviceIds
     */
    public function assign(Carbon $start, int $duration, array $serviceIds, ?int $staffId): ?StaffMember
    {
        if ($this->bookableStaff()->isEmpty()) {
            return null;
        }

        $end = $start->copy()->addMinutes($duration);
        $busy = $this->busy($start->copy()->startOfDay(), $start->copy()->startOfDay()->addDay());

        return $this->freeStaff($busy, $start, $end, $serviceIds, $staffId)
            ->sortBy(fn (StaffMember $s) => [$busy->where('staff', $s->id)->count(), $s->id])
            ->first();
    }

    /** Fermé ce jour-là : férié, congés ou fermeture exceptionnelle. */
    public function closed(Carbon $day): bool
    {
        return Closure::affecting($this->store, $day, $day)->exists();
    }

    /** @return Collection<int, StaffMember> */
    private function bookableStaff(): Collection
    {
        return $this->staff ??= StaffMember::where('store_id', $this->store->id)
            ->where('active', true)
            ->where('bookable_online', true)
            ->with(['services:id', 'store'])
            ->orderBy('name')
            ->get();
    }

    private function withinHorizon(Carbon $day): bool
    {
        return $day->gte(today()) && $day->lte(today()->addDays($this->settings['horizon_days']));
    }

    private function duringBreak(Carbon $start, Carbon $end): bool
    {
        $break = $this->store->bookingBreak();

        if ($break === null) {
            return false;
        }

        $from = $start->copy()->setTimeFromTimeString($break['start']);
        $to = $start->copy()->setTimeFromTimeString($break['end']);

        return $start->lt($to) && $end->gt($from);
    }

    /**
     * @param  Collection<int, array{start: Carbon, end: Carbon, staff: int|null}>  $busy
     * @param  array<int, int>  $serviceIds
     */
    private function hasRoom(Collection $busy, Carbon $start, Carbon $end, array $serviceIds, ?int $staffId): bool
    {
        $overlapping = $busy->filter(fn ($b) => $b['start']->lt($end) && $b['end']->gt($start));

        if ($this->bookableStaff()->isEmpty()) {
            return $overlapping->count() < max(1, (int) $this->store->booking_capacity);
        }

        // Un rendez-vous sans intervenant occupe aussi l'un d'eux.
        $unassigned = $overlapping->whereNull('staff')->count();
        $free = $this->freeStaff($busy, $start, $end, $serviceIds, null);

        if ($free->count() <= $unassigned) {
            return false;
        }

        return $staffId === null || $free->contains('id', $staffId);
    }

    /**
     * Intervenants qui réalisent les prestations, travaillent sur tout le
     * créneau et n'ont pas de rendez-vous qui le chevauche.
     *
     * @param  Collection<int, array{start: Carbon, end: Carbon, staff: int|null}>  $busy
     * @param  array<int, int>  $serviceIds
     * @return Collection<int, StaffMember>
     */
    private function freeStaff(Collection $busy, Carbon $start, Carbon $end, array $serviceIds, ?int $staffId): Collection
    {
        return $this->staffFor($serviceIds)
            ->when($staffId !== null, fn ($c) => $c->where('id', $staffId))
            ->filter(function (StaffMember $s) use ($busy, $start, $end) {
                $hours = $s->workingHours()[$start->dayOfWeekIso] ?? null;

                if ($hours === null
                    || $start->lt($start->copy()->setTimeFromTimeString($hours['open']))
                    || $end->gt($start->copy()->setTimeFromTimeString($hours['close']))) {
                    return false;
                }

                return ! $busy->contains(fn ($b) => $b['staff'] === $s->id && $b['start']->lt($end) && $b['end']->gt($start));
            })
            ->values();
    }

    /**
     * @return Collection<int, array{start: Carbon, end: Carbon, staff: int|null}>
     */
    private function busy(Carbon $from, Carbon $to): Collection
    {
        return Appointment::where('store_id', $this->store->id)
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            // Un rendez-vous de la veille ne déborde pas au-delà de 10 h de durée.
            ->where('starts_at', '>=', $from->copy()->subHours(10))
            ->where('starts_at', '<', $to)
            ->get(['starts_at', 'duration_minutes', 'staff_member_id'])
            ->map(fn (Appointment $a) => [
                'start' => $a->starts_at,
                'end' => $a->starts_at->copy()->addMinutes($a->duration_minutes),
                'staff' => $a->staff_member_id,
            ]);
    }
}
