<?php

namespace App\Services;

use App\Jobs\RecalculateLoyaltyJob;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramRule;
use App\Models\Organization;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fidélité par enseigne. L'état de chaque client (carte) et son journal sont
 * entièrement reconstruits en rejouant ses tickets dans l'ordre : le résultat
 * ne dépend que des tickets, des remises saisies et de la date du jour. Un
 * recalcul ou un réimport ne peut donc ni doubler ni perdre de points.
 *
 * Compteur séparé par client, enseigne et programme, tous magasins de
 * l'enseigne confondus. Les points viennent du cumul du cycle en cours :
 * floor(cumul / tranche) × points par tranche + bonus d'activation.
 * Un cycle se termine par l'utilisation de la remise (le client reste membre,
 * sans nouveau bonus) ou par l'expiration (il doit repasser le seuil et
 * reçoit alors un nouveau bonus). Détails : docs/fidelite-enseignes.md.
 */
class LoyaltyEngine
{
    private const CHUNK = 500;

    /** @var array<int, true> Tickets dont la remise est déjà appliquée par un programme plus récent. */
    private array $discountApplied = [];

    /**
     * Lance le recalcul en file ; rien pour une entreprise sans programme.
     *
     * @param  list<int|null>|null  $customerIds  null = toute l'entreprise
     */
    public static function queue(int $organizationId, ?array $customerIds = null): void
    {
        if ($customerIds !== null) {
            $customerIds = array_values(array_unique(array_filter($customerIds)));
        }

        if ($customerIds === [] || ! LoyaltyProgram::where('organization_id', $organizationId)->exists()) {
            return;
        }

        RecalculateLoyaltyJob::dispatch($organizationId, $customerIds);
    }

    /**
     * @param  list<int>|null  $customerIds  null = toute l'entreprise
     * @return int nombre de cartes écrites
     */
    public function recalculate(Organization $organization, ?array $customerIds = null, ?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();
        $written = 0;
        $this->discountApplied = [];

        // Du plus récent au plus ancien : une remise ne s'applique qu'à une carte.
        foreach (LoyaltyProgram::where('organization_id', $organization->id)->with('rules')->orderByDesc('starts_on')->get() as $program) {
            // Enseigne retirée du programme : ses cartes disparaissent.
            foreach (['loyalty_cards', 'loyalty_movements'] as $table) {
                DB::table($table)->where('loyalty_program_id', $program->id)->whereNotIn('brand_id', $program->rules->pluck('brand_id'))->delete();
            }

            foreach ($program->rules as $rule) {
                $written += $this->recalculateRule($organization, $program, $rule, $customerIds, $today);
            }
        }

        return $written;
    }

    /** @param list<int>|null $customerIds */
    private function recalculateRule(Organization $organization, LoyaltyProgram $program, LoyaltyProgramRule $rule, ?array $customerIds, CarbonImmutable $today): int
    {
        $ids = $customerIds ?? $this->customersOf($program, $rule);
        $base = ['organization_id' => $organization->id, 'loyalty_program_id' => $program->id, 'brand_id' => $rule->brand_id];
        $settings = $this->ruleArray($rule, $organization->currencySymbol());
        $written = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $events = $this->events($program, $rule, $chunk);
            $cards = [];
            $movements = [];

            foreach ($chunk as $customerId) {
                if (! isset($events[$customerId])) {
                    continue;
                }

                [$card, $log] = self::replay($settings, $program->ends_on->toDateString(), $events[$customerId], $today);
                $cards[] = [...$base, 'customer_id' => $customerId, ...$card, 'computed_at' => now()];

                foreach ($log as $line) {
                    $movements[] = [...$base, 'customer_id' => $customerId, ...$line];

                    if ($line['type'] === LoyaltyMovement::DISCOUNT_USED && $line['purchase_id'] !== null) {
                        $this->discountApplied[$line['purchase_id']] = true;
                    }
                }
            }

            DB::transaction(function () use ($program, $rule, $chunk, $cards, $movements) {
                foreach (['loyalty_cards', 'loyalty_movements'] as $table) {
                    DB::table($table)->where('loyalty_program_id', $program->id)->where('brand_id', $rule->brand_id)->whereIn('customer_id', $chunk)->delete();
                }

                foreach (array_chunk($cards, self::CHUNK) as $rows) {
                    DB::table('loyalty_cards')->insert($rows);
                }

                foreach (array_chunk($movements, self::CHUNK) as $rows) {
                    DB::table('loyalty_movements')->insert($rows);
                }
            });

            $written += count($cards);
        }

