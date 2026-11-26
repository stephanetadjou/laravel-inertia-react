<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;

/**
 * Langues de l'application. Les textes sont écrits en français dans le code ;
 * la traduction anglaise de chaque phrase est dans lang/en.json (clé = phrase
 * française), lue par __() côté Laravel et par t() côté React.
 */
class Locale
{
    public const SUPPORTED = ['fr', 'en'];

    public const DEFAULT = 'fr';

    public static function resolve(Request $request): string
    {
        $user = $request->user();

        // Page publique d'une entreprise (/rdv/{entreprise}…) : la langue de l'entreprise.
        $routeOrganization = $request->route('organization');

        $candidates = [
            $request->session()->get('locale'),
            $user?->locale,
            $user?->organization?->locale,
            is_object($routeOrganization) ? ($routeOrganization->locale ?? null) : null,
        ];
        foreach ($candidates as $locale) {
            if (in_array($locale, self::SUPPORTED, true)) {
                return $locale;
            }
        }

        // Visiteur sans choix : français ; le lien ?lang=en (sélecteur FR | EN) passe en anglais.
        return self::DEFAULT;
    }

    public static function apply(string $locale): void
    {
        App::setLocale($locale);
        Carbon::setLocale($locale);
    }

    /** Dictionnaire envoyé à React (vide en français : la clé est déjà le texte). @return array<string, string> */
    public static function dictionary(string $locale): array
    {
        static $cache = [];

        if ($locale === self::DEFAULT) {
            return [];
        }

        return $cache[$locale] ??= (function () use ($locale): array {
            $path = lang_path("{$locale}.json");

            return File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
        })();
    }
}
