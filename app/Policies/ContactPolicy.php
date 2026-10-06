<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\Role;
use App\Models\User;

class ContactPolicy
{
    /**
     * Modifier la fiche d'un contact : réservé à un administrateur de
     * l'organisation (rôle avec gestion de l'organisation), ou au responsable
     * de la marque / de la boutique à laquelle le contact est rattaché.
     * Un rôle sans périmètre de marque/boutique ni droit d'administration
     * (ou un utilisateur sans rôle) ne modifie rien.
     */
    public function update(User $user, Contact $contact): bool
    {
        $store = $contact->store;

        if ($store->organization_id !== $user->organization_id) {
            return false;
        }

        if ($user->canManageOrganization()) {
            return true;
        }

        return match ($user->role?->scope) {
            Role::SCOPE_BRAND => $user->brand_id !== null && $store->brand_id === $user->brand_id,
            Role::SCOPE_STORE => $user->store_id !== null && $store->id === $user->store_id,
            default => false,
        };
    }
}
