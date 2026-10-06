<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Filtre « marque » en query string, borné par le périmètre du rôle :
 * un responsable de marque est verrouillé sur sa marque, un rôle boutique
 * n'a pas de filtre marque, et une marque hors organisation est ignorée.
 */
trait ResolvesBrandFilter
{
    /**
     * @param  array<int, int>|null  $visibleIds
     * @return array{selected: int|null, locked: bool, hidden: bool}
     */
    protected function resolveBrandFilter(Request $request, User $user, ?array $visibleIds): array
    {
        $scope = $user->role?->scope;

        if ($scope === Role::SCOPE_STORE) {
            return ['selected' => null, 'locked' => true, 'hidden' => true];
        }

        if ($scope === Role::SCOPE_BRAND) {
            return ['selected' => $user->brand_id, 'locked' => true, 'hidden' => false];
        }

        $brandId = $request->integer('brand') ?: null;

        $allowed = $brandId !== null && Store::where('organization_id', $user->organization_id)
            ->where('brand_id', $brandId)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->exists();

        return ['selected' => $allowed ? $brandId : null, 'locked' => false, 'hidden' => false];
    }
}
