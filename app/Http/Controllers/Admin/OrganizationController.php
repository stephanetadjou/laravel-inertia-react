<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CreditMovement;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformLog;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Back-office plateforme › Entreprises : liste, fiche, essai, offre,
 * activation, suspension. Chaque action est tracée dans platform_logs.
 */
class OrganizationController extends Controller
{
    /** Filtre « statut » : les trois statuts enregistrés, plus l'essai terminé (calculé). */
    public const STATUS_FILTERS = [...Organization::STATUSES, 'trial_expired' => 'Essai terminé'];

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q')) ?: null,
            'status' => array_key_exists((string) $request->query('status'), self::STATUS_FILTERS) ? (string) $request->query('status') : null,
            'sector' => array_key_exists((string) $request->query('sector'), Organization::SECTORS) ? (string) $request->query('sector') : null,
            'plan' => array_key_exists((string) $request->query('plan'), Organization::PLANS) ? (string) $request->query('plan') : null,
            'offer' => in_array($request->query('offer'), ['menentia', 'retail'], true) ? (string) $request->query('offer') : null,
        ];

        $organizations = self::listQuery()
            ->when($filters['q'], fn (Builder $q, string $term) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%")))
            ->when($filters['status'] === 'trial_expired', fn (Builder $q) => $q->where('status', Organization::STATUS_TRIAL)->where('trial_ends_at', '<', now()))
            ->when($filters['status'] && $filters['status'] !== 'trial_expired', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['sector'], fn (Builder $q, string $sector) => $q->where('sector', $sector))
            ->when($filters['plan'], fn (Builder $q, string $plan) => $q->where('plan', $plan))
            ->when($filters['offer'] === 'retail', fn (Builder $q) => $q->where('sector', 'commerce'))
            ->when($filters['offer'] === 'menentia', fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('sector')->orWhere('sector', '<>', 'commerce')))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Organizations/Index', [
            'filters' => $filters,
            'options' => self::options(),
            'organizations' => $organizations->through(fn (Organization $o) => self::present($o)),
        ]);
    }

    public function show(Organization $organization): Response
    {
        $organization = self::listQuery()->findOrFail($organization->id);
        $storeIds = $organization->stores()->pluck('id');

        return Inertia::render('Admin/Organizations/Show', [
            'organization' => [
                ...self::present($organization),
                'slug' => $organization->slug,
                'suspended_at' => $organization->suspended_at?->toIso8601String(),
                'suspension_reason' => $organization->suspension_reason,
                'whatsapp_api_enabled' => (bool) $organization->whatsapp_api_enabled,
                'brands_count' => $organization->brands()->count(),
                'stores_count' => $storeIds->count(),
                'purchases_count' => Purchase::whereIn('store_id', $storeIds)->count(),
                'appointments_count' => Appointment::whereIn('store_id', $storeIds)->count(),
            ],
            'users' => $organization->users()->with('role:id,name')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'created_at' => $u->created_at?->toIso8601String(),
            ]),
            'stores' => Store::where('organization_id', $organization->id)->orderBy('name')->get()->map(fn (Store $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'whatsapp_managed' => $s->whatsapp_managed,
                'business_account_id' => $s->whatsapp_business_account_id,
                'phone_number_id' => $s->whatsapp_phone_number_id,
                'verified_name' => $s->whatsapp_verified_name,
                'display_phone_number' => $s->whatsapp_display_phone_number,
                'checked_at' => $s->whatsapp_checked_at?->toIso8601String(),
            ]),
            'platform_whatsapp_ready' => filled(config('services.meta_whatsapp.platform.token')),
            'credits' => [
                'balance' => $organization->message_credits,
                'cost' => $organization->marketingMessageCost(),
                'custom_cost' => $organization->marketing_message_cost,
                'movements' => CreditMovement::where('organization_id', $organization->id)->with('user:id,name')->latest('id')->take(20)->get()
                    ->map(fn (CreditMovement $m) => ['id' => $m->id, 'amount' => $m->amount, 'balance_after' => $m->balance_after, 'reason' => $m->reason, 'user' => $m->user?->name, 'at' => $m->created_at?->toIso8601String()]),
            ],
            'logs' => PlatformLog::where('organization_id', $organization->id)->with('user:id,name')->latest('id')->take(30)->get()
                ->map(fn (PlatformLog $log) => self::presentLog($log)),
            'options' => self::options(),
        ]);
    }

    /** Crédits de messages : recharge (ou correction) manuelle et coût d'un message marketing. */
    public function credits(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'between:-100000000,100000000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if (array_key_exists('cost', $data) && (int) $data['cost'] !== $organization->marketingMessageCost()) {
            $organization->update(['marketing_message_cost' => $data['cost']]);
            PlatformLog::record('organization.message_cost', "Coût d’un message marketing : {$data['cost']} FCFA", ['cost' => $data['cost']], $organization);
        }

        if (! empty($data['amount'])) {
            $organization->moveCredits((int) $data['amount'], $data['reason'] ?: 'Recharge manuelle', $request->user()->id);
            PlatformLog::record('organization.credits', ($data['amount'] > 0 ? 'Recharge' : 'Retrait').' de '.abs($data['amount']).' FCFA de crédits', ['amount' => $data['amount']], $organization);
        }

        return back()->with('success', 'Crédits mis à jour.');
    }

    public function extendTrial(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);

        if ($organization->status === Organization::STATUS_ACTIVE) {
            return back()->with('error', 'Cette entreprise est déjà abonnée : pas d’essai à prolonger.');
        }

        $from = $organization->trial_ends_at?->isFuture() ? $organization->trial_ends_at : now();
        $previous = $organization->trial_ends_at?->toDateString();
        $organization->update(['trial_ends_at' => $from->copy()->addDays($data['days'])]);

        PlatformLog::record('organization.trial_extended', "Essai prolongé de {$data['days']} jours", [
            'days' => $data['days'], 'from' => $previous, 'to' => $organization->trial_ends_at->toDateString(),
        ], $organization);

        return back()->with('success', "Essai prolongé jusqu’au {$organization->trial_ends_at->format('d/m/Y')}.");
    }

    public function changePlan(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(Organization::PLANS))]]);

        if ($data['plan'] === $organization->plan) {
            return back();
        }

        $previous = $organization->plan;
        $organization->update(['plan' => $data['plan']]);
        PlatformLog::record('organization.plan_changed', 'Offre '.Organization::PLANS[$data['plan']]['label'], ['from' => $previous, 'to' => $data['plan']], $organization);

        return back()->with('success', 'Offre '.Organization::PLANS[$data['plan']]['label'].' enregistrée.');
    }

    public function activate(Organization $organization): RedirectResponse
    {
        if ($organization->status !== Organization::STATUS_TRIAL) {
            return back()->with('error', 'Seule une entreprise en essai peut passer à l’abonnement.');
        }

        $organization->update(['status' => Organization::STATUS_ACTIVE]);
        PlatformLog::record('organization.activated', 'Abonnement '.Organization::PLANS[$organization->plan]['label'], ['plan' => $organization->plan], $organization);

        return back()->with('success', 'Abonnement activé.');
    }

    public function suspend(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Indiquez le motif de la suspension.']);

        if ($organization->isSuspended()) {
            return back();
        }

        $organization->update(['status' => Organization::STATUS_SUSPENDED, 'suspended_at' => now(), 'suspension_reason' => $data['reason']]);
        PlatformLog::record('organization.suspended', $data['reason'], [], $organization);

        return back()->with('success', 'Entreprise suspendue : ses utilisateurs voient la page « compte suspendu ».');
    }

    /**
     * « WhatsApp géré par NENTIX » : le client a partagé son compte
     * WhatsApp Business avec le portefeuille NENTIX ; NENTIX envoie avec
     * son token et reçoit les événements sur le webhook unique. Réglé ici
     * seulement, jamais par l'entreprise : un Phone Number ID saisi par un
     * client ne doit pas lui donner le token de NENTIX.
     */
    public function manageWhatsApp(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        abort_unless($store->organization_id === $organization->id, 404);

        $data = $request->validate([
            'managed' => ['required', 'boolean'],
            'business_account_id' => ['nullable', 'required_if:managed,true', 'regex:/^\d{5,25}$/'],
            'phone_number_id' => [
                'nullable', 'required_if:managed,true', 'regex:/^\d{5,25}$/',
                Rule::unique('stores', 'whatsapp_phone_number_id')->ignore($store->id),
            ],
        ], [
            '*.regex' => 'Identifiant Meta attendu : chiffres uniquement.',
            '*.required_if' => 'Obligatoire pour un numéro géré par NENTIX.',
            'phone_number_id.unique' => 'Ce numéro est déjà rattaché à un autre point de vente.',
        ]);

        if (! $data['managed']) {
            $store->update(['whatsapp_managed' => false]);
            PlatformLog::record('store.whatsapp_managed', "{$store->name} : géré par l'entreprise", ['store_id' => $store->id, 'managed' => false], $organization);

            return back()->with('success', "{$store->name} : WhatsApp n'est plus géré par NENTIX.");
        }

        $phoneChanged = $data['phone_number_id'] !== $store->whatsapp_phone_number_id;

        $store->fill([
            'whatsapp_managed' => true,
            'whatsapp_business_account_id' => $data['business_account_id'],
            'whatsapp_phone_number_id' => $data['phone_number_id'],
            // Le token et l'App Secret de NENTIX remplacent ceux de l'entreprise.
            'whatsapp_access_token' => null,
            'whatsapp_app_secret' => null,
        ]);

        if ($phoneChanged) {
            $store->fill([
                'whatsapp_verified_name' => null,
                'whatsapp_display_phone_number' => null,
                'whatsapp_quality_rating' => null,
                'whatsapp_messaging_limit_tier' => null,
                'whatsapp_checked_at' => null,
                'whatsapp_registered_at' => null,
            ]);
        }

        $store->save();
        $organization->update(['whatsapp_api_enabled' => true]);
        PlatformLog::record('store.whatsapp_managed', "{$store->name} : numéro {$data['phone_number_id']}", ['store_id' => $store->id, 'managed' => true], $organization);

        return back()->with('success', "{$store->name} : WhatsApp géré par NENTIX. Lancez « Tester » dans les Paramètres WhatsApp de l'entreprise.");
    }

    public function reactivate(Organization $organization): RedirectResponse
    {
        if (! $organization->isSuspended()) {
            return back();
        }

        // Retour à l'essai s'il court encore, sinon abonnement actif.
        $status = $organization->trial_ends_at?->isFuture() ? Organization::STATUS_TRIAL : Organization::STATUS_ACTIVE;
        $organization->update(['status' => $status, 'suspended_at' => null, 'suspension_reason' => null]);
        PlatformLog::record('organization.reactivated', Organization::STATUSES[$status], ['status' => $status], $organization);

        return back()->with('success', 'Entreprise réactivée.');
    }

    /** Entreprises avec leurs compteurs (utilisateurs, clients) et leur dernière activité. */
    public static function listQuery(): Builder
    {
        return Organization::query()
            ->withCount('users')
            ->addSelect([
                'customers_count' => Customer::selectRaw('count(*)')->whereColumn('customers.organization_id', 'organizations.id'),
                // Dernière requête d'un de ses utilisateurs (sessions en base).
                'last_activity' => DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')
                    ->whereColumn('users.organization_id', 'organizations.id')
                    ->selectRaw('max(sessions.last_activity)'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Organization $o): array
    {
        $trialExpired = $o->status === Organization::STATUS_TRIAL && $o->trial_ends_at?->isPast();

        return [
            'id' => $o->id,
            'name' => $o->name,
            'sector' => $o->sector,
            'sector_label' => Organization::SECTORS[$o->sector]['label'] ?? 'Non renseigné',
            'offer' => $o->offerLabel(),
            'plan' => $o->plan,
            'plan_label' => Organization::PLANS[$o->plan]['label'] ?? $o->plan,
            'status' => $o->status,
            'status_label' => $trialExpired ? 'Essai terminé' : (Organization::STATUSES[$o->status] ?? $o->status),
            'trial_expired' => $trialExpired,
            'created_at' => $o->created_at?->toIso8601String(),
            'trial_ends_at' => $o->trial_ends_at?->toIso8601String(),
            'users_count' => (int) $o->users_count,
            'customers_count' => (int) $o->customers_count,
            'last_activity' => $o->last_activity ? date(DATE_ATOM, (int) $o->last_activity) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentLog(PlatformLog $log): array
    {
        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'user' => $log->user?->name,
            'action' => PlatformLog::ACTIONS[$log->action] ?? $log->action,
            'description' => $log->description,
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $list = fn (array $items) => collect($items)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => is_array($label) ? $label['label'] : $label])->values()->all();

        return [
            'statuses' => $list(self::STATUS_FILTERS),
            'sectors' => $list(Organization::SECTORS),
            'plans' => collect(Organization::PLANS)->map(fn ($p, $key) => ['value' => $key, 'label' => $p['label'].' · '.number_format($p['price'], 0, ',', ' ').' FCFA / mois'])->values()->all(),
            'offers' => [['value' => 'menentia', 'label' => 'NENTIX'], ['value' => 'retail', 'label' => 'Analyse commerciale']],
        ];
    }
}
