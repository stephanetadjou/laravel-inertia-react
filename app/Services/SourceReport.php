<?php

namespace App\Services;

use App\Models\AdBudget;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

/**
 * Tableau par source d'acquisition (Pub Facebook, Pub Instagram, Direct,
 * Inconnue = fiches importées ou saisies) et par mois : prospects arrivés,
 * rendez-vous pris, nouveaux clients, ventes et CA, budget pub saisi et coût
 * par client (budget / nouveaux clients du mois). SQL portable (substr).
 */
class SourceReport
{
    public const UNKNOWN = 'unknown';

    public function __construct(private Organization $organization) {}

    /** @return list<string> Mois YYYY-MM, du plus ancien au plus récent. */
    public static function months(int $count = 6): array
    {
        return collect(range($count - 1, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->all();
    }

    /** @return array<string, string> */
    public static function sources(): array
    {
        return [...Customer::SOURCES, self::UNKNOWN => 'Inconnue (import, saisie)'];
    }

    /**
     * @param  list<string>  $months
     * @return list<array<string, mixed>>
     */
    public function rows(array $months): array
    {
        $from = $months[0].'-01';
        $orgId = $this->organization->id;
        $source = "COALESCE(customers.acquisition_source, '".self::UNKNOWN."')";

        $prospects = DB::table('customers')->where('organization_id', $orgId)
            ->whereRaw('COALESCE(acquired_at, created_at) >= ?', [$from])
            ->selectRaw("{$source} as source, substr(COALESCE(acquired_at, created_at), 1, 7) as month, COUNT(*) as n")
            ->groupBy('source', 'month')->get();

        $newCustomers = DB::table('customers')->where('organization_id', $orgId)
            ->where('first_purchase_at', '>=', $from)
            ->selectRaw("{$source} as source, substr(first_purchase_at, 1, 7) as month, COUNT(*) as n")
            ->groupBy('source', 'month')->get();

        $appointments = DB::table('appointments')->join('customers', 'customers.id', '=', 'appointments.customer_id')
            ->where('customers.organization_id', $orgId)
            ->where('appointments.created_at', '>=', $from)
            ->where('appointments.status', '!=', 'cancelled')
            ->selectRaw("{$source} as source, substr(appointments.created_at, 1, 7) as month, COUNT(*) as n")
            ->groupBy('source', 'month')->get();

        $sales = DB::table('purchases')->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->join('customers', 'customers.id', '=', 'contacts.customer_id')
            ->where('customers.organization_id', $orgId)
            ->where('purchases.status', 'valid')
            ->where('purchases.purchased_at', '>=', $from)
            ->selectRaw("{$source} as source, substr(purchases.purchased_at, 1, 7) as month, ".Purchase::SQL_SALES_COUNT.' as n, SUM(purchases.amount) as revenue')
            ->groupBy('source', 'month')->get();

        $budgets = AdBudget::where('organization_id', $orgId)->whereIn('month', $months)->get();

        $pick = fn ($rows, $src, $month, $field = 'n') => (int) ($rows->first(fn ($r) => $r->source === $src && $r->month === $month)?->{$field} ?? 0);

        $rows = [];
        foreach (array_keys(self::sources()) as $src) {
            foreach ($months as $month) {
                $budget = (int) ($budgets->first(fn ($b) => $b->source === $src && $b->month === $month)?->amount ?? 0);
                $customers = $pick($newCustomers, $src, $month);
                $rows[] = [
                    'source' => $src,
                    'month' => $month,
                    'prospects' => $pick($prospects, $src, $month),
                    'appointments' => $pick($appointments, $src, $month),
                    'customers' => $customers,
                    'sales' => $pick($sales, $src, $month),
                    'revenue' => $pick($sales, $src, $month, 'revenue'),
                    'budget' => $budget,
                    'cost_per_customer' => $budget > 0 && $customers > 0 ? intdiv($budget, $customers) : null,
                ];
            }
        }

        return $rows;
    }
}
