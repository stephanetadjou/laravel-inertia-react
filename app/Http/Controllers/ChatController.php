<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBrandFilter;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Historique des messages WhatsApp envoyés par l'application.
 *
 * Le journal réunit les deux traces d'envoi qui existent en base :
 * - les messages de campagne (campaign_messages, statut sent/failed et
 *   message d'erreur de l'API Meta) ;
 * - les messages de bienvenue (contacts.welcome_message_sent_at : seule la
 *   date d'un envoi réussi est conservée, ni le modèle ni les échecs).
 * Les envois de test (bouton « Envoyer un test ») ne sont pas enregistrés.
 *
 * Tout est filtré côté serveur (période, marque/boutique, type, recherche)
 * et paginé, dans le périmètre du rôle de l'utilisateur.
 */
class ChatController extends Controller
{
    use ResolvesBrandFilter;

    // « all » : tout l'historique (lien « Tout voir » de la fiche client).
    private const PERIOD_PRESETS = ['today' => 1, '7d' => 7, '30d' => 30, 'all' => 36500];

    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Contact $contact): Response
    {
        $this->authorizeVisible($request, $contact);

        return $this->render($request, $contact);
    }

    private function render(Request $request, ?Contact $selected): Response
    {
        $user = $request->user();
        $visibleIds = $user->visibleStoreIds();

        $preset = array_key_exists((string) $request->query('period'), self::PERIOD_PRESETS)
            ? (string) $request->query('period')
            : '7d';
        $from = now()->subDays(self::PERIOD_PRESETS[$preset] - 1)->startOfDay();
        $to = now()->endOfDay();

        $brandFilter = $this->resolveBrandFilter($request, $user, $visibleIds);

        $roleStores = Store::where('organization_id', $user->organization_id)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->with('brand')
            ->orderBy('name')
            ->get();

        $brandStores = $brandFilter['selected'] !== null
            ? $roleStores->where('brand_id', $brandFilter['selected'])->values()
            : $roleStores;

        // Une boutique hors du périmètre (ou hors de la marque choisie) est ignorée.
        $storeId = $request->integer('store') ?: null;
        $storeId = $brandFilter['hidden'] || ! $brandStores->contains('id', $storeId) ? null : $storeId;

        $storeIds = $storeId !== null ? [$storeId] : $brandStores->pluck('id')->all();

        $type = in_array($request->query('type'), ['campaign', 'welcome'], true) ? (string) $request->query('type') : null;
        $search = trim((string) $request->query('q', ''));
        $tab = $request->query('tab') === 'failed' ? 'failed' : 'all';

        $log = fn (bool $failedOnly) => DB::query()->fromSub(
            $this->logQuery($storeIds, $from, $to, $type, $search, $failedOnly),
            'log',
        );

        $counts = $log(false)
            ->selectRaw('kind, status, count(*) as total')
            ->groupBy('kind', 'status')
            ->get();
        $count = fn (?string $kind, string $status) => (int) $counts
            ->when($kind !== null, fn ($c) => $c->where('kind', $kind))
            ->where('status', $status)
            ->sum('total');

        $sent = $count(null, 'sent');
        $failed = $count(null, 'failed');
        $campaignAttempts = $count('campaign', 'sent') + $failed;

        $page = $log($tab === 'failed')
            ->orderByDesc('sent_at')
            ->orderByDesc('ref_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows = collect($page->items());
        $contacts = Contact::with('store.brand')->whereIn('id', $rows->pluck('contact_id')->unique())->get()->keyBy('id');
        $campaigns = Campaign::whereIn('id', $rows->pluck('campaign_id')->filter()->unique())->get()->keyBy('id');

        $page->setCollection($rows->map(fn ($row) => $this->formatRow($row, $contacts, $campaigns)));

        // Sans contact demandé, le panneau affiche le client du premier message.
        $selected ??= $contacts->get($rows->first()?->contact_id);

        return Inertia::render('Chat/Index', [
            'filters' => [
                'period' => $preset,
                'brand' => $brandFilter['selected'],
                'brand_locked' => $brandFilter['locked'],
                'brand_hidden' => $brandFilter['hidden'],
                'store' => $storeId,
                'type' => $type,
                'q' => $search,
                'tab' => $tab,
            ],
            'storeOptions' => $this->storeOptions($roleStores),
            'stats' => [
                'sent' => $sent,
                'failed' => $failed,
                'failure_rate' => $campaignAttempts > 0 ? round($failed / $campaignAttempts * 100, 1) : 0,
                'clients' => $log(false)->where('status', 'sent')->distinct()->count('contact_id'),
                'total' => $sent + $failed,
            ],
            'messages' => $page,
            'selectedContact' => $selected ? $this->contactPanel($selected) : null,
        ]);
    }

    /**
     * Union des messages de campagne et des messages de bienvenue, avec des
     * colonnes communes : kind, ref_id, contact_id, campaign_id, status,
     * error_message, sent_at.
     *
     * @param  array<int, int>  $storeIds
     */
    private function logQuery(array $storeIds, Carbon $from, Carbon $to, ?string $type, string $search, bool $failedOnly): Builder
    {
        $matchesSearch = function (Builder $query) use ($search) {
            if ($search === '') {
                return;
            }

            $digits = ltrim(preg_replace('/\D+/', '', $search), '0'); // 0 du format local ignoré

            $query->where(function (Builder $q) use ($search, $digits) {
                $q->where('contacts.name', 'like', "%{$search}%");

                if ($digits !== '') {
                    $q->orWhere('contacts.phone_e164', 'like', "%{$digits}%");
                }
            });
        };

        $campaignPart = DB::table('campaign_messages')
            ->join('contacts', 'contacts.id', '=', 'campaign_messages.contact_id')
            ->whereIn('contacts.store_id', $storeIds)
            ->whereIn('campaign_messages.status', $failedOnly ? ['failed'] : ['sent', 'failed'])
            ->whereBetween('campaign_messages.updated_at', [$from, $to])
            ->tap($matchesSearch)
            ->selectRaw("'campaign' as kind, campaign_messages.id as ref_id, campaign_messages.contact_id as contact_id, campaign_messages.campaign_id as campaign_id, campaign_messages.status as status, campaign_messages.error_message as error_message, campaign_messages.updated_at as sent_at");

        $welcomePart = DB::table('contacts')
            ->whereIn('contacts.store_id', $storeIds)
            ->whereBetween('contacts.welcome_message_sent_at', [$from, $to])
            ->tap($matchesSearch)
            ->selectRaw("'welcome' as kind, contacts.id as ref_id, contacts.id as contact_id, null as campaign_id, 'sent' as status, null as error_message, contacts.welcome_message_sent_at as sent_at");

        // Les échecs de bienvenue ne sont pas enregistrés : l'onglet « Échecs »
        // et le filtre « Campagne » ne lisent que les messages de campagne.
        if ($failedOnly || $type === 'campaign') {
            return $campaignPart;
        }

        if ($type === 'welcome') {
            return $welcomePart;
        }

        return $campaignPart->unionAll($welcomePart);
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<string, mixed>
     */
    private function formatRow(object $row, Collection $contacts, Collection $campaigns): array
    {
        $contact = $contacts->get($row->contact_id);
        $campaign = $row->campaign_id ? $campaigns->get($row->campaign_id) : null;

        return [
            'key' => "{$row->kind}-{$row->ref_id}",
            'kind' => $row->kind,
            'sent_at' => Carbon::parse($row->sent_at)->toIso8601String(),
            'status' => $row->status,
            'error_message' => $row->error_message,
            'contact' => [
                'id' => $contact?->id,
                'name' => $contact?->name,
                'phone_e164' => $contact?->phone_e164,
                'store_name' => $contact?->store->name,
                'brand_name' => $contact?->store->brand?->name,
            ],
            'campaign' => $row->kind === 'campaign' ? [
                'id' => $campaign?->id,
                'name' => $campaign ? ($campaign->name ?: $campaign->template_name) : 'Campagne supprimée',
                'template' => $campaign ? ($campaign->message_type === 'text' ? null : $campaign->template_name) : null,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, Store>  $roleStores
     */
    private function storeOptions(Collection $roleStores): Collection
    {
        return $roleStores
            ->groupBy(fn (Store $store) => $store->brand_id ?? 0)
            ->map(fn (Collection $stores) => [
                'brand' => $stores->first()->brand ? ['id' => $stores->first()->brand->id, 'name' => $stores->first()->brand->name] : null,
                'stores' => $stores->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])->values(),
            ])
            ->sortBy(fn (array $group) => $group['brand']['name'] ?? '')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPanel(Contact $contact): array
    {
        $contact->loadMissing('store');

        $messages = CampaignMessage::with('campaign')
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['sent', 'failed'])
            ->get()
            ->map(fn (CampaignMessage $message) => [
                'key' => "campaign-{$message->id}",
                'kind' => 'campaign',
                'body' => $message->campaign?->message_type === 'text' ? $message->campaign->text_body : null,
                'label' => $message->campaign ? ($message->campaign->name ?: $message->campaign->template_name) : 'Campagne supprimée',
                'template' => $message->campaign?->message_type === 'text' ? null : $message->campaign?->template_name,
                'status' => $message->status,
                'error_message' => $message->error_message,
                'sent_at' => $message->updated_at,
            ]);

        if ($contact->welcome_message_sent_at) {
            $messages->push([
                'key' => "welcome-{$contact->id}",
                'kind' => 'welcome',
                'body' => null,
                'label' => 'Message de bienvenue',
                'template' => null,
                'status' => 'sent',
                'error_message' => null,
                'sent_at' => $contact->welcome_message_sent_at,
            ]);
        }

        $lastPurchase = $contact->purchases()->latest('purchased_at')->latest('id')->first();

        return [
            'id' => $contact->id,
            'customer_id' => $contact->customer_id,
            'name' => $contact->name,
            'phone_e164' => $contact->phone_e164,
            'store_name' => $contact->store->name,
            'consent_status' => $contact->consent_status,
            'message_count' => $messages->count(),
            'last_purchase' => $lastPurchase ? [
                'date' => $lastPurchase->purchased_at->toDateString(),
                'amount' => (int) $lastPurchase->amount,
            ] : null,
            'timeline' => $messages
                ->sortBy('sent_at')
                ->take(-50)
                ->values()
                ->map(fn (array $message) => [...$message, 'sent_at' => $message['sent_at']->toIso8601String()]),
        ];
    }

    private function authorizeVisible(Request $request, Contact $contact): void
    {
        $user = $request->user();

        if ($contact->store->organization_id !== $user->organization_id) {
            throw new HttpException(403, 'Ce contact n\'appartient pas à votre organisation.');
        }

        $visibleIds = $user->visibleStoreIds();

        if ($visibleIds !== null && ! in_array($contact->store_id, $visibleIds, true)) {
            throw new HttpException(403, 'Ce contact n\'est pas dans votre périmètre.');
        }
    }
}
