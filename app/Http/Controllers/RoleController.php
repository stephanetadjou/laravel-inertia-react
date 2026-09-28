<?php

namespace App\Http\Controllers;

use App\Models\Role;
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
                'users_count' => $role->users_count,
            ]),
        ]);
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
        ]);

        $data['can_manage_organization'] = $request->boolean('can_manage_organization');

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
