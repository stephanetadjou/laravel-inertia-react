<?php

namespace App\Http\Controllers;

use App\Jobs\SendWelcomeMessageJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Store;
use App\Services\PhoneNormalizer;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $contacts = $this->filteredQuery($request)
            ->with(['store', 'groups'])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts->through(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone_e164' => $contact->phone_e164,
                'gender' => $contact->gender,
                'birthdate' => $contact->birthdate?->format('Y-m-d'),
                'consent_status' => $contact->consent_status,
                'store_name' => $contact->store->name,
                'group_names' => $contact->groups->pluck('name')->all(),
            ]),
            'perPage' => $perPage,
            'stores' => $this->visibleStoresQuery($request)->orderBy('name')->get(['id', 'name']),
            'groups' => Group::where('organization_id', $request->user()->organization_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => $request->only(['store_id', 'gender', 'consent_status', 'group_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'phone_e164' => ['required', 'string', 'max:20'],
            'gender' => ['nullable', 'in:H,F'],
            'birthdate' => ['nullable', 'date'],
            'consent_status' => ['required', 'in:opt_in,opt_out,unknown'],
        ]);

        $store = $this->visibleStoresQuery($request)->find($data['store_id']);

        if (! $store) {
            return back()->with('error', 'Boutique invalide.')->withInput();
        }

        $phone = PhoneNormalizer::normalize($data['phone_e164']);

        if (Contact::where('store_id', $store->id)->where('phone_e164', $phone)->exists()) {
            return back()->with('error', "Un contact avec ce numéro existe déjà dans {$store->name}.")->withInput();
        }

        Contact::create([
            'store_id' => $store->id,
            'name' => $data['name'],
            'phone_e164' => $phone,
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'consent_status' => $data['consent_status'],
        ]);

        return back()->with('success', "Contact « {$data['name']} » créé.");
    }

    public function show(Request $request, Contact $contact): Response
    {
        $this->authorizeVisible($request, $contact);

        $contact->load(['store', 'groups', 'purchases' => fn ($q) => $q->orderByDesc('purchased_at')]);
        $contact->load(['campaignMessages.campaign']);

        $lastPurchase = $contact->purchases->first();

        return Inertia::render('Contacts/Show', [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone_e164' => $contact->phone_e164,
                'gender' => $contact->gender,
                'birthdate' => $contact->birthdate?->format('Y-m-d'),
                'consent_status' => $contact->consent_status,
                'store_name' => $contact->store->name,
                'group_names' => $contact->groups->pluck('name')->all(),
            ],
            'rfm' => [
                'recency_days' => $lastPurchase ? (int) abs(now()->diffInDays($lastPurchase->purchased_at)) : null,
                'frequency' => $contact->purchases->count(),
                'monetary' => $contact->purchases->sum('amount'),
            ],
            'purchases' => $contact->purchases->map(fn ($purchase) => [
                'id' => $purchase->id,
                'product_category' => $purchase->product_category,
                'amount' => $purchase->amount,
                'purchased_at' => $purchase->purchased_at->format('d/m/Y'),
            ]),
            'campaignMessages' => $contact->campaignMessages->map(fn ($message) => [
                'id' => $message->id,
                'campaign_name' => $message->campaign->name ?: $message->campaign->template_name,
                'status' => $message->status,
                'sent_at' => $message->updated_at->format('d/m/Y H:i'),
            ]),
        ]);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_e164' => [
                'required', 'string', 'max:20',
                Rule::unique('contacts', 'phone_e164')
                    ->where(fn ($q) => $q->where('store_id', $contact->store_id))
                    ->ignore($contact->id),
            ],
            'gender' => ['nullable', 'in:H,F'],
            'birthdate' => ['nullable', 'date'],
            'consent_status' => ['required', 'in:opt_in,opt_out,unknown'],
        ]);

        $contact->update($data);

        return back()->with('success', "Contact « {$contact->name} » mis à jour.");
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);

        $name = $contact->name;
        $contact->delete();

        return redirect()->route('contacts.index')->with('success', "Contact « {$name} » supprimé.");
    }

    public function export(Request $request): StreamedResponse
    {
        $contacts = $this->filteredQuery($request)->with(['store', 'purchases'])->orderBy('name')->get();

        $filename = 'contacts-'.now()->format('Y-m-d_His').'.csv';

        return ResponseFacade::streamDownload(function () use ($contacts) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 : évite les accents mal affichés à l'ouverture dans Excel.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Nom', 'Téléphone', 'Boutique', 'Sexe', 'Naissance', 'Consentement', 'Nombre achats', 'Total dépensé (FCFA)']);

            foreach ($contacts as $contact) {
                fputcsv($handle, [
                    $contact->name,
                    $contact->phone_e164,
                    $contact->store->name,
                    $contact->gender,
                    $contact->birthdate?->format('Y-m-d'),
                    $contact->consent_status,
                    $contact->purchases->count(),
                    $contact->purchases->sum('amount'),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Message de bienvenue / demande d'opt-in : seule action autorisée à
     * écrire à un contact "unknown", une fois par contact (cf. §4 du cahier
     * des charges). Ne touche jamais un contact opt_out.
     */
    public function sendWelcome(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);

        if ($contact->consent_status !== 'unknown') {
            return back()->with('error', "{$contact->name} n'est pas en attente d'un message de bienvenue.");
        }

        if ($contact->welcome_message_sent_at !== null) {
            return back()->with('error', "Le message de bienvenue a déjà été envoyé à {$contact->name}.");
        }

        $data = $request->validate([
            'template_name' => [
                'required', 'string', 'max:255',
                Rule::exists('message_templates', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id)
                        ->where('is_active', true)
                        ->where('category', 'utility'),
                ),
            ],
        ]);

        SendWelcomeMessageJob::dispatch($contact, $data['template_name']);

        return back()->with('success', "Message de bienvenue en cours d'envoi à {$contact->name}.");
    }

    /**
     * Même règle que sendWelcome(), appliquée à une sélection de contacts
     * (ex. cochés depuis le tableau de bord) : silencieusement ignore tout
     * contact qui n'est pas "unknown" ou déjà accueilli, plutôt que d'échouer
     * l'envoi groupé pour les autres.
     */
    public function sendWelcomeBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer'],
            'template_name' => [
                'required', 'string', 'max:255',
                Rule::exists('message_templates', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id)
                        ->where('is_active', true)
                        ->where('category', 'utility'),
                ),
            ],
        ]);

        $contacts = $this->visibleContactsQuery($request)
            ->whereIn('id', $data['contact_ids'])
            ->where('consent_status', 'unknown')
            ->whereNull('welcome_message_sent_at')
            ->get();

        foreach ($contacts as $contact) {
            SendWelcomeMessageJob::dispatch($contact, $data['template_name']);
        }

        return back()->with('success', "{$contacts->count()} message(s) de bienvenue en cours d'envoi.");
    }

    public function sendTest(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);

        $client = new WhatsAppClient(
            $contact->store->whatsapp_access_token,
            $contact->store->whatsapp_phone_number_id,
        );

        try {
            $client->sendTemplate($contact->phone_e164, 'hello_world', 'en_US');
        } catch (WhatsAppApiException|ConnectionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Message envoyé à {$contact->name} ({$contact->phone_e164}).");
    }

    /**
     * Ajoute en masse les contacts sélectionnés (ou tous ceux correspondant
     * aux filtres actuels si aucune sélection) à un groupe existant.
     */
    public function assignGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer'],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer'],
        ]);

        $group = Group::where('organization_id', $request->user()->organization_id)
            ->findOrFail($data['group_id']);

        $contactIds = $this->visibleContactsQuery($request)
            ->whereIn('id', $data['contact_ids'])
            ->pluck('id');

        $group->contacts()->syncWithoutDetaching($contactIds);

        return back()->with('success', "{$contactIds->count()} contact(s) ajouté(s) au groupe « {$group->name} ».");
    }

    /**
     * @return Builder<Contact>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = $this->visibleContactsQuery($request);

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->integer('store_id'));
        }

        if ($request->filled('gender')) {
            $query->where('gender', $request->string('gender'));
        }

        if ($request->filled('consent_status')) {
            $query->where('consent_status', $request->string('consent_status'));
        }

        if ($request->filled('group_id')) {
            $query->whereHas('groups', fn ($q) => $q->where('groups.id', $request->integer('group_id')));
        }

        return $query;
    }

    /**
     * Contacts de l'organisation, restreints au périmètre (marque/boutique)
     * de l'utilisateur courant s'il n'a pas la vision globale.
     *
     * @return Builder<Contact>
     */
    private function visibleContactsQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = Contact::query()
            ->whereHas('store', fn ($q) => $q->where('organization_id', $user->organization_id));

        $visibleIds = $user->visibleStoreIds();

        if ($visibleIds !== null) {
            $query->whereIn('store_id', $visibleIds);
        }

        return $query;
    }

    /**
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
