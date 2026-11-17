<?php

namespace App\Modules\Caisse\Http;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Modules\Caisse\Models\CashClosing;
use App\Modules\Depenses\Models\Expense;
use App\Services\ActionLists;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Application « Caisse » : la journée d'un établissement. Encaissements par
 * moyen de paiement (toutes les ventes : rendez-vous, tranches, factures,
 * ventes saisies), clients servis à remercier, dépenses en espèces, fond de
 * caisse et clôture du soir avec écart.
 */
class CashController extends Controller
{
    public const SOURCES = [
        'appointment' => 'Rendez-vous',
        'installment' => 'Tranche',
        'invoice' => 'Facture',
        'subscription' => 'Abonnement',
        'rental' => 'Location',
        'manual' => 'Vente',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $stores = $this->stores($user);
        $store = $stores->firstWhere('id', $request->integer('store')) ?? $stores->first();
        abort_if($store === null, 404);
        $day = $this->day($request->query('day'));

        $sales = $this->sales($store->id, $day);
        $expenses = $this->expenses($user, $store->id, $day);
        $closing = CashClosing::where('store_id', $store->id)->whereDate('day', $day)->with('closer:id,name')->first();
        $previous = CashClosing::where('store_id', $store->id)->whereDate('day', '<', $day)->orderByDesc('day')->first();

        $byMethod = $sales->groupBy(fn ($p) => $p->payment_method ?? 'unknown')
            ->map(fn ($group, $method) => ['method' => $method, 'label' => Purchase::PAYMENT_METHODS[$method] ?? 'Non précisé', 'total' => (int) $group->sum('amount'), 'count' => $group->count()])
            ->sortByDesc('total')->values();
        $cashIn = (int) $sales->where('payment_method', 'cash')->sum('amount');
        $cashOut = (int) $expenses->where('method', 'cash')->sum('amount');
        $opening = $closing?->opening_float ?? (int) ($previous?->cash_counted ?? 0);

        // Clients servis aujourd'hui : un merci (ou un avis) par WhatsApp, le client reste au centre.
        $customers = $sales->filter(fn ($p) => $p->contact?->customer)->groupBy(fn ($p) => $p->contact->customer->id)
            ->map(function (Collection $group) use ($store) {
                /** @var Customer $customer */
                $customer = $group->first()->contact->customer;
                $phone = $customer->recipient()->phone_e164;
                $first = ActionLists::firstNameOf((string) $customer->name);

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'stage' => $customer->lifecycle_stage,
                    'total' => (int) $group->sum('amount'),
                    'visits' => (int) $customer->purchases_count,
                    'is_new' => (int) $customer->purchases_count <= 1,
                    'thanks_url' => $phone ? ActionLists::whatsappUrl($phone, "Bonjour {$first}, merci pour votre visite aujourd’hui chez {$store->name} ! Tout s’est bien passé ? Votre avis compte beaucoup pour nous.") : null,
                ];
            })->sortByDesc('total')->values();

        $week = collect(range(6, 0))->map(function (int $back) use ($store, $day) {
            $d = $day->copy()->subDays($back);

            return [
                'day' => $d->toDateString(),
                'label' => ucfirst($d->locale('fr')->translatedFormat('D j')),
                'total' => (int) Purchase::where('store_id', $store->id)->where('status', Purchase::STATUS_VALID)
                    ->whereBetween('purchased_at', [$d->copy()->startOfDay(), $d->copy()->endOfDay()])->sum('amount'),
            ];
        });

