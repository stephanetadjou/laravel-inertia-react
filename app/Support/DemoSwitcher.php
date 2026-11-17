<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/**
 * Phase de test : passer d'une entreprise de démonstration à l'autre (une
 * par secteur) sans se reconnecter, depuis la barre du haut.
 *
 * Désactivé par défaut : il faut DEMO_SWITCHER=true dans le .env, et jamais
 * en production. Seules les entreprises de démo listées ici sont proposées,
 * et seulement à un utilisateur déjà connecté à l'une d'elles (ou à un
 * administrateur de la plateforme) : une vraie entreprise n'y a jamais accès.
 */
class DemoSwitcher
{
    /** Secteur → entreprise de démo (slug), dans l'ordre du menu. */
    public const DEMOS = [
        'beaute' => 'demo-nentix-beaute',
        'sante' => 'demo-nentix-sante',
        'optique' => 'demo-optique-lumiere',
        'sport' => 'demo-sport',
        'formation' => 'demo-auto-ecole',
        'location' => 'demo-location-vehicules',
        'location_yamoo' => 'demo-yamoo-drive',
        'vehicules' => 'demo-ogooue-auto',
        'technique' => 'demo-nentix-garage',
        'pro' => 'demo-menentia',
        'immobilier' => 'demo-nentix-immobilier',
        'commerce' => 'demo-nentix-boutique',
    ];

    /** Libellés des démos supplémentaires d'un même secteur. */
    public const LABELS = ['location_yamoo' => 'Yamoo Drive (location)'];

    public static function enabled(): bool
    {
        return (bool) config('services.demo_switcher') && ! app()->isProduction();
    }

    public static function allowedFor(?User $user): bool
    {
        return self::enabled() && $user !== null
            && ($user->isPlatformAdmin() || in_array($user->organization?->slug, self::DEMOS, true));
    }

    /**
     * Entreprises de démo présentes dans la base.
     *
     * @return list<array{slug: string, sector: string, label: string, name: string}>
     */
    public static function options(): array
    {
        $organizations = Organization::whereIn('slug', array_values(self::DEMOS))->get(['id', 'slug', 'name'])->keyBy('slug');

        return collect(self::DEMOS)
            ->filter(fn ($slug) => $organizations->has($slug))
            ->map(fn ($slug, $sector) => [
                'slug' => $slug,
                'sector' => $sector,
                'label' => self::LABELS[$sector] ?? Organization::SECTORS[$sector]['label'] ?? $sector,
                'name' => $organizations[$slug]->name,
            ])
            ->values()
            ->all();
    }

    /** Compte utilisé dans l'entreprise de démo : son premier administrateur. */
    public static function accountFor(string $slug): ?User
    {
        if (! in_array($slug, self::DEMOS, true)) {
            return null;
        }

        $organization = Organization::where('slug', $slug)->first();

        return $organization
            ? User::where('organization_id', $organization->id)
                ->orderByRaw('case when role_id = (select id from roles where roles.organization_id = users.organization_id and roles.name = ? limit 1) then 0 else 1 end', ['Super administrateur'])
                ->orderBy('id')
                ->first()
            : null;
    }
}
