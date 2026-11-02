<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesBrandFilter;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupClass;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filtre des fiches clients, partagé par la liste, l'export et la création
 * d'une campagne depuis une sélection de clients.
 *
 * Deux niveaux de critères :
 * - la fiche (personne) : étape du parcours, recherche nom / numéro ;
 * - le contact (relation avec une boutique) : boutiques du périmètre ∩ marque
 *   ∩ boutique choisie, VIP de la boutique, consentement WhatsApp.
 *
 * Une fiche correspond si l'un de ses contacts correspond aux critères de
 * contact : « VIP de Mango » = clients dont le contact Mango est VIP. Ce sont
 * ces mêmes contacts qui reçoivent une campagne lancée depuis la sélection.
 */
class CustomerSearch
{
    use ResolvesBrandFilter;

    public const CONSENTS = ['opt_in', 'unknown', 'opt_out'];

    /** @var array{selected: int|null, locked: bool, hidden: bool} */
    public array $brandFilter;

    /** @var Collection<int, Store> Boutiques du périmètre du rôle. */
    public Collection $roleStores;

    /** @var Collection<int, Store> Boutiques du rôle ∩ marque choisie. */
    public Collection $brandStores;

    public ?int $storeId;

    /** @var array<int, int> Boutiques retenues (rôle ∩ marque ∩ boutique). */
    public array $storeIds;

    public ?string $stage;

    public bool $vip;

    /** Segment RFM enregistré sur la fiche (cf. CustomerScoring). */
    public ?string $segment;

    /** Risque de churn : medium, high, ou « any » (l'un ou l'autre). */
    public ?string $risk;

    public ?string $consent;

    public string $search;

    /** Groupe choisi (de l'organisation) : seuls ses membres. */
    public ?Group $group;

    /** Famille de produits ciblée (cf. FamilyAnalytics). */
    public ?Category $family;

    /** bought = a acheté la famille dans les N derniers jours (0 = un jour), lapsed = l'a achetée mais plus depuis N jours. */
    public string $familyMode;

    public int $familyDays;

    /** Famille jamais achetée (vente croisée : « achète A mais jamais B »). */
    public ?Category $familyNot;

    /** Attribut d'achat ciblé (« genre:Femme ») : a acheté un article avec cette valeur. */
    public ?array $property = null;

    /** Article ciblé (niveau d'analyse « article ») : a acheté cet article. */
    public ?Article $article = null;

    /** multi = a acheté dans au moins deux marques, mono = dans une seule (cf. BrandMetrics). */
    public ?string $brands = null;

    /** Vente croisée : fidèle de la marque choisie, jamais venu dans cette autre marque. */
    public ?int $neverBrand = null;

    /** Segments RFM retenus (« at_risk,cant_lose ») ; $segment = le premier. @var list<string> */
    public array $segments = [];

    /**
     * Critères venus d'une analyse (tableaux de bord, multimarque, cohortes,
     * constats « À retenir »), pour exporter ou relancer exactement les
     * clients d'un chiffre : bought_from / bought_to (a acheté sur la
     * période), first_from / first_to (premier achat sur la période),
     * first_before (déjà client avant cette date), with_brand (a aussi acheté
     * dans cette marque), customer_type (person / business).
     *
     * @var array<string, string|int>
     */
    public array $analysis = [];

    public const ANALYSIS_KEYS = ['bought_from', 'bought_to', 'first_from', 'first_to', 'first_before', 'with_brand', 'customer_type'];

    /**
     * Critère d'un module (« suivi ») : unpaid = tranche en retard,
     * ending:N = abonnement qui finit dans N jours, absent:N = absent N fois
     * ces 30 derniers jours, class:ID = inscrit au cours.
     */
    public ?string $follow = null;

