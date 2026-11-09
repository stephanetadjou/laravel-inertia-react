<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Abonnements : création d'un abonnement et de son échéancier.
 *
 * - au mois : une mensualité par mois jusqu'à la fin (fin de saison par
 *   défaut), la première à la date de début, les suivantes au jour
 *   d'échéance choisi (Paramètres › Modules) ;
 * - à la saison : le prix de la saison, en une ou plusieurs tranches ;
 * - carte de séances, paiement unique : une tranche à la date de début.
 *
 * Frais uniques (inscription, assurance) et options non récurrentes (tenue)
 * sont dus à la date de début ; les options récurrentes (transport)
 * s'ajoutent à chaque mensualité ou tranche.
 */
class Subscriptions
{
    public function __construct(private Installments $installments) {}

    /**
     * @param  array{customer_id: int, store_id: int, plan_id: int, starts_on: string, ends_on?: string|null, options?: list<int>, season_installments?: int|null}  $data
     */
    public function create(User $user, array $data): Subscription
    {
        $organization = $user->organization;
        $plan = Plan::where('organization_id', $organization->id)->findOrFail($data['plan_id']);
        $member = Customer::findOrFail($data['customer_id']);
        $payer = $member->recipient();
        $start = Carbon::parse($data['starts_on'])->startOfDay();
        $settings = $organization->moduleSettings('abonnements');
        $seasonEnd = Carbon::parse($settings['season_end']);
        $end = filled($data['ends_on'] ?? null) ? Carbon::parse($data['ends_on']) : match ($plan->period) {
            'month', 'season' => $seasonEnd->gt($start) ? $seasonEnd : $start->copy()->addYear()->subDay(),
            default => null,
        };

        $chosen = collect($plan->options ?? [])->only($data['options'] ?? [])->values();
        $recurring = (int) $chosen->where('recurring', true)->sum('amount');
        $lines = [];

        foreach ([...($plan->fees ?? []), ...$chosen->where('recurring', '!=', true)->all()] as $fee) {
            if ((int) ($fee['amount'] ?? 0) > 0) {
                $lines[] = ['label' => $fee['label'], 'amount' => (int) $fee['amount'], 'due_on' => $start->toDateString()];
            }
        }

        $parts = max(1, (int) ($data['season_installments'] ?? 1));
        $lines = [...$lines, ...match ($plan->period) {
            'month' => $this->monthly($start, $end, (int) $plan->price + $recurring, (int) $organization->moduleSettings('tranches')['due_day']),
            'season' => collect(Installments::split((int) $plan->price + $recurring, $parts, $start))
                ->map(fn ($line, $i) => [...$line, 'label' => $plan->name.($parts > 1 ? ' — tranche '.($i + 1) : '')])->all(),
            default => [['label' => $plan->name, 'amount' => (int) $plan->price + $recurring, 'due_on' => $start->toDateString()]],
        }];

        $subscription = DB::transaction(function () use ($user, $data, $plan, $member, $payer, $start, $end, $chosen, $lines) {
            $subscription = Subscription::create([
                'organization_id' => $user->organization_id,
                'store_id' => $data['store_id'],
                'customer_id' => $payer->id,
                'beneficiary_id' => $payer->isNot($member) ? $member->id : null,
                'plan_id' => $plan->id,
                'starts_on' => $start,
                'ends_on' => $end,
                'sessions_left' => $plan->period === 'sessions' ? $plan->sessions_count : null,
                'price' => (int) $plan->price,
                'options' => $chosen->all(),
                'created_by' => $user->id,
                'planning' => SubscriptionPlanning::normalize($data['planning'] ?? []) ?: null,
                'session_minutes' => $data['session_minutes'] ?? null,
                'coached' => (bool) ($data['coached'] ?? false),
                'staff_member_id' => ! empty($data['coached']) ? ($data['staff_member_id'] ?? null) : null,
            ]);

            $schedule = $this->installments->createSchedule($user, [
                'customer_id' => $payer->id,
                'beneficiary_id' => $subscription->beneficiary_id,
                'store_id' => (int) $data['store_id'],
                'catalog_item_id' => $plan->catalog_item_id,
                'subscription_id' => $subscription->id,
                'label' => $plan->name,
                'installments' => $lines,
            ]);
            $subscription->update(['payment_schedule_id' => $schedule->id]);

            Audit::record('subscription.created', $subscription, "Abonnement « {$plan->name} » pour {$member->name}", [], $user);

            return $subscription;
        });

        // Séances planifiées : elles remplissent l'agenda.
        SubscriptionPlanning::sync($subscription->fresh(['store.organization', 'plan', 'customer', 'beneficiary']));

        return $subscription;
    }

    /** Arrêt d'un abonnement : les tranches non payées sont annulées avec l'échéancier. */
    public function cancel(Subscription $subscription, User $user): void
    {
        DB::transaction(function () use ($subscription, $user) {
            $subscription->update(['status' => Subscription::STATUS_CANCELLED, 'ends_on' => $subscription->ends_on?->lt(today()) ? $subscription->ends_on : today()]);

            if ($subscription->schedule) {
                $this->installments->cancel($subscription->schedule, $user);
            }
        });

        // Abonnement arrêté : ses séances à venir quittent l'agenda.
        SubscriptionPlanning::replace($subscription->fresh());
    }

    /** @return list<array{label: string, amount: int, due_on: string}> */
    private function monthly(Carbon $start, ?Carbon $end, int $amount, int $dueDay): array
    {
        $lines = [];
        $month = $start->copy()->startOfMonth();
        $last = ($end ?? $start->copy()->addMonths(11))->copy()->startOfMonth();

        while ($month->lte($last) && count($lines) < 24) {
            $due = $month->isSameMonth($start) ? $start->copy() : $month->copy()->day(min($dueDay, $month->daysInMonth));
            $lines[] = [
                'label' => 'Mensualité '.$month->copy()->locale('fr')->translatedFormat('F Y'),
                'amount' => $amount,
                'due_on' => $due->toDateString(),
            ];
            $month->addMonth();
        }

        return $lines;
    }
}
