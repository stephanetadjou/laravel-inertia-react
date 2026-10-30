<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres de chaque client dans chaque marque (customer_brand_metrics) :
 * premier et dernier achat, tickets, CA net, et RFM calculé parmi les
 * acheteurs de la marque (mêmes règles et même période que le RFM du
 * groupe). Met à jour customers.brands_count (clients multimarques) et
 * l'historique des segments par marque.
 *
 * Recalculé après chaque import (RefreshBrandMetricsJob) et chaque nuit.
 */
class BrandMetrics
{
    public function refreshOrganization(Organization $organization): int
    {
        $rules = $organization->rfmSettings();
        $today = now()->startOfDay();
        $now = now();

        $rows = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->join('stores', 'stores.id', '=', 'purchases.store_id')
            ->where('stores.organization_id', $organization->id)
            ->whereNotNull('contacts.customer_id')
            ->where('purchases.status', Purchase::STATUS_VALID)
            ->when($rules['period_months'] > 0, fn ($q) => $q->where('purchases.purchased_at', '>=', $today->copy()->subMonths($rules['period_months'])->toDateString()))
            ->groupBy('contacts.customer_id', 'stores.brand_id')
            ->selectRaw('contacts.customer_id as customer_id, stores.brand_id as brand_id, DATE('.Purchase::SQL_FIRST_SALE.') as first_at, DATE('.Purchase::SQL_LAST_SALE.') as last_at, '.Purchase::SQL_SALES_COUNT.' as cnt, COALESCE(SUM(purchases.amount), 0) as total')
            ->get();

        $previous = DB::table('customer_brand_metrics')->where('organization_id', $organization->id)
            ->get(['customer_id', 'brand_id', 'rfm_segment'])
            ->mapWithKeys(fn ($r) => ["{$r->customer_id}-{$r->brand_id}" => $r->rfm_segment]);

        $records = [];
        $history = [];

        foreach ($rows->groupBy('brand_id') as $brandId => $brandRows) {
            $buyers = $brandRows->filter(fn ($r) => (int) $r->cnt > 0);
            $total = $buyers->count();
            $rank = fn (string $field) => CustomerScoring::ranker($buyers->pluck($field)->map(fn ($v) => is_numeric($v) ? (int) $v : (string) $v)->all(), $total);
            [$r, $f, $m] = [$rank('last_at'), $rank('cnt'), $rank('total')];

            foreach ($brandRows as $row) {
                $sold = (int) $row->cnt > 0;
                $scores = $sold ? ['r' => $r($row->last_at), 'f' => $f((int) $row->cnt), 'm' => $m((int) $row->total)] : ['r' => null, 'f' => null, 'm' => null];
                $segment = $sold ? RfmCalculator::segmentKey($scores['r'], $scores['f'], $scores['m'], (int) $row->cnt, $rules) : 'none';

                $records[] = [
                    'organization_id' => $organization->id,
                    'customer_id' => (int) $row->customer_id,
                    'brand_id' => (int) $brandId,
                    'first_purchase_at' => $row->first_at,
                    'last_purchase_at' => $row->last_at,
                    'order_count' => (int) $row->cnt,
                    'net_spend' => (int) $row->total,
                    'rfm_r' => $scores['r'],
                    'rfm_f' => $scores['f'],
                    'rfm_m' => $scores['m'],
                    'rfm_segment' => $segment,
                    'computed_at' => $now,
                ];

                if ($sold && ($previous["{$row->customer_id}-{$brandId}"] ?? null) !== $segment) {
                    $history[] = ['organization_id' => $organization->id, 'customer_id' => (int) $row->customer_id, 'brand_id' => (int) $brandId, 'segment' => $segment,
                        'r' => $scores['r'], 'f' => $scores['f'], 'm' => $scores['m'], 'computed_on' => $today->toDateString(),
                        'period_months' => $rules['period_months'], 'rules_version' => $rules['version'], 'created_at' => $now];
                }
            }
        }

        DB::transaction(function () use ($organization, $records, $history) {
            DB::table('customer_brand_metrics')->where('organization_id', $organization->id)->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('customer_brand_metrics')->insert($chunk);
            }

            foreach (array_chunk($history, 500) as $chunk) {
                DB::table('customer_segment_history')->insert($chunk);
            }

            // Nombre de marques où le client a vraiment acheté.
            DB::table('customers')->where('organization_id', $organization->id)->update(['brands_count' => 0]);
            collect($records)->filter(fn ($r) => $r['order_count'] > 0)->countBy('customer_id')
                ->groupBy(fn ($count) => $count, true)
                ->each(function ($customers, $count) {
                    foreach (array_chunk($customers->keys()->all(), 500) as $ids) {
                        DB::table('customers')->whereIn('id', $ids)->update(['brands_count' => $count]);
                    }
                });
        });

        return count($records);
    }
}
