<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_BRAND = 'brand';

    public const SCOPE_STORE = 'store';

    public const SCOPES = [
        self::SCOPE_ORGANIZATION,
        self::SCOPE_BRAND,
        self::SCOPE_STORE,
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'scope',
        'can_manage_organization',
        'can_import',
        'can_export',
    ];

    protected function casts(): array
    {
        return [
            'can_manage_organization' => 'boolean',
            'can_import' => 'boolean',
            'can_export' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Les 4 rôles fournis par défaut à toute organisation, reproduisant le
     * périmètre qui était jusqu'ici câblé en dur (super_admin / admin /
     * brand_manager / store_manager). Une organisation peut ensuite les
     * renommer, en changer le périmètre, ou créer ses propres rôles.
     *
     * @return array<int, array{name: string, scope: string, can_manage_organization: bool}>
     */
    public static function defaultDefinitions(): array
    {
        return [
            ['name' => 'Super administrateur', 'scope' => self::SCOPE_ORGANIZATION, 'can_manage_organization' => true],
            ['name' => 'Administrateur', 'scope' => self::SCOPE_ORGANIZATION, 'can_manage_organization' => true],
            ['name' => 'Responsable de marque', 'scope' => self::SCOPE_BRAND, 'can_manage_organization' => false],
            ['name' => 'Responsable de boutique', 'scope' => self::SCOPE_STORE, 'can_manage_organization' => false],
        ];
    }

    /**
     * Rôles types d'un réseau de vente (Menentia Retail), créés pour une
     * entreprise du commerce et ajoutables depuis Utilisateurs & rôles. Même
     * système que les autres rôles : un périmètre (groupe, marque, point de
     * vente) et trois droits (gérer l'entreprise, importer, exporter).
     *
     * @return array<int, array{name: string, scope: string, can_manage_organization: bool, can_import: bool, can_export: bool, description: string}>
     */
    public static function retailDefinitions(): array
    {
        return [
            ['name' => 'Admin groupe', 'scope' => self::SCOPE_ORGANIZATION, 'can_manage_organization' => true, 'can_import' => true, 'can_export' => true,
                'description' => 'Tout le groupe : paramètres, utilisateurs, imports, exports.'],
            ['name' => 'Direction', 'scope' => self::SCOPE_ORGANIZATION, 'can_manage_organization' => false, 'can_import' => false, 'can_export' => true,
                'description' => 'Tous les tableaux de bord et exports du groupe, sans les paramètres.'],
            ['name' => 'Responsable de marque', 'scope' => self::SCOPE_BRAND, 'can_manage_organization' => false, 'can_import' => true, 'can_export' => true,
                'description' => 'Une marque et ses points de vente uniquement.'],
            ['name' => 'Responsable de boutique', 'scope' => self::SCOPE_STORE, 'can_manage_organization' => false, 'can_import' => true, 'can_export' => false,
                'description' => 'Un seul point de vente.'],
            ['name' => 'Marketing', 'scope' => self::SCOPE_ORGANIZATION, 'can_manage_organization' => false, 'can_import' => false, 'can_export' => true,
                'description' => 'Clients, segments, groupes et campagnes du groupe ; pas d’import.'],
            ['name' => 'Opérateur d’import', 'scope' => self::SCOPE_ORGANIZATION, 'can_manage_organization' => false, 'can_import' => true, 'can_export' => false,
                'description' => 'Dépose et suit les imports de ventes ; aucun export de données.'],
        ];
    }

    public static function seedDefaultsFor(Organization $organization): void
    {
        $definitions = self::defaultDefinitions();

        if ($organization->sector === 'commerce') {
            $definitions = [...$definitions, ...self::retailDefinitions()];
        }

        foreach ($definitions as $definition) {
            self::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => $definition['name']],
                array_intersect_key($definition, array_flip(['scope', 'can_manage_organization', 'can_import', 'can_export'])),
            );
        }
    }

    /** Ajoute les rôles types Retail absents ; renvoie le nombre de rôles créés. */
    public static function seedRetailFor(Organization $organization): int
    {
        $created = 0;

        foreach (self::retailDefinitions() as $definition) {
            $role = self::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => $definition['name']],
                array_intersect_key($definition, array_flip(['scope', 'can_manage_organization', 'can_import', 'can_export'])),
            );
            $created += $role->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
