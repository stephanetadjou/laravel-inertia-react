<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesBrandFilter;
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

    /**
     * @param  array<string, mixed>  $filters  stage, vip, consent, q, brand, store, group, segment, risk
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
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->user(), $request->only(['stage', 'vip', 'consent', 'q', 'brand', 'store', 'group', 'segment', 'risk']));
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
            ->when($this->search !== '', function ($q) {
                $digits = ltrim(preg_replace('/\D+/', '', $this->search), '0'); // 0 du format local ignoré
                $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->when($digits !== '', fn ($q) => $q->orWhere('phone_e164', 'like', "%{$digits}%")));
            });
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
}
