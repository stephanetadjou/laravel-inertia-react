<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Capture de l'Agent IA : ce que le prospect donne dans la conversation
 * (email, ville, quartier, adresse, budget) arrive seul sur sa fiche. Sans
 * IA : expressions courantes en français. Un champ déjà rempli n'est jamais
 * écrasé (la saisie de l'équipe l'emporte).
 */
class LeadCapture
{
    /** Villes du Gabon reconnues sans formule (« je suis à Owendo »). */
    public const CITIES = ['Libreville', 'Owendo', 'Akanda', 'Ntoum', 'Port-Gentil', 'Franceville', 'Oyem', 'Moanda', 'Lambaréné',
        'Mouila', 'Tchibanga', 'Koulamoutou', 'Makokou', 'Bitam', 'Lastourville', 'Gamba', 'Mitzic', 'Booué', 'Okondja', 'Ndjolé'];

    /** @return array<string, string> champs trouvés dans le texte */
    public static function extract(string $text): array
    {
        $found = [];
        $ascii = Str::of($text)->ascii()->lower()->value();

        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
            $found['email'] = Str::lower($m[0]);
        }

        foreach (self::CITIES as $city) {
            if (preg_match('/\b'.preg_quote(Str::of($city)->ascii()->lower()->value(), '/').'\b/', $ascii)) {
                $found['city'] = $city;
                break;
            }
        }

        // « quartier Louis », « au quartier Nzeng-Ayong »
        if (preg_match('/\bquartier\s+([\p{L}][\p{L}\'’ -]{1,40}?)(?=[,.;!?\n]|\s+(?:a|à|vers|pres|près|derriere|derrière|et)\b|$)/iu', $text, $m)) {
            $found['district'] = Str::title(trim($m[1]));
        }

        // « adresse : … », « j'habite … », « livrez-moi à … »
        if (preg_match('/\b(?:adresse\s*(?:est|:)?|j[\'’]habite(?:\s+[aà])?|livr\w*(?:-moi)?\s+[aà])\s+([^\n.!?]{4,120})/iu', $text, $m)) {
            $found['address'] = trim($m[1], " \t,;:");
        }

        // « budget de 50 000 », « 150000 FCFA », « 50k »
        if (preg_match('/\bbudget\D{0,20}(\d[\d  .]*\s*(?:k|000)?\s*(?:f\s?cfa|fcfa|xaf|francs?|f)?)/iu', $text, $m)
            || preg_match('/\b(\d{1,3}(?:[  .]?\d{3})+|\d+\s*k)\s*(?:f\s?cfa|fcfa|xaf|francs?)\b/iu', $text, $m)) {
            $found['budget'] = trim(preg_replace('/\s+/u', ' ', $m[1]));
        }

        return $found;
    }

    /** Remplit les champs vides de la fiche ; renvoie ceux qui ont été ajoutés. */
    public static function apply(Customer $customer, string $text): array
    {
        $added = array_filter(self::extract($text), fn ($value, $field) => blank($customer->{$field}), ARRAY_FILTER_USE_BOTH);

        if ($added !== []) {
            $customer->forceFill($added)->save();
        }

        return $added;
    }
}
