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
    ];

    protected function casts(): array
    {
        return [
            'can_manage_organization' => 'boolean',
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

    public static function seedDefaultsFor(Organization $organization): void
    {
        foreach (self::defaultDefinitions() as $definition) {
            self::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => $definition['name']],
                ['scope' => $definition['scope'], 'can_manage_organization' => $definition['can_manage_organization']],
            );
        }
    }
}
