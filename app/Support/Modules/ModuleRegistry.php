<?php

namespace App\Support\Modules;

use App\Models\Organization;

/**
 * Registre des modules ClientlyX, à la manière d'Odoo : chaque dossier
 * app/Modules/<Nom>/ déclare dans module.php un ou plusieurs modules
 * (clé, pack minimum, dépendances, application, automatisation, routes,
 * migrations). Le registre les lit une fois et répond aux questions
 * « quel pack inclut ce module ? », « que faut-il activer avec lui ? ».
 */
class ModuleRegistry
{
    /** Ordre des packs, du moins cher au plus complet. */
    public const PACK_ORDER = ['gratuit', 'solo', 'equipe', 'premium', 'entreprise'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $modules = null;

    /** @var list<string> */
    private static array $folders = [];

    /**
     * @return array<string, array{key: string, label: string, description: string, pack: string, depends: list<string>, app: string|null, automation: string|null, folder: string}>
     */
    public static function all(): array
    {
        if (self::$modules !== null) {
            return self::$modules;
        }

        $modules = [];
        self::$folders = [];

        foreach (glob(app_path('Modules/*/module.php')) ?: [] as $file) {
            $folder = dirname($file);
            self::$folders[] = $folder;
            $declared = require $file;
            $list = isset($declared['key']) ? [$declared] : $declared;

            foreach ($list as $manifest) {
                $key = $manifest['key'];
                $modules[$key] = [
                    'key' => $key,
                    'label' => $manifest['label'] ?? Organization::MODULES[$key]['label'] ?? $key,
                    'description' => $manifest['description'] ?? Organization::MODULES[$key]['description'] ?? '',
                    'pack' => $manifest['pack'] ?? 'gratuit',
                    'depends' => $manifest['depends'] ?? [],
                    'app' => $manifest['app'] ?? null,
                    'automation' => $manifest['automation'] ?? null,
                    'folder' => $folder,
                ];
            }
        }

        return self::$modules = $modules;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Dossiers de modules (routes.php, migrations/) à charger. @return list<string> */
    public static function folders(): array
    {
        self::all();

        return self::$folders;
    }

    public static function packRank(?string $pack): int
    {
        $rank = array_search($pack, self::PACK_ORDER, true);

        return $rank === false ? 0 : $rank;
    }

    /** Pack minimum qui inclut le module (gratuit si inconnu). */
    public static function packFor(string $key): string
    {
        return self::get($key)['pack'] ?? 'gratuit';
    }

    /**
     * Ajoute les dépendances (récursivement) à une liste de modules.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function withDependencies(array $keys): array
    {
        $result = [];
        $stack = array_values($keys);

        while ($stack !== []) {
            $key = array_shift($stack);
            if (in_array($key, $result, true)) {
                continue;
            }
            $result[] = $key;
            foreach (self::get($key)['depends'] ?? [] as $dependency) {
                $stack[] = $dependency;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function pack(string $pack): array
    {
        return config("nentix.packs.{$pack}") ?? config('nentix.packs.gratuit');
    }

    /** Vide le cache (tests). */
    public static function flush(): void
    {
        self::$modules = null;
    }
}
