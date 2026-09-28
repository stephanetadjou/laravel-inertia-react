<?php

namespace App\Services;

/**
 * Normalise un numéro de téléphone saisi/importé au format E.164 sans "+"
 * (indicatif Gabon 241 par défaut pour un numéro local à 8 chiffres).
 */
class PhoneNormalizer
{
    private const DEFAULT_COUNTRY_CODE = '241';

    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);

        if (strlen($digits) === 8) {
            $digits = self::DEFAULT_COUNTRY_CODE.$digits;
        }

        return $digits;
    }
}