        return Inertia::render('Cash/Index', [
            'day' => $day->toDateString(),
            'dayLabel' => ucfirst($day->locale('fr')->translatedFormat('l j F Y')),
            'isToday' => $day->isToday(),
            'store' => ['id' => $store->id, 'name' => $store->name],
            'stores' => $stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'totals' => [
                'in' => (int) $sales->sum('amount'),
                'out' => (int) $expenses->sum('amount'),
                'count' => $sales->count(),
                'customers' => $customers->count(),
                'new_customers' => $customers->where('is_new', true)->count(),
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'opening' => $opening,
                'cash_expected' => $opening + $cashIn - $cashOut,
            ],
            'byMethod' => $byMethod,
            'sales' => $sales->sortByDesc('purchased_at')->values()->map(fn (Purchase $p) => [
                'id' => $p->id,
                'time' => $p->purchased_at->format('H:i'),
                'amount' => (int) $p->amount,
                'label' => $p->items->first()?->name ?? $p->product_category ?? $p->article ?? 'Vente',
                'source' => self::SOURCES[$p->source] ?? 'Vente',
                'method' => Purchase::PAYMENT_METHODS[$p->payment_method] ?? 'Non précisé',
                'customer' => $p->contact?->customer ? ['id' => $p->contact->customer->id, 'name' => $p->contact->customer->name] : null,
            ]),
            'expenses' => $expenses->map(fn (Expense $e) => [
                'id' => $e->id,
                'amount' => $e->amount,
                'label' => $e->label,
                'category' => Expense::CATEGORIES[$e->category] ?? $e->category,
                'method' => Purchase::PAYMENT_METHODS[$e->method] ?? 'Non précisé',
            ])->values(),
            'customers' => $customers,
            'closing' => $closing ? [
                'opening_float' => (int) $closing->opening_float,
                'cash_expected' => (int) $closing->cash_expected,
                'cash_counted' => (int) $closing->cash_counted,
                'difference' => (int) $closing->difference,
                'note' => $closing->note,
                'closed_at' => $closing->closed_at->toIso8601String(),
                'closed_by' => $closing->closer?->name,
            ] : null,
            'week' => $week,
            'hasExpenses' => $user->organization->hasModule('depenses'),
            'canReopen' => $user->canManageOrganization(),
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $user = $request->user();
        $stores = $this->stores($user);
        $data = $request->validate([
            'store_id' => ['required', 'integer', Rule::in($stores->pluck('id'))],
            'day' => ['required', 'date', 'before_or_equal:today'],
            'opening_float' => ['required', 'integer', 'min:0'],
            'cash_counted' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $day = Carbon::parse($data['day'])->startOfDay();

        if (CashClosing::where('store_id', $data['store_id'])->whereDate('day', $day)->exists()) {
            throw ValidationException::withMessages(['cash_counted' => 'Cette journée est déjà clôturée.']);
        }

        $sales = $this->sales($data['store_id'], $day);
        $expenses = $this->expenses($user, $data['store_id'], $day);
        $expected = (int) $data['opening_float'] + (int) $sales->where('payment_method', 'cash')->sum('amount') - (int) $expenses->where('method', 'cash')->sum('amount');

        $closing = CashClosing::create([
            'organization_id' => $user->organization_id,
            'store_id' => $data['store_id'],
            'day' => $day->toDateString(),
            'opening_float' => $data['opening_float'],
            'cash_expected' => $expected,
            'cash_counted' => $data['cash_counted'],
            'difference' => (int) $data['cash_counted'] - $expected,
            'total_in' => (int) $sales->sum('amount'),
            'total_out' => (int) $expenses->sum('amount'),
            'note' => $data['note'] ?? null,
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);
        Audit::record('cash.closed', $closing, 'Caisse clôturée le '.$day->format('d/m/Y').', écart '.number_format($closing->difference, 0, ',', ' ').' FCFA', [], $user);

        return back()->with('success', $closing->difference === 0 ? 'Caisse clôturée : aucun écart. Bonne soirée !' : 'Caisse clôturée avec un écart de '.number_format($closing->difference, 0, ',', ' ').' FCFA.');
    }

    public function reopen(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canManageOrganization(), 403);
        $data = $request->validate(['store_id' => ['required', 'integer', Rule::in($this->stores($user)->pluck('id'))], 'day' => ['required', 'date']]);
        $closing = CashClosing::where('store_id', $data['store_id'])->whereDate('day', $data['day'])->firstOrFail();
        Audit::record('cash.reopened', $closing, 'Caisse rouverte pour le '.Carbon::parse($data['day'])->format('d/m/Y'), [], $user);
        $closing->delete();

        return back()->with('success', 'Journée rouverte : vous pouvez la clôturer à nouveau.');
    }

    /** @return Collection<int, Purchase> */
    private function sales(int $storeId, Carbon $day): Collection
    {
        return Purchase::where('store_id', $storeId)->where('status', Purchase::STATUS_VALID)
            ->whereBetween('purchased_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->with(['contact.customer', 'items:id,purchase_id,name'])->get();
    }

    /** @return Collection<int, Expense> */
    private function expenses(User $user, int $storeId, Carbon $day): Collection
    {
        if (! $user->organization->hasModule('depenses')) {
            return collect();
        }

        return Expense::where('store_id', $storeId)->whereDate('spent_on', $day)->orderBy('id')->get();
    }

    private function day(mixed $value): Carbon
    {
        $day = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value)->startOfDay() : today();

        return $day->gt(today()) ? today() : $day;
    }

    /** @return Collection<int, Store> */
    private function stores(User $user): Collection
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('id', $visible))
            ->orderBy('name')->get(['id', 'name']);
    }
}
