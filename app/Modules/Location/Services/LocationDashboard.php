<?php

namespace App\Modules\Location\Services;

use App\Models\BookableResource;
use App\Models\Organization;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\VehicleRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tableau de bord du loueur (lecture seule) : occupation et CA par véhicule,
 * demandes et conversion, raisons de perte, cautions en cours, soldes à
 * encaisser, véhicules immobilisés et échéances (assurance, visite, vidange).
 */
class LocationDashboard
{
    /**
     * @param  list<int>  $storeIds
     * @return array<string, mixed>
     */
    public static function build(Organization $organization, array $storeIds, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->addMonth();
        $daysInMonth = $start->daysInMonth;
        $since = $start->copy()->subMonths(5);

        $vehicles = BookableResource::where('organization_id', $organization->id)->where('kind', 'vehicule')->where('active', true)->where('status', '!=', 'sold')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))->orderBy('name')->get();

        $bookings = Booking::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $since)->get(['id', 'resource_id', 'customer_id', 'status', 'starts_at', 'ends_at', 'returned_at', 'amount', 'deposit', 'deposit_status', 'quote_id']);

        $months = [];
        for ($m = $since->copy(); $m->lt($end); $m->addMonth()) {
            $months[] = ['key' => $m->format('Y-m'), 'label' => $m->locale('fr')->translatedFormat('M y')];
        }

        $costs = Schema::hasColumn('expenses', 'resource_id')
            ? DB::table('expenses')->where('organization_id', $organization->id)->whereNotNull('resource_id')
                ->where('spent_on', '>=', $start->toDateString())->where('spent_on', '<', $end->toDateString())->groupBy('resource_id')->selectRaw('resource_id, SUM(amount) as total')->pluck('total', 'resource_id')
            : collect();

        $rows = $vehicles->map(function (BookableResource $v) use ($bookings, $start, $end, $daysInMonth, $months, $costs) {
            $own = $bookings->where('resource_id', $v->id);
            $hours = $own->sum(function (Booking $b) use ($start, $end) {
                $from = $b->starts_at->max($start);
                $to = ($b->returned_at ?? $b->ends_at)->min($end);

                return $to->gt($from) ? $from->diffInMinutes($to) / 60 : 0;
            });
            $byMonth = [];
            foreach ($months as $m) {
                $byMonth[$m['key']] = (int) $own->filter(fn (Booking $b) => $b->starts_at->format('Y-m') === $m['key'])->sum('amount');
            }

            return [
                'id' => $v->id,
                'name' => $v->label(),
                'status' => $v->status,
                'status_label' => BookableResource::STATUSES[$v->status] ?? $v->status,
                'occupancy' => (int) min(100, round($hours * 100 / ($daysInMonth * 24))),
                'days' => (int) round($hours / 24),
                'revenue' => $byMonth[$start->format('Y-m')] ?? 0,
                'costs' => (int) ($costs[$v->id] ?? 0),
                'profit' => ($byMonth[$start->format('Y-m')] ?? 0) - (int) ($costs[$v->id] ?? 0),
                'by_month' => $byMonth,
                'deadlines' => $v->deadlines(),
            ];
        })->values();

        $requests = VehicleRequest::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->get(['id', 'status', 'lost_reason', 'source']);
        $won = $requests->where('status', 'won')->count();
        $lost = $requests->where('status', 'lost')->count();

        $active = Booking::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->whereIn('status', ['reserved', 'ongoing', 'returned'])
            ->whereNotNull('quote_id')->where('starts_at', '>=', today()->subMonths(6))->with('quote')->get();
        $held = Booking::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('deposit_status', 'held')->get(['id', 'deposit']);

        return [
            'month' => $start->format('Y-m'),
            'month_label' => ucfirst($start->locale('fr')->translatedFormat('F Y')),
            'months' => $months,
            'vehicles' => $rows,
            'occupancy' => $rows->count() ? (int) round($rows->avg('occupancy')) : 0,
            'revenue' => (int) $rows->sum('revenue'),
            'requests' => [
                'total' => $requests->count(),
                'won' => $won,
                'lost' => $lost,
                'open' => $requests->whereIn('status', ['new', 'quoted'])->count(),
                'conversion' => $requests->count() ? (int) round($won * 100 / $requests->count()) : null,
                'lost_reasons' => $requests->where('status', 'lost')->groupBy('lost_reason')
                    ->map(fn ($g, $k) => ['key' => $k, 'label' => VehicleRequest::LOST_REASONS[$k] ?? $k, 'count' => $g->count()])->sortByDesc('count')->values(),
                'sources' => $requests->groupBy('source')->map(fn ($g, $k) => ['key' => $k, 'label' => VehicleRequest::SOURCES[$k] ?? $k, 'count' => $g->count()])->sortByDesc('count')->values(),
            ],
            'rides' => (function () use ($organization, $storeIds, $start, $end) {
                $rides = Booking::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('kind', 'ride')
                    ->where('ride_status', '!=', 'cancelled')->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->get(['amount', 'ride_status']);

                return ['count' => $rides->count(), 'done' => $rides->where('ride_status', 'done')->count(), 'revenue' => (int) $rides->where('ride_status', 'done')->sum('amount')];
            })(),
            'ride_requests' => VehicleRequest::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('need', 'ride')->where('status', 'new')->count(),
            'deposits' => ['count' => $held->count(), 'amount' => (int) $held->sum('deposit')],
            'balances' => (int) $active->sum(fn (Booking $b) => $b->balanceDue()),
            'immobilized' => $vehicles->where('status', 'maintenance')->map(fn ($v) => $v->label())->values(),
            'alerts' => $rows->filter(fn ($r) => $r['deadlines'] !== [])->map(fn ($r) => ['vehicle' => $r['name'], 'deadlines' => $r['deadlines']])->values(),
        ];
    }
}
