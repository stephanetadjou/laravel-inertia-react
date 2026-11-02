<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ProspectStageChange;
use App\Models\Purchase;
use App\Models\SalesTarget;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Suivi des commerciaux (module « prospects ») : résultats du mois par
 * commercial (visites et démos honorées de l'agenda, passages en essai et en
 * client, CA de ses clients), objectifs et objectif à date (prorata du mois),
 * taux de passage, classement et primes (dues au 2e paiement du client apporté).
 */
class SalesTeam
{
    public const INDICATORS = [
        'visits' => 'Visites',
        'demos' => 'Démos',
        'trials' => 'Essais',
        'customers' => 'Clients payants',
        'revenue' => 'Revenu ajouté',
    ];

    private Carbon $from;

    private Carbon $to;

    public function __construct(private Organization $organization, ?string $month = null)
    {
        $this->from = Carbon::parse(($month ?? today()->format('Y-m')).'-01')->startOfMonth();
        $this->to = $this->from->copy()->addMonth();
    }

    public function month(): string
    {
        return $this->from->format('Y-m');
    }

    /** Part du mois écoulée (1 pour un mois passé, 0 pour un mois futur). */
    public function elapsed(): float
    {
        if (today()->gte($this->to)) {
            return 1.0;
        }
        if (today()->lt($this->from)) {
            return 0.0;
        }

        return today()->day / $this->from->daysInMonth;
    }

    /**
     * Membres de l'équipe : les utilisateurs de l'entreprise, un commercial ne
     * voyant que lui-même.
     *
     * @return Collection<int, User>
     */
    public function members(User $viewer): Collection
    {
        return User::where('organization_id', $this->organization->id)
            ->when($viewer->ownRecordsOnly(), fn ($q) => $q->whereKey($viewer->id))
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, int> */
    public function actuals(User $user): array
    {
        $kindDone = fn (string $kind) => Appointment::query()
            ->whereHas('store', fn ($q) => $q->where('organization_id', $this->organization->id))
            ->where('kind', $kind)->where('status', 'done')
            ->where('starts_at', '>=', $this->from)->where('starts_at', '<', $this->to)
            ->where(fn ($q) => $q->where('staff_user_id', $user->id)->orWhere(fn ($w) => $w->whereNull('staff_user_id')->where('created_by', $user->id)))
            ->count();
        $reached = fn (string $kind) => ProspectStageChange::where('owner_user_id', $user->id)->where('to_kind', $kind)
            ->where('changed_at', '>=', $this->from)->where('changed_at', '<', $this->to)
            ->distinct()->count('customer_id');
        $owned = Customer::where('organization_id', $this->organization->id)->where('owner_user_id', $user->id)->select('id');

        return [
            'visits' => $kindDone('visite'),
            'demos' => $kindDone('demo'),
            'trials' => $reached('en_essai'),
            'customers' => $reached('gagne'),
            'revenue' => (int) Purchase::whereHas('contact', fn ($q) => $q->whereIn('customer_id', $owned))
                ->where('purchased_at', '>=', $this->from)->where('purchased_at', '<', $this->to)
                ->sum('amount'),
        ];
    }

    /** @return array<string, int> */
    public function targets(User $user): array
    {
        $target = SalesTarget::where('user_id', $user->id)->where('month', $this->month())->first();

        return collect(array_keys(self::INDICATORS))->mapWithKeys(fn ($k) => [$k => (int) ($target?->{$k} ?? 0)])->all();
    }

    /**
     * Taux de passage du commercial sur ses fiches : visite → démo, démo →
     * essai, essai → client (en %, null sans base).
     *
     * @return array<string, int|null>
     */
    public function conversion(User $user): array
    {
        $owned = Customer::where('organization_id', $this->organization->id)->where('owner_user_id', $user->id)->pluck('id');
        $byKind = fn (string $kind) => Appointment::whereIn('customer_id', $owned)->where('kind', $kind)->where('status', 'done')->distinct()->pluck('customer_id');
        $visited = $byKind('visite');
        $demoed = $byKind('demo')->merge(ProspectStageChange::whereIn('customer_id', $owned)->where('to_stage', 'demo')->pluck('customer_id'))->unique();
        $trial = ProspectStageChange::whereIn('customer_id', $owned)->where('to_kind', 'en_essai')->distinct()->pluck('customer_id');
        $won = ProspectStageChange::whereIn('customer_id', $owned)->where('to_kind', 'gagne')->distinct()->pluck('customer_id');
        $rate = fn (Collection $base, Collection $next) => $base->isEmpty() ? null : (int) round($base->intersect($next)->count() / $base->count() * 100);

        return [
            'visit_demo' => $rate($visited, $demoed),
            'demo_trial' => $rate($demoed, $trial),
            'trial_customer' => $rate($trial, $won),
        ];
    }

    /**
     * Tableau de l'équipe : une ligne par commercial, classée par atteinte de
     * l'objectif clients payants (puis revenu).
     *
     * @return list<array<string, mixed>>
     */
    public function board(User $viewer): array
    {
        $elapsed = $this->elapsed();
        $commissions = collect($this->commissions());

        return $this->members($viewer)
            ->map(function (User $u) use ($elapsed, $commissions) {
                $actual = $this->actuals($u);
                $target = $this->targets($u);
                $indicators = collect(self::INDICATORS)->map(fn ($label, $k) => [
                    'key' => $k,
                    'label' => $label,
                    'actual' => $actual[$k],
                    'target' => $target[$k],
                    'to_date' => (int) round($target[$k] * $elapsed),
                    'percent' => $target[$k] > 0 ? (int) round($actual[$k] / $target[$k] * 100) : null,
                ])->values()->all();
                $score = collect($indicators)->whereNotNull('percent')->avg('percent');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'indicators' => $indicators,
                    'conversion' => $this->conversion($u),
                    'score' => $score === null ? null : (int) round($score),
                    'behind' => collect($indicators)->filter(fn ($i) => $i['target'] > 0 && $i['actual'] < $i['to_date'])->pluck('label')->values()->all(),
                    'commission' => (int) $commissions->where('user_id', $u->id)->sum('amount'),
                ];
            })
            // Membres avec un objectif ou de l'activité (le directeur sans objectif n'apparaît pas).
            ->filter(fn ($row) => $row['score'] !== null || collect($row['indicators'])->sum('actual') > 0 || $viewer->ownRecordsOnly())
            ->sortByDesc(fn ($row) => [$row['score'] ?? -1, collect($row['indicators'])->firstWhere('key', 'revenue')['actual']])
            ->values()
            ->all();
    }