    public const FILTER_KEYS = ['stage', 'vip', 'consent', 'q', 'brand', 'store', 'group', 'segment', 'risk', 'family', 'family_mode', 'family_days', 'family_not', 'prop', 'article', 'brands', 'never_brand', 'follow',
        'bought_from', 'bought_to', 'first_from', 'first_to', 'first_before', 'with_brand', 'customer_type'];

    public const FAMILY_MODES = ['bought', 'lapsed'];

    public const FAMILY_DAYS = [0, 30, 60, 90, 180, 365];

    /**
     * @param  array<string, mixed>  $filters  stage, vip, consent, q, brand, store, group, segment, risk,
     *                                         family, family_mode, family_days, family_not, prop
     */
    public function __construct(public User $user, array $filters)
    {
        $visibleIds = $user->visibleStoreIds();
        $this->brandFilter = $this->resolveBrandFilter(new Request($filters), $user, $visibleIds);

        $this->roleStores = Store::where('organization_id', $user->organization_id)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->with('brand')
            ->orderBy('name')
            ->get();

        $this->brandStores = $this->brandFilter['selected'] !== null
            ? $this->roleStores->where('brand_id', $this->brandFilter['selected'])->values()
            : $this->roleStores;

        $storeId = (int) ($filters['store'] ?? 0) ?: null;
        $this->storeId = $this->brandStores->contains('id', $storeId) ? $storeId : null;
        $this->storeIds = $this->storeId !== null ? [$this->storeId] : $this->brandStores->pluck('id')->all();

        $this->stage = array_key_exists((string) ($filters['stage'] ?? ''), Customer::STAGES) ? (string) $filters['stage'] : null;
        $this->vip = filter_var($filters['vip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->segments = array_values(array_filter(explode(',', (string) ($filters['segment'] ?? '')), fn ($s) => array_key_exists($s, RfmCalculator::SEGMENTS)));
        $this->segment = $this->segments[0] ?? null;

        foreach (self::ANALYSIS_KEYS as $key) {
            $value = trim((string) ($filters[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            if (str_ends_with($key, '_from') || str_ends_with($key, '_to') || $key === 'first_before') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $this->analysis[$key] = $value;
                }
            } elseif ($key === 'with_brand') {
                if (Store::where('organization_id', $user->organization_id)->where('brand_id', (int) $value)->exists()) {
                    $this->analysis[$key] = (int) $value;
                }
            } elseif (in_array($value, [Customer::TYPE_PERSON, Customer::TYPE_BUSINESS], true)) {
                $this->analysis[$key] = $value;
            }
        }
        $this->risk = in_array($filters['risk'] ?? null, ['medium', 'high', 'any'], true) ? (string) $filters['risk'] : null;
        $this->consent = in_array($filters['consent'] ?? null, self::CONSENTS, true) ? (string) $filters['consent'] : null;
        $this->search = trim((string) ($filters['q'] ?? ''));
        $groupId = (int) ($filters['group'] ?? 0);
        $this->group = $groupId ? Group::where('organization_id', $user->organization_id)->find($groupId) : null;

        $familyId = (int) ($filters['family'] ?? 0);
        $familyNotId = (int) ($filters['family_not'] ?? 0);
        $this->family = $familyId ? Category::where('organization_id', $user->organization_id)->find($familyId) : null;
        $this->familyNot = $familyNotId ? Category::where('organization_id', $user->organization_id)->find($familyNotId) : null;
        $this->familyMode = in_array($filters['family_mode'] ?? null, self::FAMILY_MODES, true) ? (string) $filters['family_mode'] : 'bought';
        $days = (int) ($filters['family_days'] ?? 90);
        $this->familyDays = in_array($days, self::FAMILY_DAYS, true) ? $days : 90;

        $this->brands = in_array($filters['brands'] ?? null, ['multi', 'mono'], true) ? (string) $filters['brands'] : null;
        $neverBrand = (int) ($filters['never_brand'] ?? 0);
        $this->neverBrand = $neverBrand && $this->roleStores->contains('brand_id', $neverBrand) || ($neverBrand && $user->visibleStoreIds() === null && Store::where('organization_id', $user->organization_id)->where('brand_id', $neverBrand)->exists()) ? $neverBrand : null;

        $follow = (string) ($filters['follow'] ?? '');
        $this->follow = preg_match('/^(unpaid|ending:\d{1,3}|absent:\d{1,2}|class:\d+)$/', $follow) ? $follow : null;

        $articleId = (int) ($filters['article'] ?? 0);
        $this->article = $articleId ? Article::where('organization_id', $user->organization_id)->find($articleId) : null;

        $prop = (string) ($filters['prop'] ?? '');
        if (preg_match('/^([a-z0-9_]{1,64}):(.{1,100})$/u', $prop, $m)) {
            $this->property = ['key' => $m[1], 'value' => $m[2]];
        }

        // « N'achète plus » sans délai n'a pas de sens : 90 jours par défaut.
        if ($this->familyMode === 'lapsed' && $this->familyDays === 0) {
            $this->familyDays = 90;
        }
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->user(), $request->only(self::FILTER_KEYS));
    }

    /**
     * Critères au niveau du contact (boutique, VIP, consentement, groupe), à utiliser
     * dans un whereHas('contacts', …) ou directement sur Contact.
     *
     * @param  array{vip?: bool, consent?: bool}  $ignore  critères à ne pas appliquer (compteurs)
     */
    public function contactScope(array $ignore = []): Closure
    {
        return fn (Builder $q) => $q->whereIn('contacts.store_id', $this->storeIds)
            ->when($this->vip && empty($ignore['vip']), fn ($q) => $q->where('contacts.is_vip', true))
            ->when($this->consent !== null && empty($ignore['consent']), fn ($q) => $q->where('contacts.consent_status', $this->consent))
            ->when($this->group !== null, fn ($q) => $q->whereHas('groups', fn ($q) => $q->where('groups.id', $this->group->id)));
    }

    /**
     * Fiches correspondant à la recherche et aux critères de contact, sans
     * le filtre d'étape (utile pour compter par étape).
     *
     * @param  array{vip?: bool, consent?: bool}  $ignore
     * @return Builder<Customer>
     */
    public function baseQuery(array $ignore = []): Builder
    {
        return Customer::where('organization_id', $this->user->organization_id)
            ->whereHas('contacts', $this->contactScope($ignore))
            // Segment de la marque choisie (RFM par marque), sinon segment du groupe.
            ->when($this->segment !== null && $this->brandFilter['selected'] !== null, fn ($q) => $q->whereExists(fn ($m) => $m->from('customer_brand_metrics')
                ->whereColumn('customer_brand_metrics.customer_id', 'customers.id')
                ->where('customer_brand_metrics.brand_id', $this->brandFilter['selected'])
                ->whereIn('customer_brand_metrics.rfm_segment', $this->segments)))
            ->when($this->segment !== null && $this->brandFilter['selected'] === null, fn ($q) => $q->whereIn('rfm_segment', $this->segments))
            ->when($this->analysis !== [], fn ($q) => $this->applyAnalysis($q))
            ->when($this->brands === 'multi', fn ($q) => $q->where('brands_count', '>=', 2))
            ->when($this->brands === 'mono', fn ($q) => $q->where('brands_count', 1))
            ->when($this->neverBrand !== null, fn ($q) => $q->whereNotExists(fn ($m) => $m->from('customer_brand_metrics')
                ->whereColumn('customer_brand_metrics.customer_id', 'customers.id')
                ->where('customer_brand_metrics.brand_id', $this->neverBrand)
                ->where('customer_brand_metrics.order_count', '>', 0)))
            ->when($this->risk === 'any', fn ($q) => $q->whereIn('churn_risk', ['medium', 'high']))
            ->when(in_array($this->risk, ['medium', 'high'], true), fn ($q) => $q->where('churn_risk', $this->risk))
            ->when($this->family !== null || $this->familyNot !== null || $this->property !== null || $this->article !== null, fn ($q) => $this->applyFamilies($q))
            ->when($this->follow !== null, fn ($q) => $q->whereIn('customers.id', self::followQuery($this->user->organization_id, $this->follow)))
            ->when($this->search !== '', function ($q) {
                $digits = ltrim(preg_replace('/\D+/', '', $this->search), '0'); // 0 du format local ignoré
                $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->when($digits !== '', fn ($q) => $q->orWhere('phone_e164', 'like', "%{$digits}%")));
            });
    }

    /**
     * Fiches d'un critère de module (cf. $follow). Une tranche ou un
     * abonnement concerne le payeur et la personne suivie.
     */
    public static function followQuery(int $organizationId, string $follow): \Illuminate\Database\Query\Builder
    {
        [$kind, $value] = array_pad(explode(':', $follow, 2), 2, null);
        $today = today()->toDateString();
        $both = fn ($query, string $table) => DB::query()->fromSub($query->select("{$table}.customer_id as id")
            ->unionAll((clone $query)->whereNotNull("{$table}.beneficiary_id")->select("{$table}.beneficiary_id as id")), 'ids')->select('id');

        return match ($kind) {
            'unpaid' => $both(DB::table('installments')->join('payment_schedules', 'payment_schedules.id', '=', 'installments.payment_schedule_id')
                ->where('payment_schedules.organization_id', $organizationId)->where('payment_schedules.status', 'active')
                ->whereNull('installments.paid_at')->where('installments.due_on', '<', $today), 'payment_schedules'),
            'ending' => $both(DB::table('subscriptions')->where('organization_id', $organizationId)->where('status', 'active')
                ->where('ends_on', '>=', $today)->where('ends_on', '<', today()->addDays((int) $value + 1)->toDateString()), 'subscriptions'),
            'absent' => DB::table('attendances')->join('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
                ->where('class_sessions.organization_id', $organizationId)->where('attendances.status', 'absent')
                ->where('class_sessions.session_date', '>=', today()->subDays(30)->toDateString())
                ->groupBy('attendances.customer_id')->havingRaw('COUNT(*) >= ?', [max(1, (int) $value)])
                ->select('attendances.customer_id'),
            default => DB::table('class_enrollments')->join('group_classes', 'group_classes.id', '=', 'class_enrollments.group_class_id')
                ->where('group_classes.organization_id', $organizationId)->where('class_enrollments.group_class_id', (int) $value)
                ->whereNull('class_enrollments.ended_on')->select('class_enrollments.customer_id'),
        };
    }

    /** Libellé d'un critère de module (« Tranche en retard », « Absent 3 fois en 30 j »…). */
    public static function followLabel(string $follow): string
    {
        [$kind, $value] = array_pad(explode(':', $follow, 2), 2, null);

        return match ($kind) {
            'unpaid' => 'Tranche en retard',
            'ending' => "Abonnement finissant dans {$value} j",
            'absent' => "Absent {$value} fois en 30 j",
            default => 'Inscrits : '.(GroupClass::whereKey((int) $value)->value('name') ?? 'cours'),
        };
    }

    /**
     * Critères d'analyse (cf. $analysis), sur les ventes valides des points
     * de vente retenus.
     *
     * @param  Builder<Customer>  $query
     */
    private function applyAnalysis(Builder $query): void
    {
        $a = $this->analysis;
        $sales = fn () => DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('purchases.store_id', $this->storeIds ?: [0])
            ->where('purchases.status', Purchase::STATUS_VALID)
            ->where('purchases.type', '!=', Purchase::TYPE_RETURN);

        if (isset($a['bought_from']) || isset($a['bought_to'])) {
            $query->whereIn('customers.id', $sales()
                ->when(isset($a['bought_from']), fn ($q) => $q->whereRaw('substr(purchases.purchased_at, 1, 10) >= ?', [$a['bought_from']]))
                ->when(isset($a['bought_to']), fn ($q) => $q->whereRaw('substr(purchases.purchased_at, 1, 10) <= ?', [$a['bought_to']]))
                ->select('contacts.customer_id'));
        }

        if (isset($a['first_from']) || isset($a['first_to']) || isset($a['first_before'])) {
            $query->whereIn('customers.id', $sales()
                ->groupBy('contacts.customer_id')
                ->when(isset($a['first_from']), fn ($q) => $q->havingRaw('MIN(substr(purchases.purchased_at, 1, 10)) >= ?', [$a['first_from']]))
                ->when(isset($a['first_to']), fn ($q) => $q->havingRaw('MIN(substr(purchases.purchased_at, 1, 10)) <= ?', [$a['first_to']]))
                ->when(isset($a['first_before']), fn ($q) => $q->havingRaw('MIN(substr(purchases.purchased_at, 1, 10)) < ?', [$a['first_before']]))
                ->select('contacts.customer_id'));
        }

        if (isset($a['with_brand'])) {
            $query->whereExists(fn ($m) => $m->from('customer_brand_metrics')
                ->whereColumn('customer_brand_metrics.customer_id', 'customers.id')
                ->where('customer_brand_metrics.brand_id', $a['with_brand'])
                ->where('customer_brand_metrics.order_count', '>', 0));
        }

        if (isset($a['customer_type'])) {
            $query->where('customers.type', $a['customer_type']);
        }
    }

    /**
     * Critères de famille, calculés sur les achats des boutiques retenues.
     *
     * @param  Builder<Customer>  $query
     */
    private function applyFamilies(Builder $query): void
    {
        $since = $this->familyDays > 0 ? today()->subDays($this->familyDays) : null;
        // Une catégorie (famille qui en regroupe d'autres) cible les acheteurs de toutes ses familles.
        $analytics = new FamilyAnalytics($this->storeIds, $this->family !== null && $this->family->children()->exists() ? 'category' : 'family');

        if ($this->article !== null) {
            $articles = new FamilyAnalytics($this->storeIds, 'product');
            $query->whereIn('customers.id', $articles->buyers($this->article->id, $this->familyMode === 'bought' ? $since : null));
        }

        if ($this->family !== null) {
            if ($this->familyMode === 'lapsed') {
                $query->whereIn('customers.id', $analytics->buyers($this->family->id))
                    ->whereNotIn('customers.id', $analytics->buyers($this->family->id, $since));
            } else {
                $query->whereIn('customers.id', $analytics->buyers($this->family->id, $since));
            }
        }

        if ($this->familyNot !== null) {
            $query->whereNotIn('customers.id', $analytics->buyers($this->familyNot->id));
        }

        if ($this->property !== null) {
            $query->whereIn('customers.id', $analytics->propertyBuyers($this->property['key'], $this->property['value']));
        }
    }

    /**
     * Toutes les fiches du filtre, étape comprise.
     *
     * @return Builder<Customer>
     */
    public function query(): Builder
    {
        return $this->baseQuery()->when($this->stage !== null, fn ($q) => $q->where('lifecycle_stage', $this->stage));
    }

    /**
     * Contacts qui recevraient une campagne pour ces fiches : contacts
     * correspondant au filtre (boutique, VIP…) ET opt-in. Le consentement est
     * une exclusion non négociable, quel que soit le filtre choisi.
     *
     * @param  array<int, int>|null  $customerIds  null = toutes les fiches du filtre
     * @return Builder<Contact>
     */
    public function recipients(?array $customerIds): Builder
    {
        $matched = fn () => $customerIds === null ? $this->query() : $this->query()->whereKey($customerIds);

        // Une personne suivie (enfant…) est jointe par son responsable.
        return Contact::query()
            ->where($this->contactScope())
            ->where('contacts.consent_status', 'opt_in')
            ->where(fn ($q) => $q->whereIn('contacts.customer_id', $matched()->select('customers.id'))
                ->orWhereIn('contacts.customer_id', $matched()->whereNotNull('customers.responsible_id')->select('customers.responsible_id')));
    }

    /**
     * Libellé court du filtre, pour nommer une campagne (« VIP · Mango »).
     */
    public function label(): string
    {
        $parts = array_filter([
            $this->group?->name,
            $this->stage !== null ? Customer::STAGES[$this->stage] : null,
            $this->vip ? 'VIP' : null,
            $this->segment !== null ? RfmCalculator::SEGMENTS[$this->segment]['label'] : null,
            $this->risk !== null ? 'Risque de churn' : null,
            $this->analysis !== [] ? $this->analysisLabel() : null,
            match ($this->brands) {
                'multi' => 'Multimarques',
                'mono' => 'Mono-marque',
                default => null,
            },
            $this->neverBrand !== null ? 'Jamais venus chez '.Brand::whereKey($this->neverBrand)->value('name') : null,
            $this->familyLabel(),
            $this->follow !== null ? self::followLabel($this->follow) : null,
            match ($this->consent) {
                'opt_in' => 'Opt-in',
                'unknown' => 'Consentement inconnu',
                'opt_out' => 'Opt-out',
                default => null,
            },
            $this->storeId !== null
                ? $this->roleStores->firstWhere('id', $this->storeId)?->name
                : ($this->brandFilter['selected'] !== null && ! $this->brandFilter['locked']
                    ? $this->roleStores->firstWhere('brand_id', $this->brandFilter['selected'])?->brand?->name
                    : null),
        ]);

        return $parts === [] ? 'Clients' : implode(' · ', $parts);
    }

    /** « Achat du 01/09 au 30/09 », « Nouveaux du 01/09 au 30/09 », « Aussi Mango »… */
    private function analysisLabel(): string
    {
        $a = $this->analysis;
        $day = fn (string $d) => substr($d, 8, 2).'/'.substr($d, 5, 2).'/'.substr($d, 0, 4);
        $parts = [];

        if (isset($a['first_from']) || isset($a['first_to'])) {
            $parts[] = 'Nouveaux'.(isset($a['first_from']) ? ' du '.$day($a['first_from']) : '').(isset($a['first_to']) ? ' au '.$day($a['first_to']) : '');
        }
        if (isset($a['bought_from']) || isset($a['bought_to'])) {
            $parts[] = 'Achat'.(isset($a['bought_from']) ? ' du '.$day($a['bought_from']) : '').(isset($a['bought_to']) ? ' au '.$day($a['bought_to']) : '');
        }
        if (isset($a['first_before'])) {
            $parts[] = 'Clients avant le '.$day($a['first_before']);
        }
        if (isset($a['with_brand'])) {
            $parts[] = 'Aussi '.Brand::whereKey($a['with_brand'])->value('name');
        }
        if (isset($a['customer_type'])) {
            $parts[] = Customer::TYPES[$a['customer_type']];
        }

        return implode(' · ', $parts);
    }

    /** « Boissons depuis 90 j », « Plus de Boissons depuis 90 j », « Jamais Épicerie ». */
    private function familyLabel(): ?string
    {
        $parts = [];

        if ($this->family !== null) {
            $parts[] = match (true) {
                $this->familyMode === 'lapsed' => "Plus de {$this->family->name} depuis {$this->familyDays} j",
                $this->familyDays > 0 => "{$this->family->name} depuis {$this->familyDays} j",
                default => "A acheté {$this->family->name}",
            };
        }

        if ($this->familyNot !== null) {
            $parts[] = "Jamais {$this->familyNot->name}";
        }

        if ($this->article !== null) {
            $parts[] = "A acheté {$this->article->label}";
        }

        if ($this->property !== null) {
            $parts[] = FamilyAnalytics::propertyLabel($this->property['key']).' : '.$this->property['value'];
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
