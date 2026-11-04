<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Group;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramRule;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit;
use App\Services\RfmCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Clients › Fidélité : une ligne par client et par enseigne, en trois onglets.
 * Atteinte : récompense disponible, non utilisée. Proche : à moins de X points
 * du prochain palier ou du seuil (programs.near_points, sinon 20 % de l'écart).
 * À venir : le reste, avec les points manquants, le CA restant et une date
 * estimée d'après le rythme d'achat du client dans l'enseigne. Un client peut
 * être à la fois dans Atteinte et Proche (palier suivant). Les cartes sont
 * lues telles que LoyaltyEngine les a calculées.
 */
class LoyaltyTrackingController extends Controller
{
    public const TABS = ['reached', 'near', 'future'];

    private const SORTS = ['name', 'brand', 'points', 'missing', 'expires_on', 'estimated_on'];

    private const PER_PAGE = 25;

    /** Rythme d'achat : jamais calculé sur moins d'un mois. */
    private const MIN_RATE_DAYS = 30;

    public function index(Request $request): Response
    {
        [$program, $rules, $filters, $stores] = $this->context($request);
        $rows = $program ? $this->rows($program, $rules, $filters, $request->user()) : collect();
        $tab = $filters['tab'];
        $visible = $this->sorted($rows->filter(fn ($r) => in_array($tab, $r['tabs'], true)), $filters);
        $page = max(1, $request->integer('page', 1));

        return Inertia::render('Loyalty/Index', [
            'programs' => LoyaltyProgram::where('organization_id', $request->user()->organization_id)->orderByDesc('starts_on')->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->values(),
            'brands' => $rules->map(fn ($r) => ['value' => $r->brand_id, 'label' => $r->brand->name])->values(),
            'stores' => $stores->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values(),
            'segments' => collect(RfmCalculator::SEGMENTS)->map(fn ($s, $key) => ['value' => $key, 'label' => $s['label']])->values(),
            'filters' => $filters + ['program' => $program?->id],
            'counts' => collect(self::TABS)->mapWithKeys(fn ($t) => [$t => $rows->filter(fn ($r) => in_array($t, $r['tabs'], true))->count()]),
            'cards' => new LengthAwarePaginator($visible->forPage($page, self::PER_PAGE)->values(), $visible->count(), self::PER_PAGE, $page, ['path' => '/fidelite']),
            'canExport' => $request->user()->canExport(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        if (! $request->user()->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }

        [$program, $rules, $filters] = $this->context($request);
        abort_if($program === null, 404);
        $rows = $this->sorted($this->rows($program, $rules, $filters, $request->user())->filter(fn ($r) => in_array($filters['tab'], $r['tabs'], true)), $filters);
        Audit::export("suivi fidélité {$filters['tab']}", $rows->count(), [...$filters, 'personal' => true]);

        $lines = $rows->map(fn ($r) => [$r['name'], $r['phone'], $r['brand'], $r['status'], $r['points'], $r['reward'], $r['reached_on'], $r['expires_on'], $r['next'], $r['missing'], $r['amount_left'], $r['estimated_on']])->all();
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Suivi fidélité')->fromArray([
            ['Client', 'Téléphone', 'Enseigne', 'Statut', 'Points', 'Récompense atteinte', 'Atteinte le', 'Valable jusqu’au', 'Prochaine récompense', 'Points manquants', 'CA restant', 'Date estimée'],
            ...$lines,
        ], null, 'A1', true);

        return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), "menentia-suivi-fidelite-{$filters['tab']}-".now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Enregistre les clients de l'onglet affiché comme groupe de campagne. */
    public function group(Request $request): RedirectResponse
    {
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:255']])['name']);
        [$program, $rules, $filters, $stores] = $this->context($request);
        abort_if($program === null, 404);

        $user = $request->user();
        $rows = $this->rows($program, $rules, $filters, $user)->filter(fn ($r) => in_array($filters['tab'], $r['tabs'], true));
        // Le contact du client dans l'enseigne de la ligne, pas ceux de ses autres enseignes.
        $contacts = $rows->groupBy('brand_id')->flatMap(function ($lines, $brandId) use ($user) {
            $storeIds = Store::where('brand_id', $brandId)->when($user->visibleStoreIds() !== null, fn ($q) => $q->whereIn('id', $user->visibleStoreIds()))->pluck('id');

            return $lines->pluck('customer_id')->chunk(500)
                ->flatMap(fn ($ids) => Contact::whereIn('customer_id', $ids->all())->whereIn('store_id', $storeIds)->pluck('id'));
        });

        $group = Group::firstOrCreate(['organization_id' => $user->organization_id, 'name' => $name]);
        $group->contacts()->syncWithoutDetaching($contacts->all());

        return back()->with('success', "{$rows->pluck('customer_id')->unique()->count()} client(s) dans le groupe « {$group->name} ».");
    }

    /** @return array{0: LoyaltyProgram|null, 1: Collection<int, LoyaltyProgramRule>, 2: array<string, mixed>, 3: Collection<int, Store>} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $organizationId = $user->organization_id;
        $programs = LoyaltyProgram::where('organization_id', $organizationId)->orderByDesc('starts_on')->get();
        $program = $programs->firstWhere('id', $request->integer('program'))
            ?? $programs->first(fn ($p) => $p->starts_on->lte(today()) && $p->ends_on->gte(today()))
            ?? $programs->first();

        $visible = $user->visibleStoreIds();
        $roleStores = Store::where('organization_id', $organizationId)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->orderBy('name')->get(['id', 'name', 'brand_id']);
        $rules = $program ? $program->rules()->whereIn('brand_id', $roleStores->pluck('brand_id'))->with('brand:id,name')->get() : collect();
        $brand = $rules->contains('brand_id', $request->integer('brand')) ? $request->integer('brand') : null;
        $stores = $brand ? $roleStores->where('brand_id', $brand)->values() : $roleStores;
        $store = $stores->contains('id', $request->integer('store')) ? $request->integer('store') : null;

        return [$program, $rules, [
            'tab' => in_array($request->input('tab'), self::TABS, true) ? (string) $request->input('tab') : 'reached',
            'brand' => $brand,
            'store' => $store,
            'segment' => array_key_exists((string) $request->input('segment'), RfmCalculator::SEGMENTS) ? (string) $request->input('segment') : null,
            'q' => trim((string) $request->input('q')),
            'sort' => in_array($request->input('sort'), self::SORTS, true) ? (string) $request->input('sort') : null,
            'dir' => $request->input('dir') === 'desc' ? 'desc' : 'asc',
        ], $stores];
    }

    /**
     * Toutes les lignes des filtres, avec leurs onglets.
     *
     * @param  Collection<int, LoyaltyProgramRule>  $rules
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(LoyaltyProgram $program, Collection $rules, array $filters, User $user): Collection
    {
        $brandIds = $filters['brand'] ? [$filters['brand']] : $rules->pluck('brand_id')->all();
        $currency = $user->organization->currencySymbol();
        $open = $program->ends_on->gte(today());
        $today = today();

        $cards = DB::table('loyalty_cards as c')
            ->join('customers as k', 'k.id', '=', 'c.customer_id')
            ->where('c.loyalty_program_id', $program->id)
            ->whereIn('c.brand_id', $brandIds ?: [0])
            ->when($filters['segment'], fn ($q, $segment) => $q->where('k.rfm_segment', $segment))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($q) => $q->where('k.name', 'like', "%{$filters['q']}%")->orWhere('k.phone_e164', 'like', '%'.ltrim(preg_replace('/\D+/', '', $filters['q']) ?: $filters['q'], '0').'%')))
            ->when($filters['store'], fn ($q, $store) => $q->whereExists(fn ($e) => $e->from('contacts')->whereColumn('contacts.customer_id', 'c.customer_id')->where('contacts.store_id', $store)))
            ->when($user->ownRecordsOnly(), fn ($q) => $q->where('k.owner_user_id', $user->id))
            ->get(['c.id', 'c.customer_id', 'c.brand_id', 'c.status', 'c.points', 'c.cycle_amount', 'c.reward_label', 'c.tier_points', 'c.next_tier_points', 'c.points_to_next_tier', 'c.amount_to_threshold', 'c.expires_on', 'k.name', 'k.phone_e164']);

        $reachedOn = LoyaltyMovement::where('loyalty_program_id', $program->id)->where('type', LoyaltyMovement::TIER)
            ->groupBy('customer_id', 'brand_id')->selectRaw('customer_id, brand_id, MAX(happened_on) as day')->get()
            ->mapWithKeys(fn ($m) => ["{$m->customer_id}-{$m->brand_id}" => substr((string) $m->day, 0, 10)]);
        $metrics = DB::table('customer_brand_metrics')->where('organization_id', $user->organization_id)->whereIn('brand_id', $brandIds ?: [0])
            ->get(['customer_id', 'brand_id', 'net_spend', 'first_purchase_at'])->keyBy(fn ($m) => "{$m->customer_id}-{$m->brand_id}");
        $settings = $rules->keyBy('brand_id')->map(fn (LoyaltyProgramRule $r) => [
            'rule' => $r,
            'tiers' => collect($r->tiers)->map(fn ($t) => LoyaltyProgramRule::reward($t, $currency))->sortBy('points')->values(),
        ]);
        $brandStores = Store::whereIn('brand_id', $brandIds ?: [0])->when($user->visibleStoreIds() !== null, fn ($q) => $q->whereIn('id', $user->visibleStoreIds()))->orderBy('name')->get(['id', 'name', 'brand_id'])->groupBy('brand_id');

        return $cards->map(function ($c) use ($program, $settings, $open, $today, $reachedOn, $metrics, $brandStores) {
            $rule = $settings[$c->brand_id]['rule'];
            $tiers = $settings[$c->brand_id]['tiers'];
            $active = $c->status === LoyaltyCard::STATUS_ACTIVE;
            $perPoint = fn (int $points) => (int) ceil($points / max(1, $rule->points_per_tranche)) * $rule->tranche_amount;

            if ($active) {
                $next = $c->next_tier_points !== null ? $tiers->firstWhere('points', $c->next_tier_points) : null;
                $missing = $c->points_to_next_tier;
                $gap = $next ? $next['points'] - (int) $c->tier_points : 0;
                $earned = intdiv((int) $c->cycle_amount, max(1, $rule->tranche_amount)) * $rule->points_per_tranche;
                $amountLeft = $next ? max(0, $perPoint($next['points'] - ($c->points - $earned)) - (int) $c->cycle_amount) : null;
                $nextLabel = $next['label'] ?? null;
            } else {
                $amountLeft = (int) $c->amount_to_threshold;
                $missing = (int) ceil($amountLeft / max(1, $rule->tranche_amount)) * $rule->points_per_tranche;
                $gap = intdiv($rule->threshold, max(1, $rule->tranche_amount)) * $rule->points_per_tranche;
                $nextLabel = 'Entrée au programme';
            }

            $reached = $active && $c->tier_points !== null;
            $near = $open && $missing !== null && $missing <= ($program->near_points ?? (int) ceil($gap * 0.2));
            $future = $open && ! $reached && ! $near && $missing !== null;
            $key = "{$c->customer_id}-{$c->brand_id}";

            return [
                'id' => $c->id,
                'customer_id' => $c->customer_id,
                'brand_id' => $c->brand_id,
                'name' => $c->name,
                'phone' => $c->phone_e164,
                'brand' => $rule->brand->name,
                'status' => LoyaltyCard::STATUSES[$c->status] ?? $c->status,
                'points' => (int) $c->points,
                'reward' => $reached ? $c->reward_label : null,
                'tier_points' => $reached ? (int) $c->tier_points : null,
                'reached_on' => $reached ? ($reachedOn[$key] ?? null) : null,
                'expires_on' => $active ? substr((string) $c->expires_on, 0, 10) : null,
                'next' => $nextLabel,
                'missing' => $missing,
                'amount_left' => $amountLeft,
                'estimated_on' => $open ? $this->estimate($metrics[$key] ?? null, $amountLeft, $today, $program) : null,
                'tabs' => array_keys(array_filter(['reached' => $reached, 'near' => $near, 'future' => $future])),
                'stores' => ($brandStores[$c->brand_id] ?? collect())->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
                'tiers' => $tiers,
            ];
        });
    }

    /** Date estimée d'après le CA par jour du client dans l'enseigne ; rien au-delà de la fin du programme. */
    private function estimate(?object $metric, ?int $amountLeft, Carbon $today, LoyaltyProgram $program): ?string
    {
        if ($metric === null || ! $amountLeft || (int) $metric->net_spend <= 0 || $metric->first_purchase_at === null) {
            return null;
        }

        $days = max(self::MIN_RATE_DAYS, (int) Carbon::parse($metric->first_purchase_at)->diffInDays($today));
        $date = $today->copy()->addDays((int) ceil($amountLeft / ($metric->net_spend / $days)));

        return $date->gt($program->ends_on) ? null : $date->toDateString();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sorted(Collection $rows, array $filters): Collection
    {
        $sort = $filters['sort'] ?? ['reached' => 'expires_on', 'near' => 'missing', 'future' => 'missing'][$filters['tab']];
        $sorted = $rows->sortBy(fn ($r) => [$r[$sort] === null ? 1 : 0, $r[$sort]]);

        return ($filters['dir'] === 'desc' ? $sorted->reverse() : $sorted)->values();
    }
}
