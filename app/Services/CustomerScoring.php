<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Segmentation RFM, score client et risque de churn de toutes les fiches
 * d'une organisation, enregistrés sur les fiches (listes, filtres, actions).
 *
 * - Scores R, F, M de 1 à 5 : quintile du client parmi tous les acheteurs de
 *   l'organisation (mêmes règles que RfmCalculator, qui calcule à la volée le
 *   profil d'une fiche sur le périmètre d'un rôle) ; segment déduit des scores.
 * - Score client de 0 à 100 : 40 % récence, 30 % fréquence, 30 % montant.
 * - Risque de churn, pour les clients qui ont au moins deux achats et ne sont
 *   pas encore « Inactifs » : « high » quand l'absence dépasse deux fois leur
 *   intervalle habituel entre deux achats (ou qu'ils sont « À risque »),
 *   « medium » au-delà d'une fois et demie.
 *
 * Recalculé chaque nuit (crm:refresh-lifecycle) et après un import d'achats.
 */
class CustomerScoring
{
    /** Absence minimale (jours) avant une alerte, même pour un client très régulier. */
    public const MIN_MEDIUM_DAYS = 21;

    public const MIN_HIGH_DAYS = 30;

    public const CHURN_RISKS = [
        'medium' => 'Risque moyen',
        'high' => 'Risque élevé',
    ];

    public function refreshOrganization(Organization $organization): int
    {
        $today = now()->startOfDay();
        $rules = $organization->rfmSettings();

        $buyers = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->join('customers', 'customers.id', '=', 'contacts.customer_id')
            ->where('customers.organization_id', $organization->id)
            ->where('purchases.status', Purchase::STATUS_VALID)
            // Période d'analyse RFM réglée par l'entreprise (0 = tout l'historique).
            ->when($rules['period_months'] > 0, fn ($q) => $q->where('purchases.purchased_at', '>=', $today->copy()->subMonths($rules['period_months'])->toDateString()))
            ->groupBy('contacts.customer_id')
            ->havingRaw(Purchase::SQL_SALES_COUNT.' > 0')
            ->selectRaw('contacts.customer_id as id, DATE('.Purchase::SQL_FIRST_SALE.') as first_at, DATE('.Purchase::SQL_LAST_SALE.') as last_at, '.Purchase::SQL_SALES_COUNT.' as cnt, COALESCE(SUM(purchases.amount), 0) as total')
            ->get();

        $total = $buyers->count();
        $rank = fn (string $field) => self::ranker($buyers->pluck($field)->map(fn ($v) => is_numeric($v) ? (int) $v : (string) $v)->all(), $total);
        $r = $rank('last_at');
        $f = $rank('cnt');
        $m = $rank('total');

        $stages = Customer::where('organization_id', $organization->id)->pluck('lifecycle_stage', 'id');
        $now = now();
        $updated = 0;

        $previous = Customer::where('organization_id', $organization->id)->pluck('rfm_segment', 'id');
        $history = [];

        DB::transaction(function () use ($buyers, $r, $f, $m, $stages, $today, $now, $organization, $rules, $previous, &$history, &$updated) {
            foreach ($buyers as $buyer) {
                $scores = ['r' => $r($buyer->last_at), 'f' => $f((int) $buyer->cnt), 'm' => $m((int) $buyer->total)];
                $frequency = (int) $buyer->cnt;
                $interval = null;
                $expected = null;
                $risk = null;

                if ($frequency >= 2) {
                    $span = Carbon::parse($buyer->first_at)->diffInDays(Carbon::parse($buyer->last_at));
                    $interval = max(1, (int) round($span / ($frequency - 1)));
                    $expected = Carbon::parse($buyer->last_at)->addDays($interval)->toDateString();
                    $risk = self::churnRisk(
                        (int) Carbon::parse($buyer->last_at)->diffInDays($today),
                        $interval,
                        $stages[$buyer->id] ?? null,
                    );
                }

                DB::table('customers')->where('id', $buyer->id)->update([
                    'rfm_r' => $scores['r'],
                    'rfm_f' => $scores['f'],
                    'rfm_m' => $scores['m'],
                    'rfm_segment' => $segment = RfmCalculator::segmentKey($scores['r'], $scores['f'], $scores['m'], $frequency, $rules),
                    'score' => self::score($scores['r'], $scores['f'], $scores['m']),
                    'avg_purchase_interval_days' => $interval,
                    'next_purchase_expected_at' => $expected,
                    'churn_risk' => $risk,
                    'scored_at' => $now,
                ]);
                $updated++;

                // Historique : une ligne à chaque changement de segment du groupe.
                if (($previous[$buyer->id] ?? null) !== $segment) {
                    $history[] = ['organization_id' => $organization->id, 'customer_id' => $buyer->id, 'brand_id' => null, 'segment' => $segment,
                        'r' => $scores['r'], 'f' => $scores['f'], 'm' => $scores['m'], 'computed_on' => $today->toDateString(),
                        'period_months' => $rules['period_months'], 'rules_version' => $rules['version'], 'created_at' => $now];
                }
            }

            // Fiches sans achat : segment « pas encore d'achat », pas de score.
            DB::table('customers')
                ->where('organization_id', $organization->id)
                ->whereNotIn('id', $buyers->pluck('id'))
                ->update([
                    'rfm_r' => null, 'rfm_f' => null, 'rfm_m' => null,
                    'rfm_segment' => 'none', 'score' => null,
                    'avg_purchase_interval_days' => null, 'next_purchase_expected_at' => null,
                    'churn_risk' => null, 'scored_at' => $now,
                ]);
        });

        foreach (array_chunk($history, 500) as $chunk) {
            DB::table('customer_segment_history')->insert($chunk);
        }

        $this->applyEngagementSignals($organization);

        return $updated;
    }

    /** Absences sur 30 jours qui déclenchent l'alerte : moyenne, puis élevée. */
    public const ABSENCES_MEDIUM = 3;

    public const ABSENCES_HIGH = 5;

    /** Retard de paiement (jours) qui rend l'alerte élevée. */
    public const UNPAID_HIGH_DAYS = 30;

    /**
     * Alerte de départ enrichie par les modules : un adhérent qui manque ses
     * cours ou ne paie plus ses tranches risque de partir, même si son rythme
     * d'achat semble normal. On ne baisse jamais une alerte, on la relève.
     */
    private function applyEngagementSignals(Organization $organization): void
    {
        $raise = function ($ids, string $level) use ($organization) {
            DB::table('customers')->where('organization_id', $organization->id)->whereIn('id', $ids)
                ->when($level === 'medium', fn ($q) => $q->whereNull('churn_risk'), fn ($q) => $q->where(fn ($w) => $w->whereNull('churn_risk')->orWhere('churn_risk', '!=', 'high')))
                ->update(['churn_risk' => $level]);
        };

        if ($organization->hasModule('seances_groupe')) {
            $raise(CustomerSearch::followQuery($organization->id, 'absent:'.self::ABSENCES_MEDIUM), 'medium');
            $raise(CustomerSearch::followQuery($organization->id, 'absent:'.self::ABSENCES_HIGH), 'high');
        }

        if ($organization->hasModule('tranches') || $organization->hasModule('abonnements')) {
            $raise(CustomerSearch::followQuery($organization->id, 'unpaid'), 'medium');
            $late = DB::table('installments')->join('payment_schedules', 'payment_schedules.id', '=', 'installments.payment_schedule_id')
                ->where('payment_schedules.organization_id', $organization->id)->where('payment_schedules.status', 'active')
                ->whereNull('installments.paid_at')->where('installments.due_on', '<', today()->subDays(self::UNPAID_HIGH_DAYS)->toDateString());
            $raise((clone $late)->select('payment_schedules.customer_id'), 'high');
            $raise((clone $late)->whereNotNull('payment_schedules.beneficiary_id')->select('payment_schedules.beneficiary_id'), 'high');
        }
    }

    /**
     * Signaux des modules sur une fiche (fiche client : « pourquoi cette alerte »).
     *
     * @return list<string>
     */
    public static function signals(Customer $customer, Organization $organization): array
    {
        $signals = [];

        if ($organization->hasModule('seances_groupe')) {
            $absences = DB::table('attendances')->join('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
                ->where('attendances.customer_id', $customer->id)->where('attendances.status', 'absent')
                ->where('class_sessions.session_date', '>=', today()->subDays(30)->toDateString())->count();
            if ($absences > 0) {
                $signals[] = "{$absences} absence(s) aux cours ces 30 derniers jours";
            }
        }

        if ($organization->hasModule('tranches') || $organization->hasModule('abonnements')) {
            $overdue = DB::table('installments')->join('payment_schedules', 'payment_schedules.id', '=', 'installments.payment_schedule_id')
                ->where(fn ($q) => $q->where('payment_schedules.customer_id', $customer->id)->orWhere('payment_schedules.beneficiary_id', $customer->id))
                ->where('payment_schedules.status', 'active')->whereNull('installments.paid_at')->where('installments.due_on', '<', today()->toDateString());
            $count = (clone $overdue)->count();
            if ($count > 0) {
                $signals[] = "{$count} tranche(s) en retard (".number_format((int) (clone $overdue)->sum('installments.amount'), 0, ',', ' ').' FCFA)';
            }
        }

        return $signals;
    }

    public static function score(int $r, int $f, int $m): int
    {
        return (int) round((($r - 1) * 0.4 + ($f - 1) * 0.3 + ($m - 1) * 0.3) / 4 * 100);
    }

    public static function churnRisk(int $daysSinceLast, int $interval, ?string $stage): ?string
    {
        if ($stage === Customer::STAGE_CHURNED) {
            return null;
        }

        if ($stage === Customer::STAGE_AT_RISK || $daysSinceLast > max(2 * $interval, self::MIN_HIGH_DAYS)) {
            return 'high';
        }

        return $daysSinceLast > max((int) ceil(1.5 * $interval), self::MIN_MEDIUM_DAYS) ? 'medium' : null;
    }

    /**
     * Fonction valeur → quintile (rang moyen pour les ex-aequo), calculée une
     * fois pour toutes les valeurs.
     *
     * @param  array<int, int|string>  $values
     * @return callable(int|string): int
     */
    public static function ranker(array $values, int $total): callable
    {
        $counts = array_count_values(array_map('strval', $values));
        uksort($counts, fn ($a, $b) => is_numeric($a) && is_numeric($b) ? $a <=> $b : strcmp((string) $a, (string) $b));

        $quintiles = [];
        $below = 0;

        foreach ($counts as $value => $equal) {
            $quintiles[(string) $value] = RfmCalculator::quintile($below, $equal, $total);
            $below += $equal;
        }

        return fn ($value) => $quintiles[(string) $value] ?? 3;
    }
}
