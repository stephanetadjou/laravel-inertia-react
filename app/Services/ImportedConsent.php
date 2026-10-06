<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Lecture de la colonne « consentement » des imports (contacts, achats) :
 * l'accord WhatsApp recueilli en boutique, à la caisse.
 *
 * - oui / yes / o / y / 1 / x / accord / opt-in  -> opt_in
 * - non / no / n / 0 / refus / stop / opt-out    -> opt_out
 * - cellule vide                                  -> null : rien ne change
 *   (un nouveau client reste « inconnu », un client existant garde son statut)
 * - autre valeur                                  -> invalide : signalée, rien ne change
 *
 * Une réponse explicite dans le fichier remplace le statut enregistré : c'est
 * la dernière expression du client (il peut revenir sur un refus en caisse).
 */
class ImportedConsent
{
    /** Noms de colonne acceptés, en minuscules. */
    public const COLUMNS = ['consentement', 'consentement_whatsapp', 'consentement whatsapp', 'opt_in', 'optin'];

    private const YES = ['oui', 'o', 'yes', 'y', '1', 'x', 'vrai', 'true', 'ok', 'accord', 'optin', 'opt-in', 'opt_in', 'opt in'];

    private const NO = ['non', 'n', 'no', '0', 'faux', 'false', 'refus', 'refuse', 'stop', 'optout', 'opt-out', 'opt_out', 'opt out'];

    /**
     * Valeur de la colonne consentement d'une ligne (première colonne reconnue).
     *
     * @param  array<string, mixed>  $row  ligne indexée par en-tête en minuscules
     */
    public static function valueFrom(array $row): ?string
    {
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                return trim((string) $row[$column]);
            }
        }

        return null;
    }

    /**
     * @return 'opt_in'|'opt_out'|null|false null = non renseigné, false = valeur non reconnue
     */
    public static function parse(?string $value): string|null|false
    {
        $normalized = Str::lower(Str::ascii(trim((string) $value)));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, self::YES, true)) {
            return 'opt_in';
        }

        if (in_array($normalized, self::NO, true)) {
            return 'opt_out';
        }

        return false;
    }
}
