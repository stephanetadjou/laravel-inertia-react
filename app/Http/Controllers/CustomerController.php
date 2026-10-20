<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Group;
use App\Models\MessageTemplate;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Services\CustomerSearch;
use App\Services\PhoneNormalizer;
use App\Services\RfmCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fiches clients uniques (une personne pour tout le Groupe) et parcours client.
 *
 * Périmètre : un utilisateur voit une fiche dès qu'un contact de la personne
 * est dans une boutique de son périmètre. Un rôle marque ou boutique ne voit
 * que les contacts, achats et messages de son périmètre ; les compteurs sont
 * alors recalculés sur ce périmètre, l'étape du parcours reste celle du Groupe.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = CustomerSearch::fromRequest($request);
        $visibleIds = $request->user()->visibleStoreIds();

        $sort = in_array($request->query('sort'), ['last_purchase', 'total_spent', 'name', 'score'], true) ? (string) $request->query('sort') : 'last_purchase';
        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        // Compteurs des filtres : chacun ignore son propre critère, pour
        // afficher combien de fiches on obtiendrait en le choisissant.
        $stageCounts = $search->baseQuery()->selectRaw('lifecycle_stage, count(*) as total')->groupBy('lifecycle_stage')->pluck('total', 'lifecycle_stage');
        $vipCount = $search->baseQuery(['vip' => true])
            ->when($search->stage !== null, fn ($q) => $q->where('lifecycle_stage', $search->stage))
            ->whereHas('contacts', fn ($q) => $q->where($search->contactScope(['vip' => true]))->where('contacts.is_vip', true))
            ->count();
        $consentCounts = collect(CustomerSearch::CONSENTS)->mapWithKeys(fn (string $consent) => [
            $consent => $search->baseQuery(['consent' => true])
                ->when($search->stage !== null, fn ($q) => $q->where('lifecycle_stage', $search->stage))
                ->whereHas('contacts', fn ($q) => $q->where($search->contactScope(['consent' => true]))->where('contacts.consent_status', $consent))
                ->count(),
        ]);

        // Contacts affichés dans chaque ligne : ceux qui correspondent au filtre.
        $page = $search->query()
            ->with(['contacts' => fn ($q) => $q->where($search->contactScope())->with('store:id,name')])
            ->when($sort === 'name', fn ($q) => $q->orderBy('name'))
            ->when($sort === 'total_spent', fn ($q) => $q->orderByDesc('total_spent'))
            ->when($sort === 'score', fn ($q) => $q->orderByRaw('score is null')->orderByDesc('score'))
            ->when($sort === 'last_purchase', fn ($q) => $q->orderByRaw('last_purchase_at is null')->orderByDesc('last_purchase_at'))
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $scoped = $visibleIds !== null;
        $stats = $scoped ? $this->statsFor($page->getCollection()->pluck('id')->all(), $search->roleStores->pluck('id')->all()) : collect();

        // Pour « Modifier » : toutes les boutiques du périmètre de chaque fiche
        // (le filtre affiché peut en masquer), avec le droit de modification.
        $user = $request->user();
        $editContacts = Contact::whereIn('customer_id', $page->getCollection()->pluck('id'))
            ->whereIn('store_id', $search->roleStores->pluck('id'))
            ->with('store:id,name,organization_id,brand_id') // colonnes lues par ContactPolicy
            ->orderBy('id')
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($contacts) => $contacts->map(fn (Contact $c) => [
                'id' => $c->id,
                'store_name' => $c->store->name,
                'consent_status' => $c->consent_status,
                'editable' => $user->can('update', $c),
            ])->values());

        return Inertia::render('Customers/Index', [
            'filters' => [
                'stage' => $search->stage,
                'vip' => $search->vip,
                'segment' => $search->segment,
                'risk' => $search->risk,
                'consent' => $search->consent,
                'q' => $search->search,
                'sort' => $sort,
                'per_page' => $perPage,
                'brand' => $search->brandFilter['selected'],
                'brand_locked' => $search->brandFilter['locked'],
                'brand_hidden' => $search->brandFilter['hidden'],
                'store' => $search->storeId,
                'group' => $search->group?->id,
                'brands' => $search->roleStores->pluck('brand')->filter()->unique('id')->sortBy('name')->values()
                    ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name]),
                'label' => $search->label(),
                'groups' => Group::where('organization_id', $request->user()->organization_id)->orderBy('name')->get(['id', 'name']),
            ],
            'stores' => $search->brandStores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'stages' => collect(Customer::STAGES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'count' => (int) ($stageCounts[$key] ?? 0),
            ])->values(),
            'vipCount' => $vipCount,
            'segments' => collect(RfmCalculator::SEGMENTS)->map(fn ($segment, $key) => ['key' => $key, 'label' => $segment['label']])->values(),
            'riskCount' => $search->baseQuery()->whereIn('churn_risk', ['medium', 'high'])->count(),
            'apiEnabled' => $request->user()->organization->usesWhatsAppApi(),
            'consentCounts' => $consentCounts,
            // Nombre de fiches du filtre joignables par campagne (au moins un contact opt-in).
            'reachableCount' => $search->query()->whereIn('customers.id', $search->recipients(null)->select('contacts.customer_id'))->count(),
            'templates' => MessageTemplate::where('organization_id', $request->user()->organization_id)
                ->sendable()
                ->orderBy('name')
                ->get(['id', 'name', 'language', 'category', 'body_text', 'body_variables', 'header_format']),
            'customers' => $page->through(function (Customer $customer) use ($scoped, $stats, $editContacts) {
                $own = $stats->get($customer->id);
                $contacts = $editContacts->get($customer->id, collect());

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone_e164' => $customer->phone_e164,
                    'gender' => $customer->gender,
                    'birthdate' => $customer->birthdate?->toDateString(),
                    'contacts' => $contacts,
                    'can_edit' => $contacts->contains('editable', true),
                    'stage' => $customer->lifecycle_stage,
                    'segment' => $customer->rfm_segment,
                    'score' => $customer->score,
                    'churn_risk' => $customer->churn_risk,
                    'stores' => $customer->contacts->map(fn (Contact $c) => [
                        'name' => $c->store->name,
                        'vip' => $c->is_vip,
                        'consent' => $c->consent_status,
                    ])->values(),
                    'opt_in' => $customer->contacts->contains('consent_status', 'opt_in'),
                    'purchases_count' => $scoped ? (int) ($own->purchases ?? 0) : $customer->purchases_count,
                    'total_spent' => $scoped ? (int) ($own->spent ?? 0) : (int) $customer->total_spent,
                    'last_purchase_at' => $scoped
                        ? (isset($own->last_at) ? substr((string) $own->last_at, 0, 10) : null)
                        : $customer->last_purchase_at?->toDateString(),
                ];
            }),
        ]);
    }

    public function show(Request $request, Customer $customer, RfmCalculator $rfm): Response
    {
        $storeIds = $this->authorizeVisible($request, $customer);
        $user = $request->user();
        $scoped = $user->visibleStoreIds() !== null;

        $contacts = $customer->contacts()->whereIn('store_id', $storeIds)->with(['store.brand', 'groups:id,name'])->get();
        $contactIds = $contacts->pluck('id');

        // Fiche lisible : 5 derniers achats et messages, liens « Tout voir » vers les historiques.
        $purchases = Purchase::whereIn('contact_id', $contactIds)->with('store:id,name')->orderByDesc('purchased_at')->orderByDesc('id')->take(5)->get();
        $firstPurchaseAt = Purchase::whereIn('contact_id', $contactIds)->min('purchased_at');

        $messages = CampaignMessage::whereIn('contact_id', $contactIds)
            ->whereIn('status', ['sent', 'failed'])
            ->with(['campaign', 'contact.store:id,name'])
            ->get()
            ->map(fn (CampaignMessage $m) => [
                'kind' => 'campaign',
                'label' => $m->campaign ? ($m->campaign->name ?: $m->campaign->template_name) : 'Campagne supprimée',
                'store_name' => $m->contact->store->name,
                'status' => $m->status,
                'delivery' => $m->deliveryState(),
                'at' => $m->updated_at->toIso8601String(),
            ])
            ->merge($contacts->filter(fn (Contact $c) => $c->welcome_message_sent_at)->map(fn (Contact $c) => [
                'kind' => 'welcome',
                'label' => 'Message de bienvenue',
                'store_name' => $c->store->name,
                'status' => 'sent',
                'delivery' => 'sent',
                'at' => $c->welcome_message_sent_at->toIso8601String(),
            ]))
            ->sortByDesc('at')
            ->values();

        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone_e164' => $customer->phone_e164,
                'previous_phones' => $customer->previous_phones ?? [],
                'gender' => $customer->gender,
                'birthdate' => $customer->birthdate?->toDateString(),
                'identity_confirmed' => $customer->identity_confirmed_at !== null,
                'stage' => $customer->lifecycle_stage,
                'stage_since' => $customer->lifecycle_changed_at?->toIso8601String(),
                'created_at' => $customer->created_at->toIso8601String(),
            ],
            'scoped' => $scoped,
            'rfm' => $rfm->forCustomer($customer, $storeIds),
            // Score et alerte de churn enregistrés (toute l'organisation, cf. CustomerScoring).
            'scoring' => [
                'score' => $customer->score,
                'segment' => $customer->rfm_segment,
                'churn_risk' => $customer->churn_risk,
                'churn_label' => CustomerScoring::CHURN_RISKS[$customer->churn_risk] ?? null,
                'avg_interval_days' => $customer->avg_purchase_interval_days,
                'next_expected_at' => $customer->next_purchase_expected_at?->toDateString(),
            ],
            'whatsappUrl' => ActionLists::whatsappUrl($customer->phone_e164, ''),
            'apiEnabled' => $user->organization->usesWhatsAppApi(),
            'appointments' => $customer->appointments()->whereIn('store_id', $storeIds)
                ->with(AppointmentController::RELATIONS)
                ->orderByDesc('starts_at')
                ->take(10)
                ->get()
                ->map(fn (Appointment $a) => AppointmentController::present($a, $user->organization->actionMessages())),
            'appointmentStores' => Store::whereIn('id', $contacts->pluck('store_id'))->orderBy('name')->get(['id', 'name']),
            'appointmentStatuses' => Appointment::STATUSES,
            'catalog' => AppointmentController::catalogFor($user->organization_id),
            'staff' => User::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            'followUps' => $customer->followUps()->with(['user:id,name', 'store:id,name'])->latest('done_at')->take(10)->get()
                ->map(fn (CustomerFollowUp $f) => [
                    'id' => $f->id,
                    'label' => ActionLists::LISTS[$f->action]['label'] ?? $f->action,
                    'channel' => $f->channel,
                    'user_name' => $f->user?->name,
                    'store_name' => $f->store?->name,
                    'at' => $f->done_at->toIso8601String(),
                ]),
            'firstPurchaseAt' => $firstPurchaseAt ? substr((string) $firstPurchaseAt, 0, 10) : null,
            'contacts' => $contacts->map(fn (Contact $c) => [
                'id' => $c->id,
                'editable' => $user->can('update', $c),
                'phone_e164' => $c->phone_e164,
                'name' => $c->name,
                'store_name' => $c->store->name,
                'brand_name' => $c->store->brand?->name,
                'consent_status' => $c->consent_status,
                'consent_at' => $c->consent_at?->toIso8601String(),
                'consent_source' => Contact::CONSENT_SOURCES[$c->consent_source] ?? $c->consent_source,
                'is_vip' => $c->is_vip,
                'vip_since' => $c->vip_since?->toIso8601String(),
                'welcomed' => $c->welcome_message_sent_at !== null,
                'groups' => $c->groups->pluck('name'),
                // Attributs personnalisés de l'import : seulement les valeurs renseignées.
                'attributes' => collect($c->custom_attributes ?? [])
                    ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
                    ->map(fn ($value) => (string) $value),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
            'purchases' => $purchases->map(fn (Purchase $p) => [
                'id' => $p->id,
                'date' => $p->purchased_at->toDateString(),
                'store_name' => $p->store->name,
                'product_category' => $p->product_category,
                'quantity' => $p->quantity,
                'amount' => (int) $p->amount,
            ]),
            'messages' => $messages->take(5),
            'messagesCount' => $messages->count(),
            'stageHistory' => $customer->stageChanges()->orderByDesc('changed_at')->orderByDesc('id')->take(3)->get()
                ->map(fn ($change) => [
                    'from' => $change->from_stage,
                    'to' => $change->to_stage,
                    'at' => $change->changed_at->toIso8601String(),
                ]),
            'stages' => Customer::STAGES,
            'canEdit' => $contacts->contains(fn (Contact $c) => $user->can('update', $c)),
            // Modèles « utilitaire » pour le message de bienvenue (cf. ContactController::sendWelcome).
            // Groupes de l'organisation, pour ajouter / retirer la personne d'un groupe depuis sa fiche.
            'groups' => Group::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            'memberOf' => $contacts->flatMap(fn (Contact $c) => $c->groups->pluck('id'))->unique()->values(),
            'welcomeTemplates' => MessageTemplate::where('organization_id', $user->organization_id)
                ->sendable()
                ->where('category', 'utility')
                ->orderBy('name')
                ->pluck('name'),
        ]);
    }

    /**
     * Correction de l'identité sur la fiche : le nom saisi est confirmé (il ne
     * sera plus remplacé par celui d'un nouveau contact). Un nouveau numéro
     * s'applique à tous les contacts de la personne ; l'ancien est conservé.
     */
    public function update(Request $request, Customer $customer, CustomerLifecycle $lifecycle): RedirectResponse
    {
        $storeIds = $this->authorizeVisible($request, $customer);

        $editableContacts = $customer->contacts()->whereIn('store_id', $storeIds)->get()
            ->filter(fn (Contact $c) => $request->user()->can('update', $c))
            ->keyBy('id');

        if ($editableContacts->isEmpty()) {
            throw new HttpException(403, "Vous n'avez pas le droit de modifier cette fiche client.");
        }

        $data = $request->validate([
            // Consentement WhatsApp par boutique : { id du contact: statut }.
            'consents' => ['nullable', 'array'],
            'consents.*' => ['in:opt_in,opt_out,unknown'],
            'name' => ['required', 'string', 'max:255'],
            'phone_e164' => ['required', 'string', 'max:20'],
            'gender' => ['nullable', 'in:H,F'],
            'birthdate' => ['nullable', 'date'],
        ]);

        $phone = PhoneNormalizer::normalize($data['phone_e164'])
            ?? throw ValidationException::withMessages(['phone_e164' => 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.']);

        // Un contact hors périmètre ou non modifiable par ce rôle : refus explicite.
        $foreign = array_diff(array_map('intval', array_keys($data['consents'] ?? [])), $editableContacts->keys()->all());

        if ($foreign !== []) {
            throw new HttpException(403, "Vous ne pouvez pas modifier le consentement d'une boutique hors de votre périmètre.");
        }

        DB::transaction(function () use ($customer, $lifecycle, $phone, $data, $editableContacts) {
            $lifecycle->changePhone($customer, $phone);

            $customer->update([
                'name' => $data['name'],
                'gender' => $data['gender'] ?? null,
                'birthdate' => $data['birthdate'] ?? null,
                'identity_confirmed_at' => now(),
            ]);

            // Via le modèle : un passage en opt-in recalcule l'étape (Contact → Prospect).
            foreach ($data['consents'] ?? [] as $contactId => $status) {
                $editableContacts[(int) $contactId]->update(['consent_status' => $status]);
            }
        });

        return back()->with('success', "Fiche client « {$customer->name} » mise à jour.");
    }

    /**
     * Export CSV des fiches clients : une ligne par personne, avec les mêmes
     * filtres que la liste (recherche, marque, boutique, étape, VIP). Les
     * boutiques, consentements et VIP sont limités au périmètre affiché.
     */
    public function export(Request $request): StreamedResponse
    {
        $search = CustomerSearch::fromRequest($request);
        $storeIds = $search->storeIds;
        $scoped = $request->user()->visibleStoreIds() !== null;

        $query = $search->query()
            ->with(['contacts' => fn ($q) => $q->where($search->contactScope())->with('store:id,name')])
            ->orderBy('name');

        $filename = 'fiches-clients-'.now()->format('Y-m-d_His').'.csv';

        return ResponseFacade::streamDownload(function () use ($query, $scoped, $storeIds) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 : accents corrects à l'ouverture dans Excel.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Nom', 'Téléphone', 'Étape', 'Boutiques', 'VIP chez', 'Opt-in chez', 'Achats', 'CA total (FCFA)', 'Dernier achat', 'Anciens numéros']);

            $query->chunk(500, function ($customers) use ($handle, $scoped, $storeIds) {
                $stats = $scoped ? $this->statsFor($customers->pluck('id')->all(), $storeIds) : collect();

                foreach ($customers as $customer) {
                    $own = $stats->get($customer->id);
                    $stores = fn (?callable $filter = null) => $customer->contacts
                        ->filter($filter ?? fn () => true)
                        ->map(fn (Contact $c) => $c->store->name)
                        ->implode(', ');

                    fputcsv($handle, [
                        $customer->name,
                        $customer->phone_e164,
                        $customer->stageLabel(),
                        $stores(),
                        $stores(fn (Contact $c) => $c->is_vip),
                        $stores(fn (Contact $c) => $c->consent_status === 'opt_in'),
                        $scoped ? (int) ($own->purchases ?? 0) : $customer->purchases_count,
                        $scoped ? (int) ($own->spent ?? 0) : (int) $customer->total_spent,
                        $scoped ? (isset($own->last_at) ? substr((string) $own->last_at, 0, 10) : '') : $customer->last_purchase_at?->toDateString(),
                        implode(', ', $customer->previous_phones ?? []),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Aperçu de l'audience d'une campagne lancée depuis la sélection : combien
     * de clients, combien de contacts opt-in recevront le message, depuis
     * quelles boutiques. `all=1` = toutes les fiches du filtre.
     */
    public function audience(Request $request): JsonResponse
    {
        $request->validate([
            'customer_ids' => ['exclude_if:all,1', 'required', 'array', 'min:1'],
            'customer_ids.*' => ['integer'],
        ]);

        $search = CustomerSearch::fromRequest($request);
        $ids = $request->boolean('all') ? null : array_map('intval', $request->input('customer_ids', []));

        $selected = $ids === null ? $search->query()->count() : $search->query()->whereKey($ids)->count();
        $recipients = $search->recipients($ids);

        return response()->json([
            'customers' => $selected,
            'reachable_customers' => (clone $recipients)->distinct()->count('contacts.customer_id'),
            'recipients' => (clone $recipients)->count(),
            'stores' => Store::whereIn('id', (clone $recipients)->select('contacts.store_id'))->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * @return Collection<int, Store>
     */
    private function roleStores(Request $request): Collection
    {
        $user = $request->user();
        $visibleIds = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->with('brand')
            ->orderBy('name')
            ->get();
    }

    /**
     * Compteurs d'achats limités à des boutiques (rôles marque / boutique).
     *
     * @param  array<int, int>  $customerIds
     * @param  array<int, int>  $storeIds
     */
    private function statsFor(array $customerIds, array $storeIds): Collection
    {
        return DB::table('purchases')
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')
            ->whereIn('contacts.customer_id', $customerIds)
            ->whereIn('purchases.store_id', $storeIds)
            ->groupBy('contacts.customer_id')
            ->selectRaw('contacts.customer_id as customer_id, count(*) as purchases, sum(purchases.amount) as spent, max(purchases.purchased_at) as last_at')
            ->get()
            ->keyBy('customer_id');
    }

    /**
     * @return array<int, int> boutiques du périmètre où la personne a un contact
     */
    private function authorizeVisible(Request $request, Customer $customer): array
    {
        $user = $request->user();

        if ($customer->organization_id !== $user->organization_id) {
            throw new HttpException(403, "Cette fiche client n'appartient pas à votre organisation.");
        }

        $storeIds = $this->roleStores($request)->pluck('id')->all();

        if (! $customer->contacts()->whereIn('store_id', $storeIds)->exists()) {
            throw new HttpException(403, "Cette fiche client n'est pas dans votre périmètre.");
        }

        return $storeIds;
    }
}
