<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $users = User::where('organization_id', $organizationId)
            ->with(['role', 'brand', 'managedStore'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Users/Index', [
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'role_name' => $user->role?->name,
                'brand_id' => $user->brand_id,
                'brand_name' => $user->brand?->name,
                'store_id' => $user->store_id,
                'store_name' => $user->managedStore?->name,
            ]),
            'brands' => Brand::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'brand_id']),
            'roles' => Role::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'scope']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ['data' => $data, 'role' => $role] = $this->validateUser($request);

        User::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $role->id,
            'brand_id' => $role->scope === Role::SCOPE_BRAND ? $data['brand_id'] : null,
            'store_id' => $role->scope === Role::SCOPE_STORE ? $data['store_id'] : null,
        ]);

        return back()->with('success', "Utilisateur « {$data['name']} » créé.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $user);

        ['data' => $data, 'role' => $role] = $this->validateUser($request, $user);

        if ($user->role?->can_manage_organization && ! $role->can_manage_organization && ! $this->hasOtherOrganizationManager($user)) {
            return back()->with('error', "Impossible de retirer ce rôle : ce serait le dernier utilisateur capable de gérer l'organisation.");
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role_id = $role->id;
        $user->brand_id = $role->scope === Role::SCOPE_BRAND ? $data['brand_id'] : null;
        $user->store_id = $role->scope === Role::SCOPE_STORE ? $data['store_id'] : null;

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return back()->with('success', "Utilisateur « {$user->name} » mis à jour.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $user);

        if ($user->is($request->user())) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        if ($user->canManageOrganization() && ! $this->hasOtherOrganizationManager($user)) {
            return back()->with('error', 'Impossible de supprimer le dernier utilisateur capable de gérer l\'organisation.');
        }

        $name = $user->name;
        $user->delete();

        return back()->with('success', "Utilisateur « {$name} » supprimé.");
    }

    /**
     * @return array{data: array{name: string, email: string, password: string|null, role_id: int, brand_id: int|null, store_id: int|null}, role: Role}
     */
    private function validateUser(Request $request, ?User $user = null): array
    {
        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => [$user ? 'nullable' : 'required', 'nullable', Password::min(8)],
            'role_id' => [
                'required', 'integer',
                Rule::exists('roles', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
        ]);

        /** @var Role $role */
        $role = Role::findOrFail($data['role_id']);

        $data = array_merge($data, $request->validate([
            'brand_id' => [
                Rule::requiredIf($role->scope === Role::SCOPE_BRAND), 'nullable', 'integer',
                Rule::exists('brands', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'store_id' => [
                Rule::requiredIf($role->scope === Role::SCOPE_STORE), 'nullable', 'integer',
                Rule::exists('stores', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
        ]));

        return ['data' => $data, 'role' => $role];
    }

    /**
     * Vrai s'il existe, dans l'organisation, un autre utilisateur que $user
     * capable de gérer l'organisation (utilisateurs, rôles, marques,
     * boutiques) — sert à ne jamais verrouiller une organisation hors de sa
     * propre gestion.
     */
    private function hasOtherOrganizationManager(User $user): bool
    {
        return User::where('organization_id', $user->organization_id)
            ->where('id', '!=', $user->id)
            ->whereHas('role', fn ($q) => $q->where('can_manage_organization', true))
            ->exists();
    }

    private function authorizeSameOrganization(Request $request, User $user): void
    {
        if ($user->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cet utilisateur n\'appartient pas à votre organisation.');
        }
    }
}
