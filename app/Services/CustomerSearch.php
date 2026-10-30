<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesBrandFilter;
use App\Models\Article;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
        $this->segment = array_key_exists((string) ($filters['segment'] ?? ''), RfmCalculator::SEGMENTS) ? (string) $filters['segment'] : null;
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
        return new self($request->user(), $request->only(['stage', 'vip', 'consent', 'q', 'brand', 'store', 'group', 'segment', 'risk', 'family', 'family_mode', 'family_days', 'family_not', 'prop', 'article']));
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
            ->when($this->segment !== null, fn ($q) => $q->where('rfm_segment', $this->segment))
            ->when($this->risk === 'any', fn ($q) => $q->whereIn('churn_risk', ['medium', 'high']))
            ->when(in_array($this->risk, ['medium', 'high'], true), fn ($q) => $q->where('churn_risk', $this->risk))
            ->when($this->family !== null || $this->familyNot !== null || $this->property !== null || $this->article !== null, fn ($q) => $this->applyFamilies($q))
            ->when($this->search !== '', function ($q) {
                $digits = ltrim(preg_replace('/\D+/', '', $this->search), '0'); // 0 du format local ignoré
                $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->when($digits !== '', fn ($q) => $q->orWhere('phone_e164', 'like', "%{$digits}%")));
            });
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
        return Contact::query()
            ->where($this->contactScope())
            ->where('contacts.consent_status', 'opt_in')
            ->when(
                $customerIds === null,
                fn ($q) => $q->whereIn('contacts.customer_id', $this->query()->select('customers.id')),
                fn ($q) => $q->whereIn('contacts.customer_id', $this->query()->whereKey($customerIds)->select('customers.id')),
            );
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
            $this->familyLabel(),
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
