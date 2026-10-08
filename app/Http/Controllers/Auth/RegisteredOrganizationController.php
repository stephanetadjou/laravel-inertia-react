<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inscription d'une entreprise cliente : crée en une fois l'organisation,
 * ses rôles par défaut, son premier établissement (marque + boutique du même
 * nom) et le compte de la personne qui s'inscrit, Super administrateur.
 * La connexion WhatsApp de l'établissement se fait ensuite dans Paramètres.
 */
class RegisteredOrganizationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'store_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Slug choisi hors transaction : la transaction commence ainsi par une
        // écriture et attend le verrou SQLite au lieu d'échouer (cf. uniqueSlug).
        $slug = Organization::uniqueSlug($validated['organization_name']);

        $user = DB::transaction(function () use ($validated, $slug) {
            $organization = Organization::create(['name' => $validated['organization_name'], 'slug' => $slug]);

            Role::seedDefaultsFor($organization);

            $brand = Brand::create([
                'organization_id' => $organization->id,
                'name' => $validated['store_name'],
            ]);

            Store::create([
                'organization_id' => $organization->id,
                'brand_id' => $brand->id,
                'name' => $validated['store_name'],
            ]);

            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'organization_id' => $organization->id,
                'role_id' => $organization->roles()->where('name', 'Super administrateur')->value('id'),
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard.index')
            ->with('success', 'Bienvenue ! Connectez maintenant le numéro WhatsApp de votre établissement.');
    }
}
