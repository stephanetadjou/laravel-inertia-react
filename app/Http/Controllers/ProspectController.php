<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\Audit;
use App\Services\PhoneNormalizer;
use App\Services\ProspectPipeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clients › Prospects (module « prospects ») : Kanban et liste par étape,
 * nouveau prospect, changement d'étape (motif pour « perdu »), réglages des
 * étapes et des primes. Un commercial ne voit que ses fiches.
 */
class ProspectController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = $user->organization;
        $pipeline = ProspectPipeline::for($organization);
        $stages = $pipeline->stages();
        $ownerFilter = $user->ownRecordsOnly() ? $user->id : ($request->integer('owner') ?: null);
        $q = trim((string) $request->query('q', ''));
        $lateOnly = $request->boolean('late');

        // Gagnés et perdus : seulement ceux des 30 derniers jours dans le tableau.
        $finals = $stages->whereIn('kind', ['gagne', 'perdu'])->pluck('key')->all();
        $customers = self::scoped($user)
            ->whereIn('prospect_stage', $stages->pluck('key'))
            ->where(fn ($w) => $w->whereNotIn('prospect_stage', $finals)->orWhere('prospect_stage_at', '>=', now()->subDays(30)))
            ->when($ownerFilter, fn ($w) => $w->where('owner_user_id', $ownerFilter))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('company_name', 'like', "%{$q}%")->orWhere('phone_e164', 'like', '%'.ltrim(preg_replace('/\D+/', '', $q), '0').'%')))
            ->with('owner:id,name')
            ->orderBy('prospect_stage_at')
            ->limit(500)
            ->get()
            ->map(fn (Customer $c) => self::presentCard($c, $pipeline))
            ->when($lateOnly, fn ($c) => $c->where('late', true))
            ->values();

        return Inertia::render('Prospects/Index', [
            'stages' => $stages,
            'prospects' => $customers,
            'filters' => ['owner' => $ownerFilter, 'q' => $q, 'late' => $lateOnly, 'view' => $request->query('view') === 'list' ? 'list' : 'kanban'],
            'owners' => $user->ownRecordsOnly() ? [] : self::salesPeople($organization),
            'stores' => Store::whereIn('id', PlanController::storeIds($user))->orderBy('name')->get(['id', 'name']),
            'canAssign' => ! $user->ownRecordsOnly(),
        ]);
    }

    /** Nouveau prospect : sa fiche client (numéro facultatif si l'entreprise n'en a pas encore), à la première étape. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'store_id' => ['required', 'integer', Rule::in(PlanController::storeIds($user))],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $organization->id)],
            'referral_code' => ['nullable', 'string', 'max:20'],
            'stage' => ['nullable', 'string', 'max:40'],
        ]);

        $phone = PhoneNormalizer::normalize($data['phone']) ?? throw ValidationException::withMessages(['phone' => 'Numéro invalide.']);
        if (Customer::where('organization_id', $organization->id)->where('phone_e164', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro a déjà une fiche client : ouvrez-la pour la suivre.']);
        }

        // Commercial : lui-même ; sinon celui choisi, ou celui du code de parrainage, ou le créateur.
        $owner = match (true) {
            $user->ownRecordsOnly() => $user,
            filled($data['owner_user_id'] ?? null) => User::find($data['owner_user_id']),
            filled($data['referral_code'] ?? null) => User::where('organization_id', $organization->id)->where('referral_code', strtoupper(trim($data['referral_code'])))->first()
                ?? throw ValidationException::withMessages(['referral_code' => 'Code commercial inconnu.']),
            default => $user,
        };

        $customer = DB::transaction(function () use ($data, $phone, $owner, $user, $organization) {
            $customer = Contact::create(['store_id' => $data['store_id'], 'name' => $data['name'], 'phone_e164' => $phone])->customer;
            $customer->forceFill(['company_name' => $data['company_name'] ?? null, 'owner_user_id' => $owner->id])->save();
            $pipeline = ProspectPipeline::for($organization);
            $pipeline->move($customer, $pipeline->stage($data['stage'] ?? null)['key'] ?? $pipeline->firstStage()['key'], $user);

            return $customer;
        });

        Audit::record('prospect.created', $customer, "Prospect « {$customer->name} » (commercial : {$owner->name})", [], $user);

        return back()->with('success', "Prospect « {$customer->name} » ajouté, suivi par {$owner->name}.");
    }

    public function move(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeCustomer($user, $customer);
        $data = $request->validate(['stage' => ['required', 'string', 'max:40'], 'lost_reason' => ['nullable', 'string', 'max:255']]);

        $pipeline = ProspectPipeline::for($user->organization);
        $pipeline->move($customer, $data['stage'], $user, $data['lost_reason'] ?? null);

        return back()->with('success', "{$customer->name} : étape « {$pipeline->stage($data['stage'])['label']} ».");
    }

    /** Ajoute une fiche existante au suivi (fiche client). */
    public function follow(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeCustomer($user, $customer);
        ProspectPipeline::for($user->organization)->start($customer, $user, $user);

        return back()->with('success', "{$customer->name} est maintenant dans le suivi des prospects.");
    }

    public function assign(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->ownRecordsOnly(), 403);
        $this->authorizeCustomer($user, $customer);
        $data = $request->validate(['owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $user->organization_id)]]);
        $customer->forceFill(['owner_user_id' => $data['owner_user_id'] ?? null])->save();

        return back()->with('success', 'Commercial responsable enregistré.');
    }

    public function settings(Request $request): Response
    {
        $organization = $request->user()->organization;
        $settings = $organization->moduleSettings('prospects');

        return Inertia::render('Settings/Prospects', [
            'stages' => ProspectPipeline::for($organization)->stages(),
            'kinds' => Organization::PROSPECT_KINDS,
            'commissionRate' => (int) $settings['commission_rate'],
            'commissionGrid' => (object) $settings['commission_grid'],
            'plans' => Plan::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'price']),
            'salesPeople' => User::where('organization_id', $organization->id)->orderBy('name')->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'code' => $u->referralCode()]),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'stages' => ['required', 'array', 'min:2', 'max:12'],
            'stages.*.key' => ['nullable', 'string', 'max:40'],
            'stages.*.label' => ['required', 'string', 'max:60'],
            'stages.*.kind' => ['required', Rule::in(array_keys(Organization::PROSPECT_KINDS))],
            'stages.*.delay_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'commission_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'commission_grid' => ['nullable', 'array'],
            'commission_grid.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $kinds = collect($data['stages'])->pluck('kind');
        if (! $kinds->contains('gagne') || ! $kinds->contains('perdu')) {
            throw ValidationException::withMessages(['stages' => 'Gardez au moins une étape « Client » (gagné) et une étape « Perdu ».']);
        }

        $keys = [];
        $stages = collect($data['stages'])->map(function ($s) use (&$keys) {
            $key = filled($s['key'] ?? null) ? (string) $s['key'] : Str::slug($s['label'], '_');
            while (in_array($key, $keys, true)) {
                $key .= '_2';
            }
            $keys[] = $key;

            return ['key' => $key, 'label' => trim($s['label']), 'kind' => $s['kind'], 'delay_days' => (int) ($s['delay_days'] ?? 0)];
        })->values()->all();

        $organization = $request->user()->organization;
        $organization->setModules($organization->enabledModules(), ['prospects' => [
            'stages' => $stages,
            'commission_rate' => (int) $data['commission_rate'],
            'commission_grid' => array_filter(array_map('intval', $data['commission_grid'] ?? []), fn ($v) => $v > 0),
        ]]);
        $organization->save();
        Audit::record('settings.updated', $organization, 'Étapes du suivi des prospects', ['stages' => $stages]);

        return back()->with('success', 'Étapes et primes enregistrées.');
    }

    /** Fiches de l'organisation visibles par l'utilisateur (un commercial : les siennes). @return Builder<Customer> */
    public static function scoped(User $user): Builder
    {
        $visible = $user->visibleStoreIds();

        return Customer::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereHas('contacts', fn ($c) => $c->whereIn('store_id', $visible)))
            ->when($user->ownRecordsOnly(), fn ($q) => $q->where('owner_user_id', $user->id));
    }

    /** @return list<array{id: int, name: string}> */
    public static function salesPeople(Organization $organization): array
    {
        return User::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
    }

    /** @return array<string, mixed> */
    public static function presentCard(Customer $c, ProspectPipeline $pipeline): array
    {
        $lateness = $pipeline->lateness($c);
        $phone = $c->phone_e164;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'company' => $c->company_name,
            'phone' => $phone,
            'stage' => $c->prospect_stage,
            'since' => $c->prospect_stage_at?->toIso8601String(),
            'days' => $lateness['days'],
            'late' => $lateness['late'],
            'lost_reason' => $c->lost_reason,
            'owner' => $c->owner?->name,
            'owner_id' => $c->owner_user_id,
            'whatsapp_url' => $phone ? ActionLists::whatsappUrl($phone, '') : null,
        ];
    }

    private function authorizeCustomer(User $user, Customer $customer): void
    {
        abort_unless(self::scoped($user)->whereKey($customer->id)->exists(), 404);
    }
}
