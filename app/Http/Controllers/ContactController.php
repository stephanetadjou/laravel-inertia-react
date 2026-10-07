<?php

namespace App\Http\Controllers;

use App\Jobs\SendWelcomeMessageJob;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Group;
use App\Models\MessageTemplate;
use App\Models\Store;
use App\Services\PhoneNormalizer;
use App\Services\WhatsAppApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ContactController extends Controller
{
    /**
     * L'ancienne liste des contacts est fusionnée dans Fiches clients (une
     * ligne par personne, mêmes filtres, ajout aux groupes depuis la sélection).
     * L'URL reste valable et redirige en traduisant ses filtres.
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('customers.index', array_filter([
            'q' => $request->query('q'),
            'store' => $request->query('store_id'),
            'consent' => $request->query('consent_status'),
            'group' => $request->query('group_id'),
        ]));
    }

    /**
     * Ajout d'un contact (bouton « Nouveau client » de Fiches clients) : la
     * fiche client est créée ou complétée automatiquement.
     */
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

        if ($phone === null) {
            return back()->with('error', 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.')->withInput();
        }

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

    /**
     * Ancienne fiche contact : fusionnée dans la fiche client (Profil RFM,
     * boutiques, achats, messages). L'URL reste valable et redirige.
     */
    public function show(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);

        return $contact->customer_id
            ? redirect()->route('customers.show', $contact->customer_id)
            : redirect()->route('customers.index');
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_e164' => ['required', 'string', 'max:20'],
            'gender' => ['nullable', 'in:H,F'],
            'birthdate' => ['nullable', 'date'],
            'consent_status' => ['required', 'in:opt_in,opt_out,unknown'],
        ]);

        // Même normalisation qu'à la création et à l'import : la saisie peut
        // être "062 60 50 54" ou "+241 62605054", on stocke toujours 24162605054.
        $data['phone_e164'] = PhoneNormalizer::normalize($data['phone_e164'])
            ?? throw ValidationException::withMessages([
                'phone_e164' => 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.',
            ]);

        $duplicate = Contact::where('store_id', $contact->store_id)
            ->where('phone_e164', $data['phone_e164'])
            ->whereKeyNot($contact->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'phone_e164' => "Un autre contact de {$contact->store->name} a déjà ce numéro.",
            ]);
        }

        $contact->update($data);

        return back()->with('success', "Contact « {$contact->name} » mis à jour.");
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);

        $name = $contact->name;
        $customerId = $contact->customer_id;
        $contact->delete();

        // Retour à la fiche client, sauf si elle a disparu avec son dernier contact.
        $redirect = $customerId && Customer::whereKey($customerId)->exists()
            ? redirect()->route('customers.show', $customerId)
            : redirect()->route('customers.index');

        return $redirect->with('success', "Contact « {$name} » supprimé.");
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
                        ->where(fn ($q) => $q->whereNull('status')->orWhere('status', MessageTemplate::STATUS_APPROVED))
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
     *
     * Avec all_pending, cible tous les nouveaux clients en attente de bienvenue
     * du périmètre de l'utilisateur (toutes boutiques visibles), éventuellement
     * limités à ceux ajoutés entre from et to — pas seulement les 10 affichés
     * sur le tableau de bord.
     */
    public function sendWelcomeBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'all_pending' => ['sometimes', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'contact_ids' => ['exclude_if:all_pending,true', 'required', 'array', 'min:1'],
            'contact_ids.*' => ['integer'],
            'template_name' => [
                'required', 'string', 'max:255',
                Rule::exists('message_templates', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id)
                        ->where('is_active', true)
                        ->where(fn ($q) => $q->whereNull('status')->orWhere('status', MessageTemplate::STATUS_APPROVED))
                        ->where('category', 'utility'),
                ),
            ],
        ]);

        $query = $this->visibleContactsQuery($request)
            ->where('consent_status', 'unknown')
            ->whereNull('welcome_message_sent_at');

        if ($request->boolean('all_pending')) {
            $query
                ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
                ->when($data['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()));
        } else {
            $query->whereIn('id', $data['contact_ids']);
        }

        $count = 0;

        $query->chunkById(200, function ($contacts) use ($data, &$count) {
            foreach ($contacts as $contact) {
                SendWelcomeMessageJob::dispatch($contact, $data['template_name']);
                $count++;
            }
        });

        return back()->with('success', "{$count} message(s) de bienvenue en cours d'envoi.");
    }

    public function sendTest(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);

        if (! $contact->store->canSendWhatsApp()) {
            return back()->with('error', "Aucun compte WhatsApp configuré pour {$contact->store->name}.");
        }

        $client = $contact->store->whatsappClient();

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

        if ($request->filled('q')) {
            $search = trim((string) $request->string('q'));
            // « 077 11… » (format local) doit trouver 24177 11… : le 0 initial est ignoré.
            $digits = ltrim(preg_replace('/\D+/', '', $search), '0');

            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%");

                if ($digits !== '') {
                    $q->orWhere('phone_e164', 'like', "%{$digits}%");
                }
            });
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
