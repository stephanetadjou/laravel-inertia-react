<?php

namespace App\Services;

/**
 * Normalise un numéro de mobile gabonais au format E.164 sans "+" :
 * 241 suivi de 8 chiffres, le premier étant 6 (Libertis/Moov) ou 7 (Airtel),
 * ex. 24162605054 (11 caractères).
 *
 * Variantes acceptées en entrée : espaces/points/tirets, "+241", "00241",
 * numéro local à 8 chiffres ("62 60 50 54"), 0 initial ("062605054",
 * "+241 062605054"), et valeur numérique Excel ("62605054.0").
 *
 * Tout le reste est rejeté (null), notamment un numéro local à 9 chiffres
 * sans 0 initial (ex. 612345678) : on ne devine pas quel chiffre est en
 * trop, pour ne jamais rattacher un client (et ses campagnes) au mauvais
 * numéro.
 */
class PhoneNormalizer
{
    private const COUNTRY_CODE = '241';

    public const EXPECTED_FORMAT = '8 chiffres commençant par 6 ou 7, ex. 62 60 50 54 ou +241 62 60 50 54';

    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);

        // Cellule Excel numérique rendue en flottant ("62605054.0").
        $raw = preg_replace('/^(\d+)\.0+$/', '$1', $raw);

        $digits = preg_replace('/[^0-9]/', '', $raw);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $national = str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) > 8
            ? substr($digits, strlen(self::COUNTRY_CODE))
            : $digits;

        if (strlen($national) === 9 && $national[0] === '0') {
            $national = substr($national, 1);
        }

        if (! preg_match('/^[67]\d{7}$/', $national)) {
            return null;
        }

        return self::COUNTRY_CODE.$national;
    }
}
