<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\View\View;

/**
 * Politique de confidentialité publique d'une entreprise cliente, à
 * renseigner comme « URL de la politique de confidentialité » de son app
 * Meta. Rendue côté serveur (Blade) pour être lisible sans JavaScript, y
 * compris par les vérifications automatiques de Meta.
 */
class PrivacyPolicyController extends Controller
{
    public function show(Organization $organization): View
    {
        return view('privacy-policy', [
            'organization' => $organization,
            'company' => $organization->legal_name ?: $organization->name,
            'stores' => $organization->stores()->orderBy('name')->pluck('name'),
        ]);
    }
}
