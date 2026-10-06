<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Profil RFM (Récence, Fréquence, Montant) d'une fiche client, calculé à la volée.
 *
 * Seuls comptent les achats faits dans les boutiques données (le périmètre du
 * rôle : tout le Groupe pour un administrateur). Chaque score va de 1 à 5 :
 * c'est le quintile du client parmi tous les clients acheteurs de ces mêmes
 * boutiques (5 = achat le plus récent, le plus fréquent, le plus gros montant).
 * Les ex-aequo reçoivent le même score (rang moyen), un acheteur seul obtient 3.
 */
class RfmCalculator
{
    public const SEGMENTS = [
        'champions' => ['label' => 'Champions', 'action' => 'Vos meilleurs clients : remerciez-les et proposez-leur nouveautés et avant-premières.'],
        'new' => ['label' => 'Nouveaux clients', 'action' => 'Premier achat récent : soignez l\'accueil pour déclencher un deuxième achat.'],
        'loyal' => ['label' => 'Clients fidèles', 'action' => 'Achètent régulièrement : valorisez leur fidélité et suggérez des articles complémentaires.'],
        'potential' => ['label' => 'Potentiels', 'action' => 'Clients récents à développer : une offre ciblée peut augmenter leur fréquence et leur panier.'],
        'cant_lose' => ['label' => 'À ne pas perdre', 'action' => 'Gros clients absents depuis longtemps : prévoyez un contact personnel en priorité.'],
        'at_risk' => ['label' => 'À risque', 'action' => 'Bons clients qui s\'éloignent : relancez-les avec une offre personnalisée.'],
        'hibernating' => ['label' => 'Hibernants', 'action' => 'Peu actifs et peu engagés : une promotion ponctuelle peut les réveiller.'],
        'lost' => ['label' => 'Perdus', 'action' => 'Inactifs depuis longtemps : tentez une relance de dernière chance.'],
        'none' => ['label' => 'Pas encore d\'achat', 'action' => 'Aucun achat enregistré : invitez ce client en boutique avec une offre de bienvenue.'],
    ];

    /**
     * @param  array<int, int>  $storeIds
     * @return array{
     *     recency_days: int|null, last_purchase_at: string|null,
     *     frequency: int, frequency_12m: int, monetary: int, average_basket: int,
     *     scores: array{r: int, f: int, m: int}|null, buyers: int,
     *     segment: array{key: string, label: string, action: string}
     * }
     */
    public function forCustomer(Customer $customer, array $storeIds): array
    {
        $own = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->where('contacts.customer_id', $customer->id)
            ->whereIn('purchases.store_id', $storeIds)
            ->selectRaw('DATE(MAX(purchases.purchased_at)) as last_at, COUNT(*) as cnt, COALESCE(SUM(purchases.amount), 0) as total')
            ->selectRaw('SUM(CASE WHEN purchases.purchased_at >= ? THEN 1 ELSE 0 END) as cnt_12m', [now()->subYear()->toDateString()])
            ->first();

        $frequency = (int) $own->cnt;

        if ($frequency === 0) {
            return [
                'recency_days' => null,
                'last_purchase_at' => null,
                'frequency' => 0,
                'frequency_12m' => 0,
                'monetary' => 0,
                'average_basket' => 0,
                'scores' => null,
                'buyers' => DB::query()->fromSub($this->buyersQuery($storeIds), 'buyers')->count(),
                'segment' => self::segment('none'),
            ];
        }

        $lastPurchase = Carbon::parse($own->last_at)->startOfDay();
        $monetary = (int) $own->total;
        $scores = $this->scores($storeIds, $own->last_at, $frequency, $monetary);

        return [
            'recency_days' => (int) max(0, $lastPurchase->diffInDays(today())),
            'last_purchase_at' => $lastPurchase->toDateString(),
            'frequency' => $frequency,
            'frequency_12m' => (int) $own->cnt_12m,
            'monetary' => $monetary,
            'average_basket' => (int) round($monetary / $frequency),
            'scores' => ['r' => $scores['r'], 'f' => $scores['f'], 'm' => $scores['m']],
            'buyers' => $scores['buyers'],
            'segment' => self::segment(self::segmentKey($scores['r'], $scores['f'], $scores['m'], $frequency)),
        ];
    }

    /**
     * Une seule requête agrégée : pour chaque critère, combien d'acheteurs du
     * périmètre ont une valeur inférieure ou égale à celle du client.
     *
     * @param  array<int, int>  $storeIds
     * @return array{r: int, f: int, m: int, buyers: int}
     */
    private function scores(array $storeIds, string $lastAt, int $frequency, int $monetary): array
    {
        $row = DB::query()
            ->fromSub($this->buyersQuery($storeIds), 'buyers')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN last_at < ? THEN 1 ELSE 0 END) as r_below, SUM(CASE WHEN last_at = ? THEN 1 ELSE 0 END) as r_equal', [$lastAt, $lastAt])
            ->selectRaw('SUM(CASE WHEN cnt < ? THEN 1 ELSE 0 END) as f_below, SUM(CASE WHEN cnt = ? THEN 1 ELSE 0 END) as f_equal', [$frequency, $frequency])
            ->selectRaw('SUM(CASE WHEN total < ? THEN 1 ELSE 0 END) as m_below, SUM(CASE WHEN total = ? THEN 1 ELSE 0 END) as m_equal', [$monetary, $monetary])
            ->first();

        $total = (int) $row->total;

        return [
            'r' => self::quintile((int) $row->r_below, (int) $row->r_equal, $total),
            'f' => self::quintile((int) $row->f_below, (int) $row->f_equal, $total),
            'm' => self::quintile((int) $row->m_below, (int) $row->m_equal, $total),
            'buyers' => $total,
        ];
    }

    /**
     * Agrégats par fiche client acheteuse dans les boutiques données.
     *
     * @param  array<int, int>  $storeIds
     */
    private function buyersQuery(array $storeIds): Builder
    {
        return DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('purchases.store_id', $storeIds)
            ->whereNotNull('contacts.customer_id')
            ->groupBy('contacts.customer_id')
            ->selectRaw('contacts.customer_id, DATE(MAX(purchases.purchased_at)) as last_at, COUNT(*) as cnt, SUM(purchases.amount) as total');
    }

    /** Rang centile (rang moyen pour les ex-aequo) ramené à un score 1-5. */
    public static function quintile(int $below, int $equal, int $total): int
    {
        if ($total <= 0) {
            return 3;
        }

        $percentile = ($below + $equal / 2) / $total;

        return min(5, max(1, (int) floor($percentile * 5) + 1));
    }

    public static function segmentKey(int $r, int $f, int $m, int $frequency): string
    {
        $fm = intdiv($f + $m + 1, 2);

        if ($r >= 3) {
            return match (true) {
                $frequency === 1 => 'new',
                $r >= 4 && $fm >= 4 => 'champions',
                $fm >= 3 => 'loyal',
                default => 'potential',
            };
        }

        // Un achat unique ancien n'en fait pas un « bon client qui s'éloigne ».
        return match (true) {
            $frequency > 1 && $r === 1 && $fm >= 4 => 'cant_lose',
            $frequency > 1 && $fm >= 3 => 'at_risk',
            $r === 2 => 'hibernating',
            default => 'lost',
        };
    }

    /** @return array{key: string, label: string, action: string} */
    private static function segment(string $key): array
    {
        return ['key' => $key, ...self::SEGMENTS[$key]];
    }
}
