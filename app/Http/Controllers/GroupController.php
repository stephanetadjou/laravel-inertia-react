<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GroupController extends Controller
{
    public function index(Request $request): Response
    {
        $groups = Group::where('organization_id', $request->user()->organization_id)
            ->withCount('contacts')
            ->orderBy('name')
            ->get();

        return Inertia::render('Groups/Index', [
            'groups' => $groups->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'contacts_count' => $group->contacts_count,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('groups', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                ),
            ],
        ]);

        $request->user()->organization->groups()->create($data);

        return back()->with('success', "Groupe « {$data['name']} » créé.");
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $group);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('groups', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                )->ignore($group->id),
            ],
        ]);

        $group->update($data);

        return back()->with('success', "Groupe renommé en « {$group->name} ».");
    }

    public function destroy(Request $request, Group $group): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $group);

        $group->delete();

        return back()->with('success', 'Groupe supprimé.');
    }

    private function authorizeSameOrganization(Request $request, Group $group): void
    {
        if ($group->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Ce groupe n\'appartient pas à votre organisation.');
        }
    }
}
