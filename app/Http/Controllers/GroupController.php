<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Group;
use App\Services\CustomerSearch;
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
        $user = $request->user();
        $visibleIds = $user->visibleStoreIds();
        $inScope = fn ($q) => $q->when($visibleIds !== null, fn ($q) => $q->whereIn('contacts.store_id', $visibleIds));

        // Contacts comptés dans le périmètre du rôle ; « joignables » = opt-in (seuls destinataires marketing).
        $groups = Group::where('organization_id', $user->organization_id)
            ->withCount([
                'contacts' => $inScope,
                'contacts as opt_in_count' => fn ($q) => $inScope($q)->where('contacts.consent_status', 'opt_in'),
            ])
            ->orderBy('name')
            ->get();

        // Nombre de personnes (fiches clients) par groupe : un client présent dans
        // deux boutiques du même groupe ne compte qu'une fois.
        $customers = Contact::query()
            ->join('contact_group', 'contact_group.contact_id', '=', 'contacts.id')
            ->whereIn('contact_group.group_id', $groups->pluck('id'))
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('contacts.store_id', $visibleIds))
            ->groupBy('contact_group.group_id')
            ->selectRaw('contact_group.group_id, COUNT(DISTINCT contacts.customer_id) as total')
            ->pluck('total', 'group_id');

        // Utilisation en campagne : le ciblage par groupe est enregistré dans segment_filters.group_ids.
        $usage = [];
        Campaign::whereHas('stores', fn ($q) => $q->where('organization_id', $user->organization_id))
            ->whereNotNull('segment_filters')
            ->orderBy('created_at')
            ->get(['id', 'segment_filters', 'created_at'])
            ->each(function (Campaign $campaign) use (&$usage) {
                foreach ($campaign->segment_filters['group_ids'] ?? [] as $groupId) {
                    $usage[(int) $groupId] = [
                        'campaigns' => ($usage[(int) $groupId]['campaigns'] ?? 0) + 1,
                        'last_used_at' => $campaign->created_at->toDateString(),
                    ];
                }
            });

        return Inertia::render('Groups/Index', [
            'groups' => $groups->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'contacts_count' => $group->contacts_count,
                'customers_count' => (int) ($customers[$group->id] ?? 0),
                'opt_in_count' => $group->opt_in_count,
                'campaigns_count' => $usage[$group->id]['campaigns'] ?? 0,
                'last_used_at' => $usage[$group->id]['last_used_at'] ?? null,
                'created_at' => $group->created_at?->toDateString(),
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

    /**
     * Ajoute des clients au groupe : cochés dans Fiches clients (ou tout le
     * filtre), ou depuis une fiche client. Ce sont leurs contacts du périmètre
     * (et du filtre de boutique éventuel) qui rejoignent le groupe.
     */
    public function addCustomers(Request $request, Group $group): RedirectResponse
    {
        [$contactIds, $count] = $this->selectedContacts($request, $group);

        $group->contacts()->syncWithoutDetaching($contactIds);

        return back()->with('success', $count > 1
            ? "{$count} clients ajoutés au groupe « {$group->name} »."
            : "Client ajouté au groupe « {$group->name} ».");
    }

    public function removeCustomers(Request $request, Group $group): RedirectResponse
    {
        [$contactIds, $count] = $this->selectedContacts($request, $group);

        $group->contacts()->detach($contactIds);

        return back()->with('success', $count > 1
            ? "{$count} clients retirés du groupe « {$group->name} »."
            : "Client retiré du groupe « {$group->name} ».");
    }

    /**
     * @return array{0: array<int, int>, 1: int} contacts concernés, nombre de clients
     */
    private function selectedContacts(Request $request, Group $group): array
    {
        $this->authorizeSameOrganization($request, $group);

        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'customer_ids' => ['exclude_if:all,1', 'required', 'array', 'min:1'],
            'customer_ids.*' => ['integer'],
            'filters' => ['nullable', 'array'],
        ]);

        // Mêmes filtres et même périmètre que la liste des fiches clients.
        $search = new CustomerSearch($request->user(), $data['filters'] ?? []);
        $customers = $request->boolean('all') ? $search->query() : $search->query()->whereKey(array_map('intval', $data['customer_ids']));

        $contacts = Contact::query()
            ->where($search->contactScope())
            ->whereIn('contacts.customer_id', (clone $customers)->select('customers.id'))
            ->pluck('contacts.id');

        if ($contacts->isEmpty()) {
            throw new HttpException(403, 'Aucun client de votre périmètre dans cette sélection.');
        }

        return [$contacts->all(), Contact::whereKey($contacts)->distinct()->count('customer_id')];
    }

    private function authorizeSameOrganization(Request $request, Group $group): void
    {
        if ($group->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Ce groupe n\'appartient pas à votre organisation.');
        }
    }
}
