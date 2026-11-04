<?php

namespace App\Services;

use App\Jobs\RefreshBrandMetricsJob;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerMatchReview;
use App\Models\CustomerPhone;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Identité des fiches clients : rapprochements douteux à vérifier, fusion de
 * deux fiches et séparation d'une fiche fusionnée. Toute décision est
 * journalisée (audit_logs) ; rien n'est fusionné automatiquement.
 */
class CustomerIdentity
{
    /**
     * Une vente au nom de $incomingName peut-elle être rattachée d'office à
     * la fiche ? Non si le numéro est marqué partagé, ou si les deux noms
     * n'ont rien en commun (aucun mot, ressemblance faible). Un nom absent ou
     * par défaut (« Client 241… ») ne bloque jamais.
     *
     * @return string|null motif de vérification (cf. CustomerMatchReview::REASONS), null = rattachement direct
     */
    public function reviewReason(Customer $customer, ?string $incomingName): ?string
    {
        if (CustomerLifecycle::isPlaceholderName($incomingName) || blank($incomingName)) {
            return null;
        }

        if ($customer->hasSharedPhone()) {
            return 'shared_phone';
        }

        $existing = $customer->isBusiness() ? ($customer->company_name ?: $customer->name) : $customer->name;

        if (CustomerLifecycle::isPlaceholderName($existing)) {
            return null;
        }

        return self::namesMatch((string) $existing, (string) $incomingName) ? null : 'name_mismatch';
    }

    /** « Awa Ndong » et « AWA N. » se ressemblent ; « Jean Moussavou » et « Awa Ndong », non. */
    public static function namesMatch(string $a, string $b): bool
    {
        $wordsA = self::words($a);
        $wordsB = self::words($b);

        if ($wordsA === [] || $wordsB === []) {
            return true;
        }

        if (array_intersect($wordsA, $wordsB) !== []) {
            return true;
        }

        similar_text(implode(' ', $wordsA), implode(' ', $wordsB), $percent);

        return $percent >= 70;
    }

