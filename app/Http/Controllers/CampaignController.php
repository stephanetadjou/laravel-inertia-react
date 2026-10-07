<?php

namespace App\Http\Controllers;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\MessageTemplate;
use App\Models\Store;
use App\Services\ContactImporter;
use App\Services\CustomerSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    private const STATUSES = ['draft', 'queued', 'sending', 'completed'];

    private const PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * Liste des campagnes du périmètre, filtrable en query string : recherche
     * (nom, modèle), statut, type de message, boutique et période de création.
     * Les chiffres en haut de page suivent les mêmes filtres.
     */
    public function index(Request $request): Response
    {
        $visibleIds = $request->user()->visibleStoreIds();
        $stores = $this->visibleStoresQuery($request)->orderBy('name')->get(['id', 'name']);

        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : null;
        $type = in_array($request->query('type'), ['template', 'text'], true) ? (string) $request->query('type') : null;
        $storeId = $stores->contains('id', $request->integer('store')) ? $request->integer('store') : null;
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : null;

        $filtered = Campaign::whereHas(
            'stores',
            fn ($q) => $q->where('organization_id', $request->user()->organization_id)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('stores.id', $visibleIds))
                ->when($storeId !== null, fn ($q) => $q->where('stores.id', $storeId)),
        )
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('template_name', 'like', "%{$search}%")))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($type !== null, fn ($q) => $q->where('message_type', $type))
            ->when($period !== null, fn ($q) => $q->where('created_at', '>=', now()->subDays(self::PERIODS[$period] - 1)->startOfDay()));

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $campaigns = (clone $filtered)
            ->with('stores')
            ->withCount('campaignMessages as total')
            ->latest()
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $groupNames = Group::where('organization_id', $request->user()->organization_id)->pluck('name', 'id');
        $totalRecipients = CampaignMessage::whereIn('campaign_id', (clone $filtered)->select('id'))->count();
        $totalSent = (int) (clone $filtered)->sum('sent_count');
        $totalFailed = (int) (clone $filtered)->sum('failed_count');

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns->through(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name ?: $campaign->template_name,
                'store_names' => $campaign->stores->pluck('name')->implode(', '),
                'message_type' => $campaign->message_type === 'text' ? 'text' : 'template',
                'template_name' => $campaign->message_type === 'text' ? null : $campaign->template_name,
                'group_names' => collect($campaign->segment_filters['group_ids'] ?? [])->map(fn ($id) => $groupNames[$id] ?? null)->filter()->values(),
                'status' => $campaign->status,
                'sent_count' => $campaign->sent_count,
                'delivered_count' => $campaign->delivered_count,
                'read_count' => $campaign->read_count,
                'failed_count' => $campaign->failed_count,
                'total' => $campaign->total,
                'created_at' => $campaign->created_at->toIso8601String(),
            ]),
            'stats' => [
                'total_campaigns' => (clone $filtered)->count(),
                'active_campaigns' => (clone $filtered)->whereIn('status', ['queued', 'sending'])->count(),
                'total_recipients' => $totalRecipients,
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'delivery_rate' => $totalRecipients > 0 ? round($totalSent / $totalRecipients * 100) : 0,
                'failure_rate' => $totalRecipients > 0 ? round($totalFailed / $totalRecipients * 100) : 0,
            ],
            'filters' => [
                'q' => $search,
                'status' => $status,
                'type' => $type,
                'store' => $storeId,
                'period' => $period,
                'per_page' => $perPage,
            ],
            'stores' => $stores,
            'groups' => Group::where('organization_id', $request->user()->organization_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'templates' => MessageTemplate::where('organization_id', $request->user()->organization_id)
                ->sendable()
                ->orderBy('name')
                ->get(['id', 'name', 'language']),
        ]);
    }

    /**
     * Aperçu en direct du nombre de contacts correspondant au segment choisi,
     * pendant que l'admin compose la campagne (avant tout envoi).
     */
    public function segmentCount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['integer'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
            'gender' => ['nullable', 'in:H,F'],
            'lifecycle_stages' => ['nullable', 'array'],
            'lifecycle_stages.*' => [Rule::in(array_keys(Customer::STAGES))],
            'vip_only' => ['nullable', 'boolean'],
        ]);

        $stores = $this->visibleStoresQuery($request)->whereIn('id', $data['store_ids'])->get();

        $count = $this->segmentQuery($stores, $data)->count();

        return response()->json(['count' => $count]);
    }

    public function store(Request $request, ContactImporter $importer): RedirectResponse
    {
        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contact_source' => ['required', 'in:segment,file,customers'],

            // Depuis la page Fiches clients : sélection (ou tout le filtre) + filtres de la page.
            'all_customers' => ['nullable', 'boolean'],
            'customer_ids' => ['exclude_unless:contact_source,customers', 'exclude_if:all_customers,1', 'required', 'array', 'min:1'],
            'customer_ids.*' => ['integer'],
            'filters' => ['nullable', 'array'],

            'store_ids' => ['required_if:contact_source,segment', 'array', 'min:1'],
            'store_ids.*' => ['integer'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
            'gender' => ['nullable', 'in:H,F'],
            'lifecycle_stages' => ['nullable', 'array'],
            'lifecycle_stages.*' => [Rule::in(array_keys(Customer::STAGES))],
            'vip_only' => ['nullable', 'boolean'],

            'import_store_id' => ['required_if:contact_source,file', 'nullable', 'integer'],
            'file' => ['required_if:contact_source,file', 'nullable', 'file', 'mimes:csv,txt,xlsx,xls'],
            'confirm_consent' => ['nullable', 'boolean'],

            'message_type' => ['required', 'in:template,text'],
            'template_name' => [
                'required_if:message_type,template', 'nullable', 'string', 'max:255',
                Rule::exists('message_templates', 'name')->where(
                    fn ($q) => $q->where('organization_id', $organizationId)->where('is_active', true)
                        ->where(fn ($q) => $q->whereNull('status')->orWhere('status', MessageTemplate::STATUS_APPROVED)),
                ),
            ],
            'text_body' => ['required_if:message_type,text', 'nullable', 'string', 'max:4096'],
        ]);

        $importSummary = null;

        if ($data['contact_source'] === 'customers') {
            // Destinataires = contacts des fiches sélectionnées qui correspondent
            // aux filtres de la page (boutique, VIP…) ET sont opt-in.
            $search = new CustomerSearch($request->user(), $data['filters'] ?? []);
            $customerIds = $request->boolean('all_customers') ? null : array_map('intval', $data['customer_ids']);
            $recipients = $search->recipients($customerIds)->get(['contacts.id', 'contacts.store_id', 'contacts.customer_id']);

            $contactIds = $recipients->pluck('id');
            $stores = Store::whereIn('id', $recipients->pluck('store_id')->unique())->get();
            $filters = [
                'source' => 'customers',
                'label' => $search->label(),
                'customers' => $recipients->pluck('customer_id')->unique()->count(),
                'filters' => array_filter($data['filters'] ?? [], fn ($v) => $v !== null && $v !== ''),
            ];
        } elseif ($data['contact_source'] === 'file') {
            if (! $request->boolean('confirm_consent')) {
                return back()->with('error', 'Vous devez confirmer le consentement (opt-in) des contacts importés avant de lancer la campagne.');
            }

            $store = $this->visibleStoresQuery($request)->find($data['import_store_id']);

            if (! $store) {
                return back()->with('error', 'Boutique invalide pour l\'import.');
            }

            $importSummary = $importer->import($request->file('file'), $store);
            $importedIds = collect($importSummary['contact_ids'])->unique()->values();

            // Consentement déclaré au moment de l'import (admin coche la confirmation) :
            // ne jamais écraser un contact déjà explicitement désinscrit (opt_out).
            Contact::whereIn('id', $importedIds)
                ->where('consent_status', 'unknown')
                ->update(['consent_status' => 'opt_in']);

            $stores = collect([$store]);
            $contactIds = Contact::whereIn('id', $importedIds)->where('consent_status', 'opt_in')->pluck('id');
            $filters = ['source' => 'file'];
        } else {
            $stores = $this->visibleStoresQuery($request)->whereIn('id', $data['store_ids'] ?? [])->get();

            if ($stores->isEmpty()) {
                return back()->with('error', 'Aucune boutique valide sélectionnée.');
            }

            $contactIds = $this->segmentQuery($stores, $data)->pluck('id');
            $filters = array_filter([
                'gender' => $data['gender'] ?? null,
                'group_ids' => $data['group_ids'] ?? null,
                'lifecycle_stages' => $data['lifecycle_stages'] ?? null,
                'vip_only' => (bool) ($data['vip_only'] ?? false),
            ]);
        }

        if ($contactIds->isEmpty()) {
            return back()->with('error', 'Aucun contact opt_in ne correspond à ce segment (consentement WhatsApp obligatoire avant tout envoi).');
        }

        $campaign = Campaign::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'message_type' => $data['message_type'],
            'template_name' => $data['message_type'] === 'template' ? $data['template_name'] : null,
            'text_body' => $data['message_type'] === 'text' ? $data['text_body'] : null,
            'segment_filters' => $filters,
            'status' => 'queued',
        ]);

        $campaign->stores()->attach($stores->pluck('id'));

        $now = now();
        $campaign->campaignMessages()->insert(
            $contactIds->map(fn (int $contactId) => [
                'campaign_id' => $campaign->id,
                'contact_id' => $contactId,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );

        SendCampaignJob::dispatch($campaign);

        $storeNames = $stores->pluck('name')->implode(', ');
        $message = "Campagne « {$campaign->name} » lancée pour {$contactIds->count()} contact(s) sur {$storeNames}. L'envoi se fait en arrière-plan.";

        if ($importSummary) {
            $message .= sprintf(
                ' Import : %d créé(s), %d mis à jour%s.',
                $importSummary['contacts_created'],
                $importSummary['contacts_updated'],
                ! empty($importSummary['skipped']) ? ', '.count($importSummary['skipped']).' ligne(s) ignorée(s)' : '',
            );
        }

        return redirect()->route('campaigns.index')->with('success', $message);
    }

    /**
     * Segment ciblé par une campagne. Le consentement opt_in est une exclusion
     * automatique et non négociable (cahier des charges §3.4/3.6/§4 — exclusion
     * systématique des non opt-in et désinscrits avant tout envoi hors fenêtre
     * de service), jamais un filtre optionnel laissé au choix de l'admin.
     *
     * @param  Collection<int, Store>  $stores
     *                                          L'étape du parcours client (fiche client du Groupe) s'ajoute aux autres
     *                                          critères, jamais à la place de l'opt-in.
     * @param  array{gender?: string|null, group_ids?: array<int>|null, lifecycle_stages?: array<int, string>|null, vip_only?: bool|null}  $filters
     * @return Builder<Contact>
     */
    private function segmentQuery(Collection $stores, array $filters): Builder
    {
        $query = Contact::whereIn('store_id', $stores->pluck('id'))
            ->where('consent_status', 'opt_in');

        if (! empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        if (! empty($filters['lifecycle_stages'])) {
            $query->whereHas('customer', fn ($q) => $q->whereIn('lifecycle_stage', $filters['lifecycle_stages']));
        }

        // VIP de la boutique du contact (statut propre à chaque boutique).
        if (! empty($filters['vip_only'])) {
            $query->where('is_vip', true);
        }

        if (! empty($filters['group_ids'])) {
            $query->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $filters['group_ids']));
        }

        return $query;
    }

    /**
     * Boutiques de l'organisation, restreintes au périmètre (marque/boutique)
     * de l'utilisateur courant s'il n'a pas la vision globale.
     *
     * @return Builder<Store>
     */
    private function visibleStoresQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = Store::where('organization_id', $user->organization_id);

        $visibleIds = $user->visibleStoreIds();

        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        return $query;
    }
}
