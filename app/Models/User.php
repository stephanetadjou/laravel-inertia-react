<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'organization_id', 'role_id', 'brand_id', 'store_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function managedStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Le rôle de l'utilisateur donne accès à la gestion de l'organisation
     * (marques, boutiques, utilisateurs, rôles) — configurable par rôle,
     * plus seulement les deux rôles historiques super_admin/admin.
     */
    public function canManageOrganization(): bool
    {
        return $this->role?->can_manage_organization ?? false;
    }

    /**
     * @return array<int, int>|null Liste d'IDs de boutiques visibles,
     *                              ou null si aucune restriction (org entière).
     */
    public function visibleStoreIds(): ?array
    {
        $scope = $this->role?->scope;

        if ($scope === Role::SCOPE_BRAND) {
            return Store::where('brand_id', $this->brand_id)->pluck('id')->all();
        }

        if ($scope === Role::SCOPE_STORE) {
            return $this->store_id ? [$this->store_id] : [];
        }

        return null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
