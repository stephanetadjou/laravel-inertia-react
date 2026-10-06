<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Filtre de période partagé (tableau de bord, historique d'achat) et
 * découpage en séries temporelles.
 *
 * Période en query string : `period` = today|7d|30d|90d (30 j par défaut),
 * ou `from`/`to` explicites. Chaque période se compare à la période
 * précédente de même durée.
 */
trait AnalyzesPeriods
{
    private const PERIOD_PRESETS = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * @return array{from: Carbon, to: Carbon, preset: string|null, days: int, previous_from: Carbon, previous_to: Carbon}
     */
    protected function resolvePeriod(Request $request): array
    {
        [$from, $to, $preset] = $this->periodBounds($request);
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

        return [
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'days' => $days,
            'previous_from' => $from->copy()->subDays($days),
            'previous_to' => $from->copy()->subSecond(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string|null}
     */
    private function periodBounds(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            try {
                $from = Carbon::parse((string) $request->string('from'))->startOfDay();
                $to = Carbon::parse((string) $request->string('to'))->endOfDay();

                if ($from->lessThanOrEqualTo($to)) {
                    return [$from, $to, null];
                }
            } catch (\Throwable) {
                // Date illisible : on retombe sur la période par défaut.
            }
        }

        $preset = array_key_exists((string) $request->query('period'), self::PERIOD_PRESETS)
            ? (string) $request->query('period')
            : '30d';

        return [
            now()->subDays(self::PERIOD_PRESETS[$preset] - 1)->startOfDay(),
            now()->endOfDay(),
            $preset,
        ];
    }

    /**
     * Découpe la période en tranches : par jour jusqu'à 31 jours, par
     * semaine (7 jours glissants depuis le début de période) au-delà.
     *
     * @return array<int, array{start: string, end: string}>
     */
    protected function buckets(Carbon $from, int $days): array
    {
        $step = $days > 31 ? 7 : 1;
        $buckets = [];

        for ($offset = 0; $offset < $days; $offset += $step) {
            $buckets[] = [
                'start' => $from->copy()->addDays($offset)->format('Y-m-d'),
                'end' => $from->copy()->addDays(min($offset + $step, $days) - 1)->format('Y-m-d'),
            ];
        }

        return $buckets;
    }

    /**
     * Répartit des totaux journaliers (clé Y-m-d) dans les tranches.
     *
     * @param  array<int, array{start: string, end: string}>  $buckets
     * @param  Collection<string, int|string>  $daily
     * @return array<int, int>
     */
    protected function sumIntoBuckets(array $buckets, Collection $daily): array
    {
        return array_map(
            fn (array $bucket) => (int) $daily
                ->filter(fn ($value, $day) => $day >= $bucket['start'] && $day <= $bucket['end'])
                ->sum(),
            $buckets,
        );
    }

    protected function dailyTotals(Builder $query, string $column, string $aggregate = 'count(*)'): Collection
    {
        return $query
            ->selectRaw("DATE({$column}) as day, {$aggregate} as total")
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total]);
    }

    /**
     * CA par tranche sur la période, aligné tranche à tranche sur la période
     * précédente. `$purchases` renvoie la requête d'achats bornée aux dates.
     *
     * @param  array{from: Carbon, to: Carbon, days: int, previous_from: Carbon, previous_to: Carbon}  $period
     * @return array<int, array{start: string, end: string, revenue: int, previous: int}>
     */
    protected function salesSeries(array $period, callable $purchases): array
    {
        $buckets = $this->buckets($period['from'], $period['days']);
        $previousBuckets = $this->buckets($period['previous_from'], $period['days']);

        $current = $this->sumIntoBuckets($buckets, $this->dailyTotals($purchases($period['from'], $period['to']), 'purchased_at', 'sum(amount)'));
        $previous = $this->sumIntoBuckets($previousBuckets, $this->dailyTotals($purchases($period['previous_from'], $period['previous_to']), 'purchased_at', 'sum(amount)'));

        return array_map(
            fn (array $bucket, int $i) => [...$bucket, 'revenue' => $current[$i], 'previous' => $previous[$i] ?? 0],
            $buckets,
            array_keys($buckets),
        );
    }
}
