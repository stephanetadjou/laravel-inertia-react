<?php

namespace App\Services;

use App\Models\Customer;

/**
 * Source d'acquisition d'une fiche, lue sur le premier message WhatsApp :
 * un clic sur une pub « Click to WhatsApp » ajoute au message un objet
 * `referral` (source_type ad|post, source_id, source_url, headline,
 * ctwa_clid…). L'URL de la pub dit si elle tournait sur Instagram ou
 * Facebook ; sans referral, la source est « Direct ».
 */
class Acquisition
{
    /** @param  array<string, mixed>|null  $referral */
    public static function sourceOf(?array $referral): string
    {
        if (! $referral) {
            return 'direct';
        }

        $hint = mb_strtolower(implode(' ', array_filter([
            $referral['source_url'] ?? null,
            $referral['source_app'] ?? null,
            $referral['entry_point'] ?? null,
        ], 'is_string')));

        return str_contains($hint, 'instagram') || str_contains($hint, 'instagr.am') ? 'instagram_ad' : 'facebook_ad';
    }

    /**
     * Enregistre la source sur la fiche, une seule fois : le premier contact
     * garde la pub qui a fait venir le client.
     *
     * @param  array<string, mixed>|null  $referral
     */
    public static function record(Customer $customer, ?array $referral): void
    {
        if ($customer->acquisition_source !== null) {
            return;
        }

        $customer->forceFill([
            'acquisition_source' => self::sourceOf($referral),
            'acquisition_data' => $referral ? array_intersect_key($referral, array_flip([
                'source_type', 'source_id', 'source_url', 'headline', 'body', 'media_type', 'ctwa_clid', 'source_app', 'entry_point',
            ])) : null,
            'acquired_at' => now(),
        ])->saveQuietly();
    }
}
