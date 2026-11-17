<?php

namespace App\Modules\CarteDigitale\Services;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Services\PhoneNormalizer;
use App\Services\ProspectPipeline;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/** Règles du module Carte digitale : quota par pack, mention, contact reçu. */
class DigitalCards
{
    /** Nombre de cartes permis par le pack en vigueur (null = illimité). */
    public static function limit(Organization $organization): ?int
    {
        $pack = ModuleRegistry::pack($organization->pack());

        return array_key_exists('cards', $pack) ? $pack['cards'] : 3;
    }

    public static function canCreate(Organization $organization): bool
    {
        $limit = self::limit($organization);

        return $limit === null || DigitalCard::where('organization_id', $organization->id)->count() < $limit;
    }

    /** Mention « Créé avec ClientlyX » (offres Gratuit et Solo). */
    public static function showsBranding(Organization $organization): bool
    {
        return (bool) (ModuleRegistry::pack($organization->pack())['card_branding'] ?? true);
    }

    /**
     * Offre suivante (pas d'option « cartes en plus » : on monte d'offre).
     *
     * @return array{label: string, cards: int|null}|null
     */
    public static function nextPack(Organization $organization): ?array
    {
        $order = ModuleRegistry::PACK_ORDER;
        $next = $order[ModuleRegistry::packRank($organization->pack()) + 1] ?? null;

        return $next === null ? null : ['label' => Organization::PLANS[$next]['label'] ?? $next, 'cards' => ModuleRegistry::pack($next)['cards'] ?? null];
    }

    /** Période des statistiques permise par le pack (7, 30, 90 ou 365 jours). */
    public static function statsDays(Organization $organization): int
    {
        return (int) (ModuleRegistry::pack($organization->pack())['card_stats_days'] ?? 7);
    }

    /** Impression 85 × 55 mm (à partir de l'offre Équipe). */
    public static function canPrint(Organization $organization): bool
    {
        return (bool) (ModuleRegistry::pack($organization->pack())['card_print'] ?? false);
    }

    /**
     * Pack minimum qui débloque une période de statistiques ou l'impression.
     */
    public static function packFor(string $key, int|bool $value): string
    {
        foreach (ModuleRegistry::PACK_ORDER as $pack) {
            $setting = ModuleRegistry::pack($pack)[$key] ?? null;
            if (is_int($value) ? (int) $setting >= $value : (bool) $setting) {
                return Organization::PLANS[$pack]['label'] ?? $pack;
            }
        }

        return 'Entreprise';
    }

    /**
     * Carte créée depuis le site vitrine (?offre=carte) : champs du créateur
     * gardés en session à l'inscription, puis repris ici.
     *
     * @param  array<string, string|null>  $draft
     */
    public static function createFromDraft(Organization $organization, ?int $userId, array $draft): DigitalCard
    {
        $name = trim((string) ($draft['nom'] ?? '')) ?: $organization->name;
        $whatsapp = filled($draft['whatsapp'] ?? null) ? PhoneNormalizer::normalize((string) $draft['whatsapp']) : null;
        $color = in_array($draft['couleur'] ?? null, DigitalCard::COLORS, true) ? $draft['couleur'] : DigitalCard::COLORS[0];

        return DigitalCard::create([
            'organization_id' => $organization->id,
            'store_id' => Store::where('organization_id', $organization->id)->orderBy('id')->value('id'),
            'user_id' => $userId,
            'slug' => DigitalCard::uniqueSlug($name),
            'name' => mb_substr($name, 0, 120),
            'job_title' => filled($draft['poste'] ?? null) ? mb_substr(trim($draft['poste']), 0, 120) : null,
            'company' => filled($draft['entreprise'] ?? null) ? mb_substr(trim($draft['entreprise']), 0, 120) : $organization->name,
            'phone_e164' => $whatsapp,
            'whatsapp_e164' => $whatsapp,
            'email' => filter_var($draft['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'color' => $color,
            // Réseaux saisis dans le créateur du site : seuls les liens valables sont gardés.
            'socials' => collect(array_keys(DigitalCard::SOCIALS))
                ->mapWithKeys(fn ($key) => [$key => DigitalCard::normalizeSocial($key, $draft[$key] ?? null)])
                ->filter(fn ($url) => is_string($url))->all() ?: null,
        ]);
    }

    /**
     * Contact laissé sur la carte : fiche client (source « carte », collaborateur
     * responsable si la fiche n'en a pas) et tâche à rappeler dans « À faire ».
     */
    public static function receiveContact(DigitalCard $card, string $name, string $phone, ?string $message, bool $consent): Contact
    {
        return DB::transaction(function () use ($card, $name, $phone, $message, $consent) {
            $storeId = $card->store_id ?? Store::where('organization_id', $card->organization_id)->orderBy('id')->value('id');

            $contact = Contact::where('store_id', $storeId)->where('phone_e164', $phone)->first()
                ?? Contact::create(['store_id' => $storeId, 'phone_e164' => $phone, 'name' => $name, 'consent_status' => 'unknown']);

            // Case cochée par le visiteur : accord daté et sourcé, jamais retiré ici.
            if ($consent && $contact->consent_status !== 'opt_in') {
                $contact->update(['consent_status' => 'opt_in', 'consent_at' => now(), 'consent_source' => 'carte_digitale']);
            }

            $customer = $contact->fresh()->customer;
            $isClient = $customer !== null && (int) $customer->purchases_count > 0;
            if ($customer !== null) {
                $customer->forceFill(array_filter([
                    'acquisition_source' => $customer->acquisition_source ?? 'carte',
                    'acquisition_card_id' => $customer->acquisition_card_id ?? $card->id,
                    'acquired_at' => $customer->acquired_at ?? now(),
                    'owner_user_id' => $customer->owner_user_id ?? $card->user_id,
                ], fn ($v) => $v !== null))->save();

                // Suivi des prospects : un nouveau venu entre à l'étape « à contacter », avec le
                // commercial de la carte comme responsable (objectifs, primes) ; la relance à date
                // suit le délai de l'étape. Un client existant ne redevient pas prospect.
                $pipeline = ProspectPipeline::for($card->organization);
                if (! $isClient && $customer->prospect_stage === null && $pipeline->enabled()) {
                    $pipeline->start($customer, $card->user);
                }
            }

            Task::open([
                'organization_id' => $card->organization_id,
                'store_id' => $storeId,
                'customer_id' => $customer?->id,
                'contact_id' => $contact->id,
                'assigned_user_id' => $card->user_id,
                'kind' => 'card_contact',
                'title' => $isClient ? "Client existant : nouveau contact par la carte de {$card->name}" : "Contact reçu par la carte de {$card->name}",
                'details' => filled($message) ? mb_substr(trim($message), 0, 500) : null,
            ]);

            $card->record('contact');

            return $contact;
        });
    }
}
