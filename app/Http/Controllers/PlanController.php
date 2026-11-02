<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ventes › Formules (module « abonnements ») : formules (prix, période,
 * frais uniques, options) et abonnements en cours ; un abonnement prépare
 * son échéancier (Ventes › Échéances).
 */
class PlanController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = self::storeIds($user);
        $filter = in_array($request->query('show'), ['ending', 'all'], true) ? $request->query('show') : 'current';
        $endingDays = 30;

        $subscriptions = Subscription::whereIn('store_id', $storeIds)
            ->with(['plan:id,name', 'customer:id,name', 'beneficiary:id,name', 'schedule.installments'])
            ->when($filter === 'current', fn ($q) => $q->current())
            ->when($filter === 'ending', fn ($q) => $q->current()->whereNotNull('ends_on')->where('ends_on', '<', today()->addDays($endingDays + 1)->toDateString()))
            ->orderBy($filter === 'ending' ? 'ends_on' : 'id', $filter === 'ending' ? 'asc' : 'desc')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Subscription $s) => self::presentSubscription($s));

        return Inertia::render('Plans/Index', [
            'plans' => Plan::where('organization_id', $user->organization_id)->withCount(['subscriptions as active_count' => fn ($q) => $q->current()])
                ->orderByDesc('active')->orderBy('name')->get()
                ->map(fn (Plan $p) => self::presentPlan($p) + ['active_count' => $p->active_count]),
            'subscriptions' => $subscriptions,
            'filter' => $filter,
            'counts' => [
                'current' => Subscription::whereIn('store_id', $storeIds)->current()->count(),
                'ending' => Subscription::whereIn('store_id', $storeIds)->current()->whereNotNull('ends_on')->where('ends_on', '<', today()->addDays($endingDays + 1)->toDateString())->count(),
            ],
            ...self::formOptions($user),
            'canManage' => $user->canManageOrganization(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageOrganization(), 403);
        $plan = Plan::create([...$this->validatePlan($request), 'organization_id' => $request->user()->organization_id]);

        return back()->with('success', "Formule « {$plan->name} » créée.");
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless($request->user()->canManageOrganization() && $plan->organization_id === $request->user()->organization_id, 403);
        $plan->update($this->validatePlan($request));

        return back()->with('success', "Formule « {$plan->name} » enregistrée.");
    }

    public function subscribe(Request $request, Subscriptions $subscriptions): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('organization_id', $user->organization_id)],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('organization_id', $user->organization_id)],
            'store_id' => ['required', 'integer', Rule::in(self::storeIds($user))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'options' => ['nullable', 'array'],
            'options.*' => ['integer', 'min:0'],
            'season_installments' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $subscription = $subscriptions->create($user, $data);
        $member = $subscription->member();

        return back()->with('success', "Abonnement « {$subscription->plan->name} » enregistré pour {$member->name} : son échéancier est prêt dans Ventes › Échéances.");
    }

    public function cancel(Request $request, Subscription $subscription, Subscriptions $subscriptions): RedirectResponse
    {
        abort_unless($subscription->organization_id === $request->user()->organization_id && in_array($subscription->store_id, self::storeIds($request->user()), true), 404);
        $subscriptions->cancel($subscription, $request->user());

        return back()->with('success', 'Abonnement arrêté : les tranches restantes ne seront plus réclamées.');
    }

    /** @return array<string, mixed> */
    private function validatePlan(Request $request): array
    {
        $organizationId = $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('organization_id', $organizationId)],
            'price' => ['required', 'integer', 'min:0'],
            'period' => ['required', Rule::in(array_keys(Plan::PERIODS))],
            'sessions_count' => ['nullable', 'required_if:period,sessions', 'integer', 'min:1', 'max:500'],
            'fees' => ['nullable', 'array', 'max:10'],
            'fees.*.label' => ['required', 'string', 'max:100'],
            'fees.*.amount' => ['required', 'integer', 'min:0'],
            'options' => ['nullable', 'array', 'max:10'],
            'options.*.label' => ['required', 'string', 'max:100'],
            'options.*.amount' => ['required', 'integer', 'min:0'],
            'options.*.recurring' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['nullable', 'boolean'],
        ]);

        return [
            ...$data,
            'sessions_count' => $data['period'] === 'sessions' ? $data['sessions_count'] : null,
            'fees' => array_values(array_map(fn ($f) => ['label' => $f['label'], 'amount' => (int) $f['amount']], $data['fees'] ?? [])),
            'options' => array_values(array_map(fn ($o) => ['label' => $o['label'], 'amount' => (int) $o['amount'], 'recurring' => (bool) ($o['recurring'] ?? false)], $data['options'] ?? [])),
            'active' => $data['active'] ?? true,
        ];
    }

    /**
     * Données du formulaire d'abonnement (Formules et fiche client).
     *
     * @return array<string, mixed>
     */
    public static function formOptions(User $user): array
    {
        $settings = $user->organization->moduleSettings('abonnements');

        return [
            'planOptions' => Plan::where('organization_id', $user->organization_id)->where('active', true)->orderBy('name')->get()->map(fn (Plan $p) => self::presentPlan($p)),
            'stores' => Store::whereIn('id', self::storeIds($user))->orderBy('name')->get(['id', 'name']),
            'catalog' => CatalogItem::where('organization_id', $user->organization_id)->where('active', true)->orderBy('name')->get(['id', 'name']),
            'periods' => Plan::PERIODS,
            'season' => ['start' => $settings['season_start'], 'end' => $settings['season_end']],
        ];
    }

    /** @return array<string, mixed> */
    public static function presentPlan(Plan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'catalog_item_id' => $p->catalog_item_id,
            'price' => (int) $p->price,
            'period' => $p->period,
            'sessions_count' => $p->sessions_count,
            'fees' => $p->fees ?? [],
            'options' => $p->options ?? [],
            'description' => $p->description,
            'active' => $p->active,
            'price_label' => $p->priceLabel(),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentSubscription(Subscription $s): array
    {
        $status = $s->currentStatus();
        $installments = $s->schedule?->installments ?? collect();

        return [
            'id' => $s->id,
            'plan' => $s->plan?->name,
            'customer' => ['id' => $s->customer_id, 'name' => $s->customer?->name],
            'beneficiary' => $s->beneficiary ? ['id' => $s->beneficiary->id, 'name' => $s->beneficiary->name] : null,
            'starts_on' => $s->starts_on->toDateString(),
            'ends_on' => $s->ends_on?->toDateString(),
            'status' => $status,
            'status_label' => Subscription::STATUSES[$status],
            'sessions_left' => $s->sessions_left,
            'options' => collect($s->options ?? [])->pluck('label')->all(),
            'paid' => (int) $installments->whereNotNull('paid_at')->sum('amount'),
            'total' => (int) $installments->sum('amount'),
            'overdue' => $installments->filter(fn ($i) => $i->paid_at === null && $i->due_on->lt(today()))->count(),
        ];
    }

    /** @return array<int, int> */
    public static function storeIds(User $user): array
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->pluck('id')->all();
    }

    /** Abonnements d'une fiche (payeur ou personne suivie), pour l'onglet de la fiche client. */
    public static function forCustomer(Customer $customer, User $user): array
    {
        return Subscription::whereIn('store_id', self::storeIds($user))
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhere('beneficiary_id', $customer->id))
            ->with(['plan:id,name', 'customer:id,name', 'beneficiary:id,name', 'schedule.installments'])
            ->latest('starts_on')
            ->get()
            ->map(fn (Subscription $s) => self::presentSubscription($s))
            ->all();
    }
}
