<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerStageChange;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fiche client unique et parcours client.
 *
 * - Rattache chaque contact (relation d'une boutique) à la fiche de la
 *   personne, par organisation et numéro E.164.
 * - Tient à jour les compteurs d'achats de la fiche et son étape du Groupe :
 *   Contact → Prospect → Nouveau client → Client actif → À risque → Inactif.
 * - Tient à jour le statut VIP de chaque contact, boutique par boutique
 *   (top X % des acheteurs de la boutique sur la période VIP).
 * - Trace chaque changement d'étape (customer_stage_changes).
 *
 * Appelé automatiquement par les événements des modèles Contact et Purchase ;
 * les imports regroupent les recalculs avec batch(), et la commande
 * crm:refresh-lifecycle recalcule tout chaque nuit (les passages « à risque »
 * et « inactif » arrivent avec le temps, sans événement).
 */
class CustomerLifecycle
{
    /** @var array<int, true>|null Fiches à recalculer en fin de batch() ; null hors batch. */
    private static ?array $deferred = null;

    /**
     * Exécute $callback en regroupant les recalculs : chaque fiche touchée
     * n'est recalculée qu'une fois, à la fin (imports de fichiers).
     */
    public static function batch(callable $callback): mixed
    {
        if (self::$deferred !== null) {
            return $callback();
        }

        self::$deferred = [];

        try {
            $result = $callback();
        } finally {
            $ids = array_keys(self::$deferred);
            self::$deferred = null;
        }

        app(self::class)->refresh($ids);

        return $result;
    }

    /** Nom généré par l'import d'achats quand le fichier n'en fournit pas. */
    public static function isPlaceholderName(?string $name): bool
    {
        return $name === null || preg_match('/^Client \+?\d+$/u', trim($name)) === 1;
    }

    /**
     * Demande le recalcul d'une fiche (immédiat, ou en fin de batch()).
     */
    public function touch(?int $customerId): void
    {
        if ($customerId === null) {
            return;
        }

        if (self::$deferred !== null) {
            self::$deferred[$customerId] = true;

            return;
        }

        $this->refresh([$customerId]);
    }

    /**
     * Rattache un nouveau contact à la fiche de la personne (créée au besoin).
     * Conflit d'identité : le nom du contact le plus récent l'emporte, sauf si
     * l'identité a été confirmée à la main sur la fiche.
     */
    public function linkContact(Contact $contact): void
    {
        $organizationId = Store::whereKey($contact->store_id)->value('organization_id');

        $customer = Customer::firstOrCreate(
            ['organization_id' => $organizationId, 'phone_e164' => $contact->phone_e164],
            ['name' => $contact->name, 'gender' => $contact->gender, 'birthdate' => $contact->birthdate],
        );

        if (! $customer->wasRecentlyCreated) {
            $this->syncIdentity($customer, $contact);
        }

        $contact->customer_id = $customer->id;
    }

    /**
     * Reporte sur la fiche le nom (et le sexe / la date de naissance s'ils
     * sont renseignés) d'un contact créé ou modifié, sauf identité confirmée.
     */
    public function syncIdentity(Customer $customer, Contact $contact): void
    {
        if ($customer->identity_confirmed_at !== null) {
            return;
        }

        $customer->fill(array_filter([
            // Un nom par défaut (« Client 241… », import d'achats sans nom)
            // ne remplace jamais un vrai nom.
            'name' => self::isPlaceholderName($contact->name) && ! self::isPlaceholderName($customer->name) ? null : $contact->name,
            'gender' => $contact->gender,
            'birthdate' => $contact->birthdate,
        ], fn ($value) => $value !== null && $value !== ''));

        $customer->save();
    }

    /**
     * Le numéro d'un contact change (appelé avant l'enregistrement) :
     * - le nouveau numéro appartient déjà à une autre fiche : c'est la même
     *   personne, le contact rejoint cette fiche (l'ancien numéro y est
     *   conservé, plus utilisé) ;
     * - sinon, c'est la personne qui change de numéro : sa fiche et tous ses
     *   contacts passent au nouveau numéro (cf. changePhone()).
     */
    public function contactPhoneChanging(Contact $contact): void
    {
        $customer = $contact->customer;
        $newPhone = $contact->phone_e164;
        $oldPhone = (string) $contact->getOriginal('phone_e164');

        $owner = Customer::where('organization_id', $customer->organization_id)
            ->where('phone_e164', $newPhone)
            ->whereKeyNot($customer->id)
            ->first();

        if ($owner === null) {
            $this->changePhone($customer, $newPhone);

            return;
        }

        $owner->update([
            'previous_phones' => collect($owner->previous_phones ?? [])->push($oldPhone)
                ->reject(fn ($phone) => $phone === $newPhone || $phone === '')
                ->unique()->values()->all(),
        ]);
        $contact->customer_id = $owner->id;
    }

    /**
     * Après un changement de fiche d'un contact : l'ancienne fiche disparaît
     * si elle n'a plus de contact, sinon les deux sont recalculées.
     */
    public function contactMoved(?int $fromCustomerId, ?int $toCustomerId): void
    {
        if ($fromCustomerId !== null) {
            if (Contact::where('customer_id', $fromCustomerId)->exists()) {
                $this->touch($fromCustomerId);
            } else {
                Customer::whereKey($fromCustomerId)->delete();
            }
        }

        $this->touch($toCustomerId);
    }

    /**
     * Changement de numéro d'une personne : la fiche et tous ses contacts
     * passent au nouveau numéro ; l'ancien est conservé dans previous_phones
     * (plus utilisé pour les envois).
     *
     * @throws ValidationException si le numéro appartient déjà à une autre fiche
     */
    public function changePhone(Customer $customer, string $newPhone, string $errorKey = 'phone_e164'): void
    {
        if ($newPhone === $customer->phone_e164) {
            return;
        }

        $owner = Customer::where('organization_id', $customer->organization_id)
            ->where('phone_e164', $newPhone)
            ->whereKeyNot($customer->id)
            ->first();

        if ($owner) {
            throw ValidationException::withMessages([
                $errorKey => "Ce numéro appartient déjà à la fiche client « {$owner->name} ».",
            ]);
        }

        DB::transaction(function () use ($customer, $newPhone) {
            $previous = collect($customer->previous_phones ?? [])
                ->push($customer->phone_e164)
                ->reject(fn ($phone) => $phone === $newPhone)
                ->unique()
                ->values()
                ->all();

            $customer->update(['phone_e164' => $newPhone, 'previous_phones' => $previous]);

            // Écriture directe : pas d'événements Contact (évite de relancer
            // le changement de numéro pour chaque contact de la personne).
            Contact::where('customer_id', $customer->id)->update(['phone_e164' => $newPhone]);
        });
    }

    /**
     * Recalcule les compteurs et l'étape de fiches clients.
     *
     * @param  array<int, int>  $customerIds
     */
    public function refresh(array $customerIds): void
    {
        $customerIds = array_values(array_unique(array_filter($customerIds)));

        if ($customerIds === []) {
            return;
        }

        foreach (array_chunk($customerIds, 500) as $chunk) {
            $customers = Customer::with('organization')->whereKey($chunk)->get();

            foreach ($customers->groupBy('organization_id') as $orgCustomers) {
                $this->refreshGroup($orgCustomers->first()->organization, $orgCustomers->all());
            }
        }
    }

    /**
     * Recalcule toutes les fiches d'une organisation. Renvoie le nombre de
     * changements d'étape.
     */
    public function refreshOrganization(Organization $organization): int
    {
        $changes = 0;

        Customer::where('organization_id', $organization->id)
            ->chunkById(500, function ($customers) use ($organization, &$changes) {
                $changes += $this->refreshGroup($organization, $customers->all());
            });

        return $changes;
    }

    /**
     * @param  array<int, Customer>  $customers  fiches d'une même organisation
     */
    private function refreshGroup(Organization $organization, array $customers): int
    {
        $settings = $organization->lifecycleSettings();
        $today = now()->startOfDay();
        $vipSince = $today->copy()->subDays($settings['vip_period_days']);
        $ids = array_map(fn (Customer $c) => $c->id, $customers);

        $stats = DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('contacts.customer_id', $ids)
            ->groupBy('contacts.customer_id')
            ->selectRaw('contacts.customer_id as customer_id, min(purchases.purchased_at) as first_at, max(purchases.purchased_at) as last_at, count(*) as purchases, sum(purchases.amount) as spent')
            ->get()
            ->keyBy('customer_id');

        $optedIn = DB::table('contacts')
            ->whereIn('customer_id', $ids)
            ->where('consent_status', 'opt_in')
            ->distinct()
            ->pluck('customer_id')
            ->flip();

        $this->refreshVip($ids, $settings, $vipSince);

        $changes = 0;

        foreach ($customers as $customer) {
            $row = $stats->get($customer->id);

            $customer->fill([
                'first_purchase_at' => $row ? Carbon::parse($row->first_at)->toDateString() : null,
                'last_purchase_at' => $row ? Carbon::parse($row->last_at)->toDateString() : null,
                'purchases_count' => (int) ($row->purchases ?? 0),
                'total_spent' => (int) ($row->spent ?? 0),
            ]);

            $stage = $this->stageFor($customer, $optedIn->has($customer->id), $settings, $today);

            if ($stage !== $customer->lifecycle_stage || $customer->lifecycle_changed_at === null) {
                CustomerStageChange::create([
                    'customer_id' => $customer->id,
                    'from_stage' => $customer->lifecycle_changed_at === null ? null : $customer->lifecycle_stage,
                    'to_stage' => $stage,
                    'changed_at' => now(),
                ]);
                $customer->lifecycle_stage = $stage;
                $customer->lifecycle_changed_at = now();
                $changes++;
            }

            $customer->save();
        }

        return $changes;
    }

    /**
     * @param  array{new_customer_days: int, at_risk_days: int, churn_days: int, vip_top_percent: int, vip_period_days: int}  $settings
     */
    private function stageFor(Customer $customer, bool $optedIn, array $settings, Carbon $today): string
    {
        if ($customer->purchases_count === 0) {
            return $optedIn ? Customer::STAGE_PROSPECT : Customer::STAGE_CONTACT;
        }

        $daysSinceLast = (int) $customer->last_purchase_at->copy()->startOfDay()->diffInDays($today);

        if ($daysSinceLast > $settings['churn_days']) {
            return Customer::STAGE_CHURNED;
        }

        if ($daysSinceLast > $settings['at_risk_days']) {
            return Customer::STAGE_AT_RISK;
        }

        $daysSinceFirst = (int) $customer->first_purchase_at->copy()->startOfDay()->diffInDays($today);

        return $daysSinceFirst <= $settings['new_customer_days'] ? Customer::STAGE_NEW : Customer::STAGE_ACTIVE;
    }

    /**
     * Statut VIP par boutique : un contact est VIP dans sa boutique s'il fait
     * partie du top X % des acheteurs de cette boutique (CA dans la boutique
     * sur la période VIP). Un même client peut donc être VIP chez Mango et
     * pas chez Adidas.
     *
     * Recalculé pour toutes les boutiques où ces fiches ont un contact : un
     * nouvel achat peut faire monter le seuil et retirer le statut à d'autres
     * clients de la même boutique. Seuls les contacts qui changent sont écrits.
     *
     * @param  array<int, int>  $customerIds
     * @param  array{vip_top_percent: int}  $settings
     */
    private function refreshVip(array $customerIds, array $settings, Carbon $since): void
    {
        $storeIds = DB::table('contacts')->whereIn('customer_id', $customerIds)->distinct()->pluck('store_id');

        if ($storeIds->isEmpty()) {
            return;
        }

        $thresholds = $this->vipThresholds($storeIds->all(), $settings, $since);

        // Candidats : acheteurs de la période + VIP actuels (qui peuvent perdre le statut).
        $contacts = DB::table('contacts')
            ->whereIn('store_id', $storeIds)
            ->where(fn ($q) => $q->where('is_vip', true)
                ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('purchases')
                    ->whereColumn('purchases.contact_id', 'contacts.id')
                    ->where('purchases.purchased_at', '>=', $since)))
            ->get(['id', 'store_id', 'is_vip']);

        $spends = DB::table('purchases')
            ->whereIn('contact_id', $contacts->pluck('id'))
            ->where('purchased_at', '>=', $since)
            ->groupBy('contact_id')
            ->selectRaw('contact_id, sum(amount) as spent')
            ->pluck('spent', 'contact_id');

        foreach ($contacts as $contact) {
            $spent = (int) ($spends[$contact->id] ?? 0);
            $threshold = $thresholds[$contact->store_id] ?? null;
            $isVip = $threshold !== null && $spent > 0 && $spent >= $threshold;

            if ($isVip !== (bool) $contact->is_vip) {
                // Écriture directe : un changement de statut VIP ne doit pas
                // relancer les événements du contact (et donc ce recalcul).
                DB::table('contacts')->where('id', $contact->id)->update([
                    'is_vip' => $isVip,
                    'vip_since' => $isVip ? now() : null,
                ]);
            }
        }
    }

    /**
     * CA minimum (sur la période VIP) pour faire partie du top X % des
     * acheteurs de chaque boutique. Boutique absente = pas de VIP (aucun
     * achat sur la période, ou VIP désactivé avec 0 %).
     *
     * @param  array<int, int>  $storeIds
     * @param  array{vip_top_percent: int}  $settings
     * @return array<int, int> seuil par boutique
     */
    private function vipThresholds(array $storeIds, array $settings, Carbon $since): array
    {
        if ($settings['vip_top_percent'] <= 0 || $storeIds === []) {
            return [];
        }

        return DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('contacts.store_id', $storeIds)
            ->where('purchases.purchased_at', '>=', $since)
            ->groupBy('contacts.store_id', 'contacts.id')
            ->selectRaw('contacts.store_id as store_id, sum(purchases.amount) as spent')
            ->get()
            ->groupBy('store_id')
            ->map(function ($rows) use ($settings) {
                $spends = $rows->pluck('spent')->map(fn ($s) => (int) $s)->sortDesc()->values();
                $rank = max(1, (int) ceil($spends->count() * $settings['vip_top_percent'] / 100));

                return $spends[$rank - 1];
            })
            ->all();
    }
}