    /**
     * Primes du mois : pour chaque abonnement d'un client apporté par un
     * commercial, la prime est due quand le client fait son 2e paiement de
     * l'abonnement (frais d'inscription et options uniques non comptés), dans
     * le mois. Montant : grille par formule, sinon % du prix mensuel.
     *
     * @return list<array<string, mixed>>
     */
    public function commissions(): array
    {
        $settings = $this->organization->moduleSettings('prospects');
        $rows = [];

        $subscriptions = Subscription::where('organization_id', $this->organization->id)
            ->with(['plan', 'customer.owner:id,name', 'schedule.installments'])
            ->whereHas('customer', fn ($q) => $q->whereNotNull('owner_user_id'))
            ->get();

        foreach ($subscriptions as $s) {
            $oneOff = collect([...($s->plan?->fees ?? []), ...collect($s->options ?? [])->where('recurring', '!=', true)->all()])->pluck('label')->all();
            $paid = ($s->schedule?->installments ?? collect())
                ->whereNotNull('paid_at')
                ->reject(fn ($i) => in_array($i->label, $oneOff, true))
                ->sortBy('paid_at')
                ->values();
            $second = $paid->get(1);

            if ($second === null || $second->paid_at->lt($this->from) || $second->paid_at->gte($this->to)) {
                continue;
            }

            $grid = $settings['commission_grid'][$s->plan?->name] ?? null;
            $rows[] = [
                'user_id' => $s->customer->owner_user_id,
                'user' => $s->customer->owner?->name,
                'customer_id' => $s->customer_id,
                'customer' => $s->customer->company_name ?: $s->customer->name,
                'plan' => $s->plan?->name,
                'second_payment_at' => $second->paid_at->toDateString(),
                'amount' => (int) ($grid ?? round((int) $s->price * (int) $settings['commission_rate'] / 100)),
            ];
        }

        return collect($rows)->sortBy(['user', 'second_payment_at'])->values()->all();
    }
}
