<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Organization;
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

        $buyers = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->join('customers', 'customers.id', '=', 'contacts.customer_id')
            ->where('customers.organization_id', $organization->id)
            ->groupBy('contacts.customer_id')
            ->selectRaw('contacts.customer_id as id, DATE(MIN(purchases.purchased_at)) as first_at, DATE(MAX(purchases.purchased_at)) as last_at, COUNT(*) as cnt, COALESCE(SUM(purchases.amount), 0) as total')
            ->get();

        $total = $buyers->count();
        $rank = fn (string $field) => self::ranker($buyers->pluck($field)->map(fn ($v) => is_numeric($v) ? (int) $v : (string) $v)->all(), $total);
        $r = $rank('last_at');
        $f = $rank('cnt');
        $m = $rank('total');

        $stages = Customer::where('organization_id', $organization->id)->pluck('lifecycle_stage', 'id');
        $now = now();
        $updated = 0;

        DB::transaction(function () use ($buyers, $r, $f, $m, $stages, $today, $now, $organization, &$updated) {
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
                    'rfm_segment' => RfmCalculator::segmentKey($scores['r'], $scores['f'], $scores['m'], $frequency),
                    'score' => self::score($scores['r'], $scores['f'], $scores['m']),
                    'avg_purchase_interval_days' => $interval,
                    'next_purchase_expected_at' => $expected,
                    'churn_risk' => $risk,
                    'scored_at' => $now,
                ]);
                $updated++;
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

        return $updated;
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
    private static function ranker(array $values, int $total): callable
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
