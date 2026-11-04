<?php

namespace App\Http\Controllers;

use App\Jobs\RecalculateLoyaltyJob;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramRule;
use App\Models\LoyaltyRedemption;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit;
use App\Services\CustomerSearch;
use App\Services\LoyaltyEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres › Programmes de fidélité (libre-service, rien en dur) et
 * bouton « Remise utilisée » de la fiche client.
 */
class LoyaltyProgramController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        $stats = DB::table('loyalty_cards')->where('organization_id', $organization->id)
            ->groupBy('loyalty_program_id', 'status')
            ->selectRaw('loyalty_program_id, status, COUNT(*) as total, COALESCE(SUM(points), 0) as points')
            ->get()->groupBy('loyalty_program_id');

        return Inertia::render('Settings/LoyaltyPrograms', [
            'legacyEnabled' => $organization->loyaltySettings()['enabled'],
            'rewardTypes' => collect(LoyaltyProgramRule::REWARD_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'brands' => Brand::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'programs' => LoyaltyProgram::where('organization_id', $organization->id)->with('rules.brand:id,name')->orderByDesc('starts_on')->get()
                ->map(fn (LoyaltyProgram $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'starts_on' => $p->starts_on->toDateString(),
                    'ends_on' => $p->ends_on->toDateString(),
                    'same_rules' => $p->same_rules,
                    'near_points' => $p->near_points,
                    'rules' => $p->rules->map(fn ($r) => [
                        'brand_id' => $r->brand_id,
                        'brand_name' => $r->brand?->name,
                        'threshold' => $r->threshold,
                        'tranche_amount' => $r->tranche_amount,
                        'points_per_tranche' => $r->points_per_tranche,
                        'activation_bonus' => $r->activation_bonus,
                        'tiers' => array_map(fn ($t) => [...$t, 'type' => $t['type'] ?? 'percent', 'reward' => LoyaltyProgramRule::reward($t, $organization->currencySymbol())['label']], $r->tiers),
                        'validity_months' => $r->validity_months,
                        'reset_after_use' => $r->reset_after_use,
                    ])->values(),
                    'active_members' => (int) ($stats[$p->id] ?? collect())->firstWhere('status', LoyaltyCard::STATUS_ACTIVE)?->total,
                    'expired_members' => (int) ($stats[$p->id] ?? collect())->firstWhere('status', LoyaltyCard::STATUS_EXPIRED)?->total,
                    'points' => (int) ($stats[$p->id] ?? collect())->sum('points'),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $data = $this->validated($request, $organization);

        $program = DB::transaction(function () use ($data, $organization) {
            $program = LoyaltyProgram::create(['organization_id' => $organization->id, 'name' => $data['name'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'same_rules' => $data['same_rules'], 'near_points' => $data['near_points'] ?? null]);
            $program->rules()->createMany($data['rules']);

            return $program;
        });

        Audit::record('loyalty.program_created', $program, "Programme de fidélité « {$program->name} » créé", $data);
        RecalculateLoyaltyJob::dispatch($organization->id);

        return back()->with('success', 'Programme créé : les cartes vont être calculées à partir des tickets.');
    }

    public function update(Request $request, LoyaltyProgram $program): RedirectResponse
    {
        $this->authorizeProgram($request, $program);
        $organization = $request->user()->organization;
        $data = $this->validated($request, $organization, $program);

        DB::transaction(function () use ($program, $data) {
            $program->update(['name' => $data['name'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'same_rules' => $data['same_rules'], 'near_points' => $data['near_points'] ?? null]);
            $program->rules()->whereNotIn('brand_id', array_column($data['rules'], 'brand_id'))->delete();

            foreach ($data['rules'] as $rule) {
                $program->rules()->updateOrCreate(['brand_id' => $rule['brand_id']], $rule);
            }
        });

        Audit::record('loyalty.program_updated', $program, "Programme de fidélité « {$program->name} » modifié", $data);
        RecalculateLoyaltyJob::dispatch($organization->id);

        return back()->with('success', 'Programme enregistré : les cartes vont être recalculées.');
    }

    public function destroy(Request $request, LoyaltyProgram $program): RedirectResponse
    {
        $this->authorizeProgram($request, $program);
        Audit::record('loyalty.program_deleted', $program, "Programme de fidélité « {$program->name} » supprimé", ['cards' => $program->cards()->count()]);
        $program->delete();

        return back()->with('success', 'Programme supprimé. Les tickets ne sont pas touchés.');
    }

    /** Remise utilisée en magasin : même effet qu'un ticket importé avec remise_fidelite. */
    public function redeem(Request $request, Customer $customer, LoyaltyCard $card, LoyaltyEngine $engine): RedirectResponse
    {
        $user = $request->user();
        abort_unless($customer->organization_id === $user->organization_id && $card->customer_id === $customer->id, 404);

        $stores = Store::where('organization_id', $user->organization_id)->where('brand_id', $card->brand_id)
            ->when($user->visibleStoreIds() !== null, fn ($q) => $q->whereIn('id', $user->visibleStoreIds()));
        abort_unless((clone $stores)->exists(), 403);

        $data = $request->validate([
            'store_id' => ['required', 'integer', Rule::in((clone $stores)->pluck('id'))],
            'tier_points' => ['required', 'integer'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'used_on' => ['required', 'date', 'before_or_equal:today'],
        ], ['store_id.required' => 'Choisissez le magasin.', 'tier_points.required' => 'Choisissez la récompense.']);

        if ($card->status !== LoyaltyCard::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['tier_points' => 'Cette carte n’est pas active.']);
        }

        $tier = collect($card->program->rules->firstWhere('brand_id', $card->brand_id)?->tiers ?? [])->firstWhere('points', $data['tier_points']);

        if ($tier === null) {
            throw ValidationException::withMessages(['tier_points' => 'Ce palier n’existe pas dans le programme.']);
        }

        $reward = LoyaltyProgramRule::reward($tier, $user->organization->currencySymbol());

        LoyaltyRedemption::create([
            'store_id' => $data['store_id'],
            'used_on' => $data['used_on'],
            'discount_percent' => $reward['percent'],
            'reward_label' => $reward['label'],
            'amount' => (int) ($data['amount'] ?? 0) ?: $reward['cost'],
            'organization_id' => $user->organization_id,
            'loyalty_program_id' => $card->loyalty_program_id,
            'brand_id' => $card->brand_id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
        ]);
        $engine->recalculate($user->organization, [$customer->id]);
        Audit::record('loyalty.discount_used', $customer, "Récompense utilisée : {$reward['label']}", $data);

        return back()->with('success', 'Récompense enregistrée.');
    }

    /**
     * Blocs de la fiche client : une carte par enseigne visible.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function cardsFor(Customer $customer, User $user): Collection
    {
        if (! $user->organization->hasModule('fidelite')) {
            return collect();
        }

        $storeQuery = Store::where('organization_id', $user->organization_id)
            ->when($user->visibleStoreIds() !== null, fn ($q) => $q->whereIn('id', $user->visibleStoreIds()));
        $stores = $storeQuery->get(['id', 'name', 'brand_id'])->groupBy('brand_id');

        $cards = $customer->loyaltyCards()->with(['brand:id,name', 'program.rules'])->whereIn('brand_id', $stores->keys())->get()
            ->sortByDesc(fn (LoyaltyCard $c) => [$c->program->starts_on->toDateString(), $c->status === LoyaltyCard::STATUS_ACTIVE]);
        $history = LoyaltyMovement::where('customer_id', $customer->id)->orderByDesc('happened_on')->orderByDesc('id')->get()
            ->groupBy(fn ($m) => "{$m->loyalty_program_id}-{$m->brand_id}");

        return $cards->values()->map(function (LoyaltyCard $card) use ($stores, $history) {
            $rule = $card->program->rules->firstWhere('brand_id', $card->brand_id);
            $currency = $card->program->organization?->currencySymbol() ?? 'FCFA';

            return [
                'id' => $card->id,
                'brand' => $card->brand->name,
                'program' => $card->program->name,
                'status' => $card->status,
                'status_label' => LoyaltyCard::STATUSES[$card->status] ?? $card->status,
                'points' => $card->points,
                'cycle_amount' => (int) $card->cycle_amount,
                'threshold' => $rule?->threshold,
                'amount_to_threshold' => (int) $card->amount_to_threshold,
                'tiers' => collect($rule?->tiers ?? [])->map(fn ($t) => LoyaltyProgramRule::reward($t, $currency))->sortBy('points')->values()
                    ->map(fn ($t) => [...$t, 'state' => match (true) {
                        $card->status !== LoyaltyCard::STATUS_ACTIVE => 'future',
                        $card->tier_points === $t['points'] => 'current',
                        $card->points >= $t['points'] => 'reached',
                        $t['points'] - $card->points <= CustomerSearch::NEAR_TIER_POINTS => 'near',
                        default => 'future',
                    }, 'missing' => max(0, $t['points'] - $card->points),
                        'used' => ($history["{$card->loyalty_program_id}-{$card->brand_id}"] ?? collect())->where('type', LoyaltyMovement::DISCOUNT_USED)->where('note', $t['label'])->count()]),
                'tier_points' => $card->tier_points,
                'reward_label' => $card->reward_label,
                'next_tier_points' => $card->next_tier_points,
                'points_to_next_tier' => $card->points_to_next_tier,
                'activated_on' => $card->activated_on?->toDateString(),
                'expires_on' => $card->expires_on?->toDateString(),
                'discounts_used' => $card->discounts_used,
                'stores' => ($stores[$card->brand_id] ?? collect())->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
                'history' => ($history["{$card->loyalty_program_id}-{$card->brand_id}"] ?? collect())->take(12)->map(fn (LoyaltyMovement $m) => [
                    'id' => $m->id,
                    'type' => LoyaltyMovement::TYPES[$m->type] ?? $m->type,
                    'points' => $m->points,
                    'balance' => $m->balance,
                    'note' => $m->note,
                    'on' => $m->happened_on->toDateString(),
                ])->values(),
            ];
        });
    }

    private function authorizeProgram(Request $request, LoyaltyProgram $program): void
    {
        abort_unless($program->organization_id === $request->user()->organization_id, 404);
    }

    /**
     * Palier enregistré : { points, percent } pour une remise en %, sinon le type et sa valeur.
     *
     * @param  array<string, mixed>  $tier
     * @return array<string, mixed>
     */
    private function tier(array $tier): array
    {
        $type = $tier['type'] ?? 'percent';
        $points = (int) $tier['points'];

        return match (true) {
            $type === 'percent' && (int) ($tier['percent'] ?? 0) > 0 => ['points' => $points, 'percent' => (int) $tier['percent']],
            in_array($type, ['amount', 'voucher'], true) && (int) ($tier['amount'] ?? 0) > 0 => ['points' => $points, 'type' => $type, 'amount' => (int) $tier['amount']],
            in_array($type, ['gift', 'perk'], true) && filled($tier['label'] ?? null) => ['points' => $points, 'type' => $type, 'label' => trim($tier['label'])],
            default => throw ValidationException::withMessages(['rules' => "Complétez la récompense du palier de {$points} points."]),
        };
    }

    /** @return array{name: string, starts_on: string, ends_on: string, same_rules: bool, rules: list<array<string, mixed>>} */
    private function validated(Request $request, Organization $organization, ?LoyaltyProgram $program = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'same_rules' => ['sometimes', 'boolean'],
            'near_points' => ['nullable', 'integer', 'min:1'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.brand_id' => ['required', 'integer', 'distinct', Rule::exists('brands', 'id')->where('organization_id', $organization->id)],
            'rules.*.threshold' => ['required', 'integer', 'min:0'],
            'rules.*.tranche_amount' => ['required', 'integer', 'min:1'],
            'rules.*.points_per_tranche' => ['required', 'integer', 'min:1'],
            'rules.*.activation_bonus' => ['required', 'integer', 'min:0'],
            'rules.*.tiers' => ['required', 'array', 'min:1'],
            'rules.*.tiers.*.points' => ['required', 'integer', 'min:1'],
            'rules.*.tiers.*.type' => ['nullable', Rule::in(array_keys(LoyaltyProgramRule::REWARD_TYPES))],
            'rules.*.tiers.*.percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rules.*.tiers.*.amount' => ['nullable', 'integer', 'min:0'],
            'rules.*.tiers.*.label' => ['nullable', 'string', 'max:120'],
            'rules.*.validity_months' => ['required', 'integer', 'min:1', 'max:120'],
            'rules.*.reset_after_use' => ['required', 'boolean'],
        ], [
            'name.required' => 'Donnez un nom au programme.',
            'ends_on.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'rules.required' => 'Ajoutez au moins une enseigne.',
            'rules.*.brand_id.distinct' => 'Une enseigne ne peut figurer qu’une fois dans un programme.',
            'rules.*.tiers.required' => 'Ajoutez au moins un palier.',
        ]);

        $overlap = LoyaltyProgram::where('organization_id', $organization->id)
            ->when($program, fn ($q) => $q->whereKeyNot($program->id))
            ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])
            ->whereHas('rules', fn ($q) => $q->whereIn('brand_id', array_column($data['rules'], 'brand_id')))
            ->first();

        if ($overlap) {
            throw ValidationException::withMessages(['rules' => "Une enseigne est déjà dans le programme « {$overlap->name} » sur ces dates : un ticket compterait deux fois."]);
        }

        $data['same_rules'] = (bool) ($data['same_rules'] ?? false);
        $first = $data['rules'][array_key_first($data['rules'])];

        $data['rules'] = array_map(function (array $rule) use ($data, $first) {
            // Mêmes règles : une seule grille pour toutes les enseignes, compteurs séparés.
            $rule = $data['same_rules'] ? [...$first, 'brand_id' => $rule['brand_id']] : $rule;
            $tiers = collect($rule['tiers'])->map(fn ($t) => $this->tier($t))->sortBy('points')->values();

            if ($tiers->pluck('points')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['rules' => 'Deux paliers d’une enseigne ont le même nombre de points.']);
            }

            return [...$rule, 'tiers' => $tiers->all()];
        }, array_values($data['rules']));

        return $data;
    }
}
