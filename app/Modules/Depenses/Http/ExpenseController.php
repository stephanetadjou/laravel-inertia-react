<?php

namespace App\Modules\Depenses\Http;

use App\Http\Controllers\Controller;
use App\Models\BookableResource;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Modules\Depenses\Models\Expense;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Application « Dépenses » : saisir et suivre les dépenses du mois. */
class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = $this->storeIds($user);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01') : today()->startOfMonth();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $category = array_key_exists((string) $request->query('category'), Expense::CATEGORIES) ? (string) $request->query('category') : null;
        $q = trim((string) $request->query('q', ''));

        $base = Expense::where('organization_id', $user->organization_id)->whereIn('store_id', $storeIds);
        $monthQuery = (clone $base)->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()]);
        $previous = (int) (clone $base)->whereBetween('spent_on', [$start->copy()->subMonthNoOverflow()->toDateString(), $start->copy()->subDay()->toDateString()])->sum('amount');
        $total = (int) (clone $monthQuery)->sum('amount');

        // Chiffre d'affaires du mois (ventes valides) pour situer les dépenses.
        $revenue = (int) Purchase::whereIn('store_id', $storeIds)->where('status', Purchase::STATUS_VALID)
            ->whereBetween('purchased_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])->sum('amount');

        $byCategory = (clone $monthQuery)->selectRaw('category, sum(amount) as total, count(*) as n')->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($r) => ['key' => $r->category, 'label' => Expense::CATEGORIES[$r->category] ?? $r->category, 'total' => (int) $r->total, 'count' => (int) $r->n])->values();

        $list = (clone $monthQuery)
            ->when($category, fn ($w) => $w->where('category', $category))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('label', 'like', "%{$q}%")->orWhere('supplier', 'like', "%{$q}%")))
            ->with(['store:id,name', 'user:id,name'])
            ->orderByDesc('spent_on')->orderByDesc('id');

        return Inertia::render('Expenses/Index', [
            'filters' => ['month' => $start->format('Y-m'), 'category' => $category, 'q' => $q],
            'monthLabel' => ucfirst($start->locale('fr')->translatedFormat('F Y')),
            'stats' => ['total' => $total, 'previous' => $previous, 'revenue' => $revenue, 'count' => (clone $monthQuery)->count()],
            'byCategory' => $byCategory,
            'expenses' => $list->paginate(50)->withQueryString()->through(fn (Expense $e) => [
                'id' => $e->id,
                'spent_on' => $e->spent_on->toDateString(),
                'amount' => $e->amount,
                'category' => $e->category,
                'category_label' => Expense::CATEGORIES[$e->category] ?? $e->category,
                'label' => $e->label,
                'supplier' => $e->supplier,
                'method' => $e->method,
                'method_label' => Purchase::PAYMENT_METHODS[$e->method] ?? null,
                'store_id' => $e->store_id,
                'store_name' => $e->store?->name,
                'user_name' => $e->user?->name,
                'has_receipt' => $e->receipt_path !== null,
                'resource_id' => $e->resource_id,
                'vehicle' => $e->resource_id ? BookableResource::whereKey($e->resource_id)->value('name') : null,
            ]),
            'categories' => collect(Expense::CATEGORIES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'suppliers' => (clone $base)->whereNotNull('supplier')->distinct()->orderBy('supplier')->limit(200)->pluck('supplier'),
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
            // Loueurs : une dépense (garage, carburant, assurance) peut être rattachée à un véhicule.
            'vehicles' => $request->user()->organization->hasModule('vehicules') ? BookableResource::where('organization_id', $request->user()->organization_id)->where('kind', 'vehicule')->where('status', '!=', 'sold')->orderBy('name')->get(['id', 'name', 'reference'])->map(fn ($v) => ['id' => $v->id, 'label' => $v->label()]) : [],
            'paymentMethods' => Purchase::PAYMENT_METHODS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $this->validated($request, $user);
        $expense = Expense::create([
            ...collect($data)->except('receipt')->all(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'receipt_path' => $request->file('receipt')?->store("expenses/{$user->organization_id}"),
        ]);
        Audit::record('expense.created', $expense, "Dépense : {$expense->label}, ".number_format($expense->amount, 0, ',', ' ').' FCFA', [], $user);

        return back()->with('success', 'Dépense enregistrée.');
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeExpense($user, $expense);
        $data = $this->validated($request, $user);
        $expense->update([
            ...collect($data)->except('receipt')->all(),
            ...($request->hasFile('receipt') ? ['receipt_path' => $request->file('receipt')->store("expenses/{$user->organization_id}")] : []),
        ]);

        return back()->with('success', 'Dépense mise à jour.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeExpense($user, $expense);
        Audit::record('expense.deleted', $expense, "Dépense supprimée : {$expense->label}, ".number_format($expense->amount, 0, ',', ' ').' FCFA', [], $user);
        $expense->delete();

        return back()->with('success', 'Dépense supprimée.');
    }

    public function receipt(Request $request, Expense $expense): StreamedResponse
    {
        $this->authorizeExpense($request->user(), $expense);
        abort_if($expense->receipt_path === null || ! Storage::exists($expense->receipt_path), 404);

        return Storage::response($expense->receipt_path);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $user): array
    {
        return $request->validate([
            'store_id' => ['required', 'integer', Rule::in($this->storeIds($user))],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'label' => ['required', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'resource_id' => ['nullable', 'integer', Rule::exists('bookable_resources', 'id')->where('organization_id', $user->organization_id)],
        ]);
    }

    private function authorizeExpense(User $user, Expense $expense): void
    {
        abort_unless($expense->organization_id === $user->organization_id && in_array($expense->store_id, $this->storeIds($user), true), 404);
    }

    /** @return array<int, int> */
    private function storeIds(User $user): array
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('id', $visible))
            ->pluck('id')->all();
    }
}