        return $written;
    }

    /**
     * Rejoue les événements d'un client (tickets et remises saisies) et rend
     * sa carte et son journal.
     *
     * @param  array{threshold: int, tranche: int, per_tranche: int, bonus: int, tiers: list<array{points: int, percent: int}>, validity: int, reset: bool}  $rule
     * @param  list<array{date: string, kind: string, amount: int, percent: int|null, id: int|null, discount: int}>  $events  triés
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public static function replay(array $rule, string $endsOn, array $events, CarbonImmutable $today): array
    {
        $status = LoyaltyCard::STATUS_NONE;
        $cycle = $total = $bonus = $points = $used = 0;
        $activated = $expires = $lastPurchase = $reachedTier = $manualDay = null;
        $log = [];

        $write = function (string $type, int $delta, string $date, ?int $purchaseId = null, ?int $amount = null, ?string $note = null, ?int $percent = null) use (&$log, &$points) {
            $log[] = ['type' => $type, 'points' => $delta, 'balance' => $points, 'amount' => $amount, 'discount_percent' => $percent, 'happened_on' => $date, 'purchase_id' => $purchaseId, 'note' => $note];
        };

        $expire = function (string $date) use (&$status, &$expires, &$points, &$cycle, &$bonus, &$reachedTier, $write) {
            if ($status !== LoyaltyCard::STATUS_ACTIVE || $date <= $expires) {
                return;
            }

            $lost = $points;
            $status = LoyaltyCard::STATUS_EXPIRED;
            $points = $cycle = $bonus = 0;
            $reachedTier = null;
            $write(LoyaltyMovement::EXPIRATION, -$lost, CarbonImmutable::parse($expires)->addDay()->toDateString(), note: 'Validité terminée le '.CarbonImmutable::parse($expires)->format('d/m/Y'));
        };

        $useDiscount = function (array $event) use (&$status, &$points, &$cycle, &$bonus, &$used, &$reachedTier, $rule, $endsOn, $write) {
            if ($status !== LoyaltyCard::STATUS_ACTIVE) {
                // Après la fin du programme, ce ticket ne concerne simplement pas cette carte.
                if ($event['date'] <= $endsOn) {
                    $write(LoyaltyMovement::DISCOUNT_IGNORED, 0, $event['date'], $event['id'], note: 'Client non membre actif à cette date');
                }

                return;
            }

            $tier = self::tiers($rule, $points);
            $percent = $event['percent'] ?: $tier['percent'];
            $label = $event['label'] ?? ($event['percent'] ? "Remise de {$event['percent']} %" : $tier['label']);
            $cost = ($event['discount'] ?? 0) ?: $tier['cost'];
            $lost = 0;
            $used++;

            if ($rule['reset']) {
                $lost = $points;
                $points = $cycle = $bonus = 0;
                $reachedTier = null;
            }

            $write(LoyaltyMovement::DISCOUNT_USED, -$lost, $event['date'], $event['id'], $cost ?: null, $label, $percent);
        };

        foreach ($events as $event) {
            $expire($event['date']);

            if ($event['kind'] === 'manual') {
                $manualDay = $event['date'];
                $useDiscount($event);

                continue;
            }

            if ($event['percent'] !== null && $event['date'] !== $manualDay) {
                $useDiscount($event);
            }

            // Aucun gain après la fin du programme (le ticket n'était lu que pour sa remise).
            if ($event['date'] > $endsOn) {
                continue;
            }

            $total += $event['amount'];
            $cycle = max(0, $cycle + $event['amount']);
            $lastPurchase = $event['date'];
            $earned = intdiv($cycle, $rule['tranche']) * $rule['per_tranche'];

            if ($status !== LoyaltyCard::STATUS_ACTIVE) {
                if ($cycle < $rule['threshold']) {
                    continue;
                }

                $status = LoyaltyCard::STATUS_ACTIVE;
                $activated = $event['date'];
                $expires = CarbonImmutable::parse($event['date'])->addMonthsNoOverflow($rule['validity'])->toDateString();
                $bonus = $rule['bonus'];
                $points = $earned;
                $write(LoyaltyMovement::ACTIVATION, $earned, $event['date'], $event['id'], $cycle, 'Seuil atteint');

                if ($bonus > 0) {
                    $points += $bonus;
                    $write(LoyaltyMovement::BONUS, $bonus, $event['date'], $event['id']);
                }
            } else {
                $delta = $earned + $bonus - $points;
                $points += $delta;

                if ($delta !== 0 || $event['amount'] < 0) {
                    $write($event['amount'] < 0 ? LoyaltyMovement::RETURN : LoyaltyMovement::GAIN, $delta, $event['date'], $event['id'], $event['amount']);
                }
            }

            $tier = self::tiers($rule, $points);

            if ($tier['points'] !== null && $tier['points'] > (int) $reachedTier) {
                $write(LoyaltyMovement::TIER, 0, $event['date'], $event['id'], note: "Palier {$tier['points']} points : {$tier['label']}", percent: $tier['percent']);
            }
            $reachedTier = $tier['points'];
        }

        $expire($today->toDateString());
        $tier = self::tiers($rule, $points);
        $active = $status === LoyaltyCard::STATUS_ACTIVE;

        return [[
            'status' => $status,
            'activated_on' => $activated,
            'expires_on' => $expires,
            'points' => $points,
            'cycle_amount' => $cycle,
            'program_amount' => $total,
            'discount_percent' => $active ? $tier['percent'] : null,
            'reward_label' => $active ? $tier['label'] : null,
            'tier_points' => $active ? $tier['points'] : null,
            'next_tier_points' => $tier['next'],
            'points_to_next_tier' => $tier['next'] !== null ? $tier['next'] - $points : null,
            'amount_to_threshold' => $active ? 0 : max(0, $rule['threshold'] - $cycle),
            'discounts_used' => $used,
            'last_purchase_on' => $lastPurchase,
        ], $log];
    }

    /**
     * Palier atteint et palier suivant pour un nombre de points.
     *
     * @param  array{tiers: list<array{points: int, percent: int}>}  $rule
     * @return array{points: int|null, percent: int|null, label: string|null, cost: int, next: int|null}
     */
    private static function tiers(array $rule, int $points): array
    {
        $tiers = collect($rule['tiers'])->sortBy('points');
        $current = $tiers->filter(fn ($t) => $points >= $t['points'])->last();

        return [
            'points' => $current['points'] ?? null,
            'percent' => ($current['percent'] ?? 0) ?: null,
            'label' => $current === null ? null : ($current['label'] ?? "Remise de {$current['percent']} %"),
            'cost' => $current['cost'] ?? 0,
            'next' => $tiers->first(fn ($t) => $points < $t['points'])['points'] ?? null,
        ];
    }

    /**
     * Règle sous la forme lue par replay().
     *
     * @return array{threshold: int, tranche: int, per_tranche: int, bonus: int, tiers: list<array{points: int, percent: int}>, validity: int, reset: bool}
     */
    private function ruleArray(LoyaltyProgramRule $rule, string $currency): array
    {
        return [
            'threshold' => $rule->threshold,
            'tranche' => max(1, $rule->tranche_amount),
            'per_tranche' => $rule->points_per_tranche,
            'bonus' => $rule->activation_bonus,
            'tiers' => array_map(fn ($t) => LoyaltyProgramRule::reward($t, $currency), $rule->tiers ?? []),
            'validity' => $rule->validity_months,
            'reset' => $rule->reset_after_use,
        ];
    }

    /**
     * Clients ayant un ticket, une carte ou une remise dans cette enseigne du programme.
     *
     * @return list<int>
     */
    private function customersOf(LoyaltyProgram $program, LoyaltyProgramRule $rule): array
    {
        return $this->ticketQuery($program, $rule)->distinct()->pluck('contacts.customer_id')
            ->merge(DB::table('loyalty_cards')->where('loyalty_program_id', $program->id)->where('brand_id', $rule->brand_id)->pluck('customer_id'))
            ->merge(DB::table('loyalty_redemptions')->where('loyalty_program_id', $program->id)->where('brand_id', $rule->brand_id)->pluck('customer_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function ticketQuery(LoyaltyProgram $program, LoyaltyProgramRule $rule): Builder
    {
        return DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->join('stores', 'stores.id', '=', 'purchases.store_id')
            ->where('stores.brand_id', $rule->brand_id)
            ->whereNotNull('contacts.customer_id')
            ->where('purchases.status', Purchase::STATUS_VALID)
            ->where('purchases.purchased_at', '>=', $program->starts_on->toDateString())
            // Après la fin : seuls les tickets portant une remise sont lus.
            ->where(fn ($q) => $q->where('purchases.purchased_at', '<=', $program->ends_on->toDateString().' 23:59:59')->orWhereNotNull('purchases.loyalty_discount_percent'));
    }

    /**
     * Événements par client, dans l'ordre. Une remise saisie passe avant les
     * tickets du même jour : ces tickets comptent dans le nouveau cumul.
     *
     * @param  list<int>  $customerIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function events(LoyaltyProgram $program, LoyaltyProgramRule $rule, array $customerIds): array
    {
        $events = [];

        $tickets = $this->ticketQuery($program, $rule)
            ->whereIn('contacts.customer_id', $customerIds)
            ->get(['contacts.customer_id', 'purchases.id', 'purchases.purchased_at', 'purchases.amount', 'purchases.discount_amount', 'purchases.loyalty_discount_percent', 'purchases.loyalty_reward_label']);

        foreach ($tickets as $t) {
            if (isset($this->discountApplied[$t->id])) {
                continue;
            }

            $percent = $t->loyalty_discount_percent !== null ? (int) $t->loyalty_discount_percent : null;
            $discount = (int) $t->discount_amount ?: ($percent > 0 && $percent < 100 ? (int) round($t->amount * $percent / (100 - $percent)) : 0);
            $events[(int) $t->customer_id][] = ['date' => substr((string) $t->purchased_at, 0, 10), 'kind' => 'ticket', 'amount' => (int) $t->amount, 'percent' => $percent, 'id' => (int) $t->id, 'discount' => max(0, $discount), 'label' => $t->loyalty_reward_label];
        }

        $redemptions = DB::table('loyalty_redemptions')->where('loyalty_program_id', $program->id)->where('brand_id', $rule->brand_id)
            ->whereIn('customer_id', $customerIds)->get();

        foreach ($redemptions as $r) {
            $events[(int) $r->customer_id][] = ['date' => substr((string) $r->used_on, 0, 10), 'kind' => 'manual', 'amount' => 0, 'percent' => (int) $r->discount_percent, 'id' => null, 'discount' => (int) $r->amount, 'label' => $r->reward_label];
        }

        foreach ($events as &$list) {
            usort($list, fn ($a, $b) => [$a['date'], $a['kind'] === 'manual' ? 0 : 1, $a['id']] <=> [$b['date'], $b['kind'] === 'manual' ? 0 : 1, $b['id']]);
        }

        return $events;
    }
}
