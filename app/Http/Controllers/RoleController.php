<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = Role::where('organization_id', $request->user()->organization_id)
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return Inertia::render('Roles/Index', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'scope' => $role->scope,
                'can_manage_organization' => $role->can_manage_organization,
                'can_import' => $role->can_import ?? true,
                'can_export' => $role->can_export ?? true,
                'users_count' => $role->users_count,
            ]),
            'presets' => collect(Role::retailDefinitions())->map(fn ($d) => [
                'name' => $d['name'],
                'description' => $d['description'],
                'exists' => $roles->contains('name', $d['name']),
            ]),
        ]);
    }

    /** Ajoute les rôles types d'un réseau de vente (Admin groupe, Direction, Marketing…). */
    public function presets(Request $request): RedirectResponse
    {
        $created = Role::seedRetailFor($request->user()->organization);

        if ($created > 0) {
            Audit::record('settings.updated', $request->user()->organization, "{$created} rôle(s) type(s) ajouté(s)");
        }

        return back()->with('success', $created > 0 ? "{$created} rôle(s) type(s) ajouté(s)." : 'Les rôles types existent déjà.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateRole($request);

        $request->user()->organization->roles()->create($data);

        return back()->with('success', "Rôle « {$data['name']} » créé.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $role);

        $data = $this->validateRole($request, $role);

        if ($role->can_manage_organization && ! $data['can_manage_organization'] && $this->wouldLockOutOrganization($role)) {
            return back()->with('error', "Impossible de retirer ce droit : ce rôle est utilisé par le dernier utilisateur capable de gérer l'organisation.");
        }

        $role->update($data);

        return back()->with('success', "Rôle « {$role->name} » mis à jour.");
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $role);

        if ($role->users()->exists()) {
            return back()->with('error', "Réaffectez d'abord les utilisateurs du rôle « {$role->name} » avant de le supprimer.");
        }

        $role->delete();

        return back()->with('success', 'Rôle supprimé.');
    }

    /**
     * @return array{name: string, scope: string, can_manage_organization: bool}
     */
    private function validateRole(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                )->ignore($role?->id),
            ],
            'scope' => ['required', Rule::in(Role::SCOPES)],
            'can_manage_organization' => ['nullable', 'boolean'],
            'can_import' => ['nullable', 'boolean'],
            'can_export' => ['nullable', 'boolean'],
        ]);

        $data['can_manage_organization'] = $request->boolean('can_manage_organization');
        $data['can_import'] = $request->boolean('can_import', true);
        $data['can_export'] = $request->boolean('can_export', true);

        return $data;
    }

    /**
     * Vrai si retirer can_manage_organization de $role laisserait
     * l'organisation sans aucun utilisateur capable de la gérer.
     */
    private function wouldLockOutOrganization(Role $role): bool
    {
        if (! $role->users()->exists()) {
            return false;
        }

        return ! Role::where('organization_id', $role->organization_id)
            ->where('id', '!=', $role->id)
            ->where('can_manage_organization', true)
            ->whereHas('users')
            ->exists();
    }

    private function authorizeSameOrganization(Request $request, Role $role): void
    {
        if ($role->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Ce rôle n\'appartient pas à votre organisation.');
        }
    }
}
