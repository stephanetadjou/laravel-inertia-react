<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BookableResource;
use App\Models\ClassEnrollment;
use App\Models\ClassSession;
use App\Models\Closure;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\StaffMember;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Moteur d'occupation : la ressource que l'entreprise doit rentabiliser.
 *
 * - Intervenants (module rdv) : minutes réservées sur les minutes de travail
 *   (horaires de l'intervenant, ou de l'établissement × capacité quand il n'y
 *   a pas d'intervenant), pause et fermetures déduites.
 * - Cours (module seances_groupe) : inscrits à la date de la séance sur les places.
 * - Ressources (module ressources) : jours avec au moins une réservation, et
 *   jours sans réservation depuis la dernière.
 *
 * Trois signaux, seuils dans Paramètres › Occupation : creux (sous « low » %),
 * saturation (à partir de « high » %) et immobilisation (« idle_days » jours).
 * Lecture seule : rien n'est enregistré.
 */
class Occupation
{
    /** Heures affichées dans la carte des heures. */
    public const HOURS = [7, 21];

    private const DAY_LABELS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];

    /** @var array{low: int, high: int, window_days: int, class_low: int, class_window_days: int, idle_days: int} */
    private array $settings;

    /** @var Collection<int, Store> */
    private Collection $stores;

    /**
     * @param  array<int, int>|null  $storeIds  périmètre visible (null = tous les établissements)
     */
    public function __construct(private Organization $organization, ?array $storeIds = null)
    {
        $this->settings = $organization->occupationSettings();
        $this->stores = Store::where('organization_id', $organization->id)
            ->where('is_active', true)
            ->when($storeIds !== null, fn ($q) => $q->whereIn('id', $storeIds))
            ->orderBy('name')
            ->get();
    }

    /** Le moteur ne sert qu'aux entreprises qui ont un agenda, des cours ou des ressources. */
    public static function applies(Organization $organization): bool
    {
        return $organization->hasModule('rdv') || $organization->hasModule('seances_groupe') || $organization->hasModule('ressources') || $organization->hasModule('vehicules');
    }

    /**
     * Occupation d'une période [from, to[ : taux global, par intervenant, cours,
     * ressources et carte des heures (jour de la semaine × heure).
     *
     * @return array<string, mixed>
     */
    public function period(Carbon $from, Carbon $to): array
    {
        $slots = $this->hasModule('rdv') ? $this->staffDays($from, $to) : collect();
        $capacity = (int) $slots->sum('capacity');
        $booked = (int) $slots->sum('booked');
        $sessions = $this->hasModule('seances_groupe') ? $this->sessions($from, $to) : collect();
        $places = (int) $sessions->sum('capacity');
        $filled = (int) $sessions->sum('filled');

        return [
            'from' => $from->toDateString(),
            'to' => $to->copy()->subDay()->toDateString(),
            'rate' => self::rate($booked, $capacity),
            'free_hours' => (int) floor(max(0, $capacity - $booked) / 60),
            'staff' => $slots->groupBy('key')->map(fn (Collection $days) => [
                'name' => $days->first()['name'],
                'store' => $days->first()['store'],
                'capacity' => (int) $days->sum('capacity'),
                'booked' => (int) $days->sum('booked'),
                'rate' => self::rate((int) $days->sum('booked'), (int) $days->sum('capacity')),
            ])->sortBy('rate')->values()->all(),
            'classes' => $sessions->isEmpty() ? null : [
                'sessions' => $sessions->count(),
                'places' => $places,
                'filled' => $filled,
                'rate' => self::rate($filled, $places),
                'full' => $sessions->where('rate', '>=', 100)->count(),
            ],
            'resources' => $this->hasResources() ? $this->resources($from, $to) : [],
            'heatmap' => $this->hasModule('rdv') ? $this->heatmap($from, $to) : null,
        ];
    }

    /**
     * Les creux, saturations et immobilisations à traiter, du plus proche au plus lointain.
     *
     * @return list<array{type: string, kind: string, date: string|null, label: string, detail: string, rate: int|null}>
     */
    public function signals(?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $s = $this->settings;
        $signals = [];

        if ($this->hasModule('rdv')) {
            foreach ($this->staffDays($today, $today->copy()->addDays(max(1, $s['window_days']))) as $slot) {
                $rate = self::rate($slot['booked'], $slot['capacity']);
                if ($rate === null) {
                    continue;
                }
                $when = self::dayLabel(Carbon::parse($slot['date']), $today);
                if ($rate < $s['low']) {
                    $free = intdiv($slot['capacity'] - $slot['booked'], 60);
                    $signals[] = $this->signal('creux', 'staff', $slot['date'], "{$slot['name']} · {$when}", "{$rate} % réservé, {$free} h libres", $rate);
                } elseif ($rate >= $s['high']) {
                    $signals[] = $this->signal('saturation', 'staff', $slot['date'], "{$slot['name']} · {$when}", "{$rate} % réservé : proposez un autre jour", $rate);
                }
            }
        }

        if ($this->hasModule('seances_groupe')) {
            foreach ($this->sessions($today, $today->copy()->addDays(max(1, $s['class_window_days']))) as $session) {
                $when = self::dayLabel(Carbon::parse($session['date']), $today).' '.$session['time'];
                if ($session['rate'] < $s['class_low']) {
                    $left = $session['capacity'] - $session['filled'];
                    $signals[] = $this->signal('creux', 'class', $session['date'], "{$session['name']} · {$when}", "{$session['filled']} inscrits sur {$session['capacity']}, {$left} places à remplir", $session['rate']);
                } elseif ($session['rate'] >= 100) {
                    $signals[] = $this->signal('saturation', 'class', $session['date'], "{$session['name']} · {$when}", "Complet ({$session['capacity']} places)", $session['rate']);
                }
            }
        }

        if ($this->hasModule('vehicules')) {
            // Stock de sécurité : toujours quelques véhicules libres pour un client de dernière minute.
            for ($i = 1; $i <= max(1, $s['window_days']); $i++) {
                $day = $today->copy()->addDays($i);
                $free = Fleet::availableOn($this->organization->id, $day, $this->stores->pluck('id')->all())->count();
                if ($free < $s['safety_stock']) {
                    $signals[] = $this->signal('saturation', 'fleet', $day->toDateString(), 'Flotte · '.self::dayLabel($day, $today), ($free === 0 ? 'Aucun véhicule libre' : "{$free} véhicule(s) libre(s)")." (stock de sécurité : {$s['safety_stock']})", null);
                }
            }

            // Véhicules à vendre qui vieillissent au parc.
            BookableResource::where('organization_id', $this->organization->id)->where('kind', 'vehicule')->where('status', 'for_sale')
                ->whereNotNull('for_sale_since')->where('for_sale_since', '<=', $today->copy()->subDays($s['sale_days'])->toDateString())
                ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $this->stores->pluck('id')))
                ->get()
                ->each(function (BookableResource $v) use (&$signals, $today) {
                    $signals[] = $this->signal('immobilisation', 'sale', null, $v->label(), 'À vendre depuis '.(int) $v->for_sale_since->diffInDays($today).' jours', null);
                });
        }

        if ($this->hasResources()) {
            foreach ($this->resources($today->copy()->subDays(30), $today) as $resource) {
                if ($resource['idle_days'] !== null && $resource['idle_days'] >= $s['idle_days']) {
                    $signals[] = $this->signal('immobilisation', 'resource', null, $resource['name'], "Aucune réservation depuis {$resource['idle_days']} jours", null);
                }
            }
        }

        $order = ['saturation' => 0, 'creux' => 1, 'immobilisation' => 2];
        usort($signals, fn ($a, $b) => [$a['date'] ?? '9999', $order[$a['type']]] <=> [$b['date'] ?? '9999', $order[$b['type']]]);

        return $signals;
    }

    /**
     * Séances à venir sous le seuil « peu rempli » (Paramètres › Occupation).
     *
     * @return Collection<int, array{id: int, group_class_id: int, name: string, date: string, time: string, capacity: int, filled: int, rate: int}>
     */
    public function lowSessions(?Carbon $today = null): Collection
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return $this->hasModule('seances_groupe')
            ? $this->sessions($today, $today->copy()->addDays(max(1, $this->settings['class_window_days'])))->where('rate', '<', $this->settings['class_low'])->values()
            : collect();
    }

    /**
     * Créneaux peu remplis des prochains jours : intervenant (ou établissement), jour, heures libres.
     *
     * @return Collection<int, array{name: string, date: string, free_hours: int, rate: int}>
     */
    public function gaps(?Carbon $today = null): Collection
    {
        $today = ($today ?? today())->copy()->startOfDay();

        if (! $this->hasModule('rdv')) {
            return collect();
        }

        return $this->staffDays($today, $today->copy()->addDays(max(1, $this->settings['window_days'])))
            ->map(fn ($slot) => ['name' => $slot['name'], 'date' => $slot['date'], 'free_hours' => intdiv($slot['capacity'] - $slot['booked'], 60), 'rate' => (int) self::rate($slot['booked'], $slot['capacity'])])
            ->filter(fn ($gap) => $gap['rate'] < $this->settings['low'] && $gap['free_hours'] >= 1)
            ->values();
    }

    /**
     * Une ligne par intervenant (ou par établissement sans intervenant) et par
     * jour ouvert : minutes de travail et minutes réservées.
     *
     * @return Collection<int, array{key: string, name: string, store: string, date: string, capacity: int, booked: int, windows: list<array{0: Carbon, 1: Carbon}>, appointments: list<array{0: Carbon, 1: Carbon}>}>
     */
    private function staffDays(Carbon $from, Carbon $to): Collection
    {
        $rows = collect();

        foreach ($this->stores as $store) {
            $staff = StaffMember::where('store_id', $store->id)->where('active', true)->with('store')->orderBy('name')->get();
            $closures = Closure::affecting($store, $from, $to->copy()->subDay())->get();
            $appointments = Appointment::where('store_id', $store->id)
                ->whereIn('status', Appointment::BLOCKING_STATUSES)
                ->where('starts_at', '>=', $from->copy()->subHours(10))
                ->where('starts_at', '<', $to)
                ->get(['starts_at', 'duration_minutes', 'staff_member_id'])
                ->map(fn (Appointment $a) => ['start' => $a->starts_at, 'end' => $a->starts_at->copy()->addMinutes((int) $a->duration_minutes), 'staff' => $a->staff_member_id]);

            for ($day = $from->copy()->startOfDay(); $day->lt($to); $day->addDay()) {
                if ($closures->contains(fn (Closure $c) => $c->covers($day))) {
                    continue;
                }

                if ($staff->isEmpty()) {
                    $windows = $this->windows($store, $day, $store->bookingHours()[$day->dayOfWeekIso] ?? null);
                    $seats = max(1, (int) $store->booking_capacity);
                    $busy = $appointments->map(fn ($a) => [$a['start'], $a['end']])->all();
                    $rows->push($this->row('store-'.$store->id, $store->name, $store->name, $day, $windows, $busy, $seats));

                    continue;
                }

                // Un rendez-vous sans intervenant occupe l'équipe : réparti sur l'établissement.
                $unassigned = $appointments->whereNull('staff')->map(fn ($a) => [$a['start'], $a['end']])->all();
                foreach ($staff as $i => $member) {
                    $windows = $this->windows($store, $day, $member->workingHours()[$day->dayOfWeekIso] ?? null);
                    $busy = $appointments->where('staff', $member->id)->map(fn ($a) => [$a['start'], $a['end']])->values()->all();
                    $row = $this->row('staff-'.$member->id, $member->name, $store->name, $day, $windows, $busy, 1);
                    $share = $unassigned === [] ? 0 : (int) round(self::overlap($unassigned, $windows) / $staff->count());
                    $row['booked'] = min($row['capacity'], $row['booked'] + $share);
                    $rows->push($row);
                }
            }
        }

        return $rows->filter(fn ($r) => $r['capacity'] > 0)->values();
    }

    /** Plages de travail d'une journée, pause de l'établissement retirée. @return list<array{0: Carbon, 1: Carbon}> */
    private function windows(Store $store, Carbon $day, ?array $hours): array
    {
        if ($hours === null) {
            return [];
        }

        $open = $day->copy()->setTimeFromTimeString($hours['open']);
        $close = $day->copy()->setTimeFromTimeString($hours['close']);
        $break = $store->bookingBreak();

        if ($break === null) {
            return [[$open, $close]];
        }

        $pauseStart = $day->copy()->setTimeFromTimeString($break['start']);
        $pauseEnd = $day->copy()->setTimeFromTimeString($break['end']);

        return array_values(array_filter([
            [$open, $pauseStart->min($close)],
            [$pauseEnd->max($open), $close],
        ], fn ($w) => $w[0]->lt($w[1])));
    }

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @param  list<array{0: Carbon, 1: Carbon}>  $busy
     */
    private function row(string $key, string $name, string $store, Carbon $day, array $windows, array $busy, int $seats): array
    {
        $capacity = (int) collect($windows)->sum(fn ($w) => $w[0]->diffInMinutes($w[1])) * $seats;

        return [
            'key' => $key,
            'name' => $name,
            'store' => $store,
            'date' => $day->toDateString(),
            'capacity' => $capacity,
            'booked' => min($capacity, self::overlap($busy, $windows)),
            'seats' => $seats,
            'windows' => $windows,
            'busy' => $busy,
        ];
    }

    /**
     * Minutes des réservations qui tombent dans les plages de travail.
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $busy
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     */
    private static function overlap(array $busy, array $windows): int
    {
        $minutes = 0;
        foreach ($busy as [$start, $end]) {
            foreach ($windows as [$open, $close]) {
                $from = $start->max($open);
                $to = $end->min($close);
                if ($from->lt($to)) {
                    $minutes += (int) $from->diffInMinutes($to);
                }
            }
        }

        return $minutes;
    }

    /**
     * Carte des heures : taux de réservation par jour de la semaine et par heure.
     *
     * @return array{hours: list<int>, rows: list<array{day: string, cells: list<int|null>}>}
     */
    private function heatmap(Carbon $from, Carbon $to): array
    {
        [$first, $last] = self::HOURS;
        $capacity = [];
        $booked = [];

        foreach ($this->staffDays($from, $to) as $slot) {
            $day = Carbon::parse($slot['date']);
            $dow = $day->dayOfWeekIso;
            for ($h = $first; $h < $last; $h++) {
                $cell = [[$day->copy()->setTime($h, 0), $day->copy()->setTime($h + 1, 0)]];
                $windows = collect($slot['windows'])->map(fn ($w) => [$w[0]->max($cell[0][0]), $w[1]->min($cell[0][1])])->filter(fn ($w) => $w[0]->lt($w[1]))->values()->all();
                $minutes = (int) collect($windows)->sum(fn ($w) => $w[0]->diffInMinutes($w[1])) * $slot['seats'];
                if ($minutes === 0) {
                    continue;
                }
                $capacity[$dow][$h] = ($capacity[$dow][$h] ?? 0) + $minutes;
                $booked[$dow][$h] = ($booked[$dow][$h] ?? 0) + min($minutes, self::overlap($slot['busy'], $windows));
            }
        }

        // Seulement les heures où quelqu'un travaille au moins un jour.
        $open = collect($capacity)->flatMap(fn ($byHour) => array_keys($byHour));
        $hours = $open->isEmpty() ? [] : range($open->min(), $open->max());

        $rows = [];
        foreach (self::DAY_LABELS as $dow => $label) {
            if (! isset($capacity[$dow])) {
                continue;
            }
            $rows[] = ['day' => $label, 'cells' => array_map(fn ($h) => self::rate($booked[$dow][$h] ?? 0, $capacity[$dow][$h] ?? 0), $hours)];
        }

        return ['hours' => $hours, 'rows' => $rows];
    }

    /**
     * Séances de cours prévues : inscrits à la date sur les places.
     *
     * @return Collection<int, array{name: string, date: string, time: string, capacity: int, filled: int, rate: int}>
     */
    private function sessions(Carbon $from, Carbon $to): Collection
    {
        $sessions = ClassSession::query()
            ->where('organization_id', $this->organization->id)
            ->where('status', ClassSession::STATUS_SCHEDULED)
            ->where('session_date', '>=', $from->toDateString())
            ->where('session_date', '<', $to->toDateString())
            ->whereHas('groupClass', fn ($q) => $q->whereIn('store_id', $this->stores->pluck('id'))->where('capacity', '>', 0))
            ->with('groupClass:id,name,capacity')
            ->orderBy('session_date')->orderBy('start_time')
            ->get();

        $enrollments = ClassEnrollment::whereIn('group_class_id', $sessions->pluck('group_class_id')->unique())
            ->get(['group_class_id', 'enrolled_on', 'ended_on'])
            ->groupBy('group_class_id');

        return $sessions->map(function (ClassSession $session) use ($enrollments) {
            $date = $session->session_date;
            $filled = ($enrollments[$session->group_class_id] ?? collect())
                ->filter(fn ($e) => (string) $e->enrolled_on <= $date && ($e->ended_on === null || (string) $e->ended_on > $date))
                ->count();
            $capacity = (int) $session->groupClass->capacity;

            return [
                'id' => $session->id,
                'group_class_id' => $session->group_class_id,
                'name' => $session->groupClass->name,
                'date' => $date,
                'time' => substr((string) $session->start_time, 0, 5),
                'capacity' => $capacity,
                'filled' => $filled,
                'rate' => (int) min(100, round($filled / $capacity * 100)),
            ];
        })->values();
    }

    /**
     * Ressources (véhicules, salles, matériel) : jours réservés sur la période
     * et jours écoulés depuis la dernière réservation passée.
     *
     * @return list<array{name: string, kind: string, used_days: int, days: int, rate: int|null, idle_days: int|null}>
     */
    private function resources(Carbon $from, Carbon $to): array
    {
        $days = max(1, (int) $from->diffInDays($to));

        return BookableResource::where('organization_id', $this->organization->id)
            ->where('active', true)
            // Un véhicule en entretien, à vendre ou vendu n'est pas à faire tourner.
            ->where('status', 'available')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $this->stores->pluck('id')))
            ->orderBy('name')
            ->get()
            ->map(function (BookableResource $resource) use ($from, $to, $days) {
                $booked = Appointment::where('resource_id', $resource->id)->whereIn('status', Appointment::BLOCKING_STATUSES);
                $dates = (clone $booked)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
                    ->pluck('starts_at')->map(fn ($d) => Carbon::parse($d)->toDateString());
                $last = (clone $booked)->where('starts_at', '<', now())->max('starts_at');
                $since = $last ? Carbon::parse($last) : $resource->created_at;

                // Locations de véhicules : chaque jour couvert par un contrat compte ; un véhicule sorti n'est pas immobilisé.
                $rentals = Rental::where('resource_id', $resource->id)->whereIn('status', ['reserved', 'ongoing', 'returned'])->where('starts_at', '<', $to)->get();
                foreach ($rentals as $rental) {
                    $end = Fleet::effectiveEnd($rental);
                    for ($d = $rental->starts_at->copy()->startOfDay()->max($from)->copy(); $d->lt($end->min($to)); $d->addDay()) {
                        $dates->push($d->toDateString());
                    }
                    if ($rental->status === 'ongoing') {
                        $since = now();
                    } elseif ($rental->starts_at->lt(now()) && $end->gt($since ?? $end->copy()->subDay())) {
                        $since = $end->min(now());
                    }
                }
                $used = $dates->unique()->count();

                return [
                    'name' => $resource->label(),
                    'kind' => BookableResource::KINDS[$resource->kind] ?? $resource->kind,
                    'used_days' => $used,
                    'days' => $days,
                    'rate' => self::rate($used, $days),
                    'idle_days' => $since ? (int) $since->copy()->startOfDay()->diffInDays(today()) : null,
                ];
            })
            ->sortBy('rate')
            ->values()
            ->all();
    }

    private function hasResources(): bool
    {
        return $this->hasModule('ressources') || $this->hasModule('vehicules');
    }

    private function hasModule(string $module): bool
    {
        return $this->organization->hasModule($module);
    }

    private function signal(string $type, string $kind, ?string $date, string $label, string $detail, ?int $rate): array
    {
        return compact('type', 'kind', 'date', 'label', 'detail', 'rate');
    }

    public static function rate(int $used, int $capacity): ?int
    {
        return $capacity > 0 ? (int) min(100, round($used / $capacity * 100)) : null;
    }

    public static function dayLabel(Carbon $day, Carbon $today): string
    {
        return match ((int) $today->diffInDays($day)) {
            0 => 'aujourd’hui',
            1 => 'demain',
            default => mb_strtolower($day->locale('fr')->translatedFormat('l j')),
        };
    }
}