    /** @return list<string> mots de 3 lettres et plus, sans accents ni casse */
    private static function words(string $name): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn ($w) => strlen($w) >= 3)));
    }

    /**
     * Ajoute des ventes à la file « À vérifier » (une entrée par fiche, nom
     * et point de vente encore en attente).
     *
     * @param  list<int>  $purchaseIds
     */
    public function queue(Customer $customer, int $storeId, ?string $incomingName, string $reason, array $purchaseIds, ?int $batchId = null): CustomerMatchReview
    {
        $review = CustomerMatchReview::firstOrCreate(
            [
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'store_id' => $storeId,
                'incoming_name' => $incomingName,
                'status' => CustomerMatchReview::STATUS_PENDING,
            ],
            [
                'phone_e164' => $customer->phone_e164,
                'existing_name' => $customer->displayName(),
                'reason' => $reason,
                'import_batch_id' => $batchId,
                'purchase_ids' => [],
            ],
        );

        $review->update(['purchase_ids' => array_values(array_unique([...($review->purchase_ids ?? []), ...$purchaseIds]))]);

        return $review;
    }

    /**
     * Décision sur un rapprochement : « attach » rattache les ventes à la
     * fiche (contact du point de vente créé au besoin), « separate » les
     * laisse non rattachées (personne différente sur un numéro partagé : le
     * numéro est marqué partagé pour la suite).
     */
    public function resolve(CustomerMatchReview $review, string $decision, User $user): void
    {
        DB::transaction(function () use ($review, $decision, $user) {
            $customer = $review->customer;

            if ($decision === CustomerMatchReview::STATUS_ATTACHED) {
                $contact = Contact::where('store_id', $review->store_id)->where('customer_id', $customer->id)->first()
                    ?? Contact::where('store_id', $review->store_id)->where('phone_e164', $customer->phone_e164)->first()
                    ?? Contact::create(['store_id' => $review->store_id, 'phone_e164' => $customer->phone_e164, 'name' => $customer->name, 'customer_id' => $customer->id]);

                CustomerLifecycle::batch(function () use ($review, $contact) {
                    Purchase::withCancelled()->whereKey($review->purchase_ids ?? [])->whereNull('contact_id')->get()
                        ->each(fn (Purchase $purchase) => $purchase->update(['contact_id' => $contact->id, 'buyer_name' => null]));
                });
            } else {
                CustomerPhone::where('customer_id', $customer->id)->where('phone_e164', $customer->phone_e164)->update(['is_shared' => true]);
            }

            $review->update(['status' => $decision, 'resolved_by' => $user->id, 'resolved_at' => now()]);

            Audit::record('customer.review', $customer, ($decision === CustomerMatchReview::STATUS_ATTACHED ? 'Ventes rattachées' : 'Ventes gardées séparées')
                ." : « {$review->incoming_name} » / fiche « {$review->existing_name} »", ['review_id' => $review->id, 'decision' => $decision, 'purchases' => $review->purchase_ids], $user);
        });
    }

    /**
     * Fusionne $source dans $target : contacts (et donc ventes, groupes,
     * consentements), rendez-vous, relances, fidélité, historique d'étapes
     * et numéros passent sur la fiche gardée ; la fiche fusionnée disparaît.
     * La trace (contacts déplacés, numéro, nom) permet de les séparer à
     * nouveau (split()).
     */
    public function merge(Customer $source, Customer $target, User $user): Customer
    {
        if ($source->is($target) || $source->organization_id !== $target->organization_id) {
            throw ValidationException::withMessages(['target_id' => 'Choisissez une autre fiche de la même entreprise.']);
        }

        DB::transaction(function () use ($source, $target, $user) {
            $contactIds = Contact::where('customer_id', $source->id)->pluck('id')->all();
            $snapshot = ['source_id' => $source->id, 'name' => $source->name, 'phone' => $source->phone_e164, 'contacts' => $contactIds, 'type' => $source->type, 'company_name' => $source->company_name, 'external_code' => $source->external_code];

            // Écritures directes : pas d'événements Contact (pas de changement de numéro en cascade).
            Contact::whereKey($contactIds)->update(['customer_id' => $target->id]);

            foreach (['appointments', 'customer_follow_ups', 'loyalty_transactions', 'loyalty_redemptions', 'customer_stage_changes'] as $table) {
                DB::table($table)->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            }

            foreach (CustomerConsent::where('customer_id', $source->id)->get() as $consent) {
                $exists = CustomerConsent::where('customer_id', $target->id)->where('store_id', $consent->store_id)
                    ->where('channel', $consent->channel)->where('purpose', $consent->purpose)->exists();
                $exists ? $consent->delete() : $consent->update(['customer_id' => $target->id]);
            }

            foreach (CustomerPhone::where('customer_id', $source->id)->get() as $phone) {
                CustomerPhone::where('customer_id', $target->id)->where('phone_e164', $phone->phone_e164)->exists()
                    ? $phone->delete()
                    : $phone->update(['customer_id' => $target->id, 'is_primary' => false]);
            }

            DB::table('customer_match_reviews')->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            $target->update([
                'previous_phones' => collect($target->previous_phones ?? [])->push($source->phone_e164)->reject(fn ($p) => $p === $target->phone_e164)->unique()->values()->all(),
                'external_code' => $target->external_code ?: $source->external_code,
            ]);

            Customer::whereKey($source->id)->delete();
            app(CustomerLifecycle::class)->refresh([$target->id]);

            Audit::record('customer.merged', $target, "Fiche « {$snapshot['name']} » ({$snapshot['phone']}) fusionnée dans « {$target->name} »", $snapshot, $user);
        });

        app(CustomerScoring::class)->refreshOrganization($target->organization);
        RefreshBrandMetricsJob::dispatch($target->organization_id);
        LoyaltyEngine::queue($target->organization_id, [$target->id]);

        return $target->fresh();
    }

    /**
     * Sépare d'une fiche les contacts d'un autre numéro (une fusion à
     * défaire) : ils forment une nouvelle fiche à ce numéro, avec leurs
     * ventes.
     *
     * @param  list<int>  $contactIds
     */
    public function split(Customer $customer, array $contactIds, User $user): Customer
    {
        $contacts = Contact::where('customer_id', $customer->id)->whereKey($contactIds)->get();
        $phones = $contacts->pluck('phone_e164')->unique();

        if ($contacts->isEmpty() || $phones->count() !== 1 || $phones->first() === $customer->phone_e164) {
            throw ValidationException::withMessages(['contact_ids' => 'Choisissez les contacts d’un même numéro, différent du numéro principal de la fiche.']);
        }

        if (Contact::where('customer_id', $customer->id)->whereKeyNot($contacts->pluck('id'))->doesntExist()) {
            throw ValidationException::withMessages(['contact_ids' => 'La fiche doit garder au moins un contact.']);
        }

        $phone = $phones->first();

        $new = DB::transaction(function () use ($customer, $contacts, $phone, $user) {
            $new = Customer::create([
                'organization_id' => $customer->organization_id,
                'phone_e164' => $phone,
                'name' => $contacts->sortByDesc('updated_at')->first()->name,
            ]);

            Contact::whereKey($contacts->pluck('id'))->update(['customer_id' => $new->id]);
            CustomerConsent::where('customer_id', $customer->id)->whereIn('store_id', $contacts->pluck('store_id'))->update(['customer_id' => $new->id]);
            CustomerPhone::where('customer_id', $customer->id)->where('phone_e164', $phone)->delete();

            $customer->update(['previous_phones' => collect($customer->previous_phones ?? [])->reject(fn ($p) => $p === $phone)->values()->all()]);

            app(CustomerLifecycle::class)->refresh([$customer->id, $new->id]);

            Audit::record('customer.split', $customer, "Fiche « {$new->name} » ({$phone}) séparée de « {$customer->name} »", ['new_customer_id' => $new->id, 'contacts' => $contacts->pluck('id')->all()], $user);

            return $new;
        });

        app(CustomerScoring::class)->refreshOrganization($customer->organization);
        RefreshBrandMetricsJob::dispatch($customer->organization_id);
        LoyaltyEngine::queue($customer->organization_id, [$customer->id, $new->id]);

        return $new;
    }
}
