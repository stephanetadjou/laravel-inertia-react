<?php

namespace App\Http\Controllers;

use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppOutboundMessage;
use App\Services\ActionLists;
use App\Services\CustomerLifecycle;
use App\Services\WhatsAppConversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Discussions : boîte de réception WhatsApp partagée (sur ordinateur).
 * Liste des contacts qui ont écrit, le fil (messages reçus, réponses,
 * réponses automatiques, campagnes), une réponse en texte libre tant que la
 * fenêtre de 24 h est ouverte (sinon un modèle approuvé), l'attribution à un
 * utilisateur et le filtre « À traiter ».
 */
class ConversationController extends Controller
{
    private const PER_PAGE = 60;

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
        $filter = in_array($request->query('filter'), ['todo', 'mine'], true) ? (string) $request->query('filter') : 'all';
        $q = trim((string) $request->query('q', ''));

        $contacts = $this->scope($user)
            ->where(fn ($w) => $w->whereNotNull('last_inbound_at')->orWhereHas('outboundMessages'))
            ->when($filter === 'todo', fn ($w) => $w->where('needs_attention', true))
            ->when($filter === 'mine', fn ($w) => $w->where('assigned_user_id', $user->id))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('phone_e164', 'like', '%'.preg_replace('/\D/', '', $q).'%')))
            ->with(['store:id,name', 'customer:id,name,acquisition_source', 'assignee:id,name'])
            ->orderByDesc('last_inbound_at')
            ->limit(self::PER_PAGE)
            ->get();

        $apiEnabled = $user->organization->usesWhatsAppApi();

        return Inertia::render('Discussions/Index', [
            'apiEnabled' => $apiEnabled,
            'filters' => ['filter' => $filter, 'q' => $q],
            'counts' => ['todo' => $this->scope($user)->where('needs_attention', true)->count()],
            'conversations' => $contacts->map(fn (Contact $c) => [
                'id' => $c->id,
                'name' => $this->displayName($c),
                'phone' => '+'.$c->phone_e164,
                'store' => $c->store?->name,
                'source' => $c->customer?->sourceLabel(),
                'needs_attention' => $c->needs_attention,
                'assignee' => $c->assignee?->name,
                'last' => $this->lastMessage($c),
                'last_at' => $c->last_inbound_at?->toIso8601String(),
            ])->values(),
            'selected' => $selected ? $this->thread($selected) : null,
            'users' => User::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            'templates' => $selected ? MessageTemplate::where('organization_id', $user->organization_id)->sendable()->orderBy('name')
                ->get(['id', 'name', 'language', 'category', 'body_text', 'body_variables']) : [],
        ]);
    }

    public function reply(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);
        $data = $request->validate([
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:4096'],
            'template_id' => ['nullable', 'integer'],
        ]);

        if (! $request->user()->organization->usesWhatsAppApi()) {
            return back()->with('error', 'Connectez d’abord le numéro WhatsApp de l’établissement (Paramètres › WhatsApp).');
        }

        if (! empty($data['template_id'])) {
            $template = MessageTemplate::where('organization_id', $request->user()->organization_id)->sendable()->findOrFail($data['template_id']);
            // Variables du modèle : le prénom du client pour {{1}}, rien d'autre à deviner.
            $first = ActionLists::firstNameOf($this->displayName($contact));
            $vars = array_fill(0, (int) $template->body_variables, $first);
            $message = WhatsAppConversation::template($contact, $template->name, $template->language, $vars, [], 'manual', $request->user(), $template->body_text);
        } else {
            if (! WhatsAppConversation::windowOpen($contact)) {
                return back()->with('error', 'Le client n’a pas écrit depuis plus de 24 h : WhatsApp impose un modèle de message approuvé.');
            }
            $message = WhatsAppConversation::text($contact, trim((string) $data['body']), 'manual', $request->user());
        }

        // Une réponse humaine relance le robot plus tard ; la discussion reste « à traiter » jusqu'au bouton « Traité ».
        return $message->status === 'sent'
            ? back()->with('success', 'Message envoyé.')
            : back()->with('error', 'Envoi impossible : '.$message->error);
    }

    public function assign(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $request->user()->organization_id)],
        ]);

        $contact->forceFill(['assigned_user_id' => $data['user_id'] ?? null])->saveQuietly();
        Task::whereNull('done_at')->where('contact_id', $contact->id)->update(['assigned_user_id' => $data['user_id'] ?? null]);

        return back()->with('success', 'Discussion attribuée.');
    }

    /** « Traité » : la discussion quitte « À traiter », ses tâches sont faites, le robot reprend. */
    public function resolve(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeVisible($request, $contact);
        $contact->forceFill(['needs_attention' => false, 'bot_paused_until' => null])->saveQuietly();
        Task::whereNull('done_at')->where('contact_id', $contact->id)->whereIn('kind', ['talk_to_human', 'callback'])->update(['done_at' => now()]);

        return back()->with('success', 'Discussion traitée.');
    }

    /** Fil d'un contact : reçus, envoyés (manuels, robot, rappels) et campagnes. */
    private function thread(Contact $contact): array
    {
        $in = WhatsAppInboundMessage::where('contact_id', $contact->id)->latest('received_at')->limit(100)->get()
            ->map(fn ($m) => ['id' => 'in-'.$m->id, 'direction' => 'in', 'body' => $m->body ?? '['.$m->type.']', 'at' => $m->received_at, 'origin' => null, 'status' => null, 'author' => null]);
        $out = WhatsAppOutboundMessage::where('contact_id', $contact->id)->with('user:id,name')->latest('sent_at')->limit(100)->get()
            ->map(fn ($m) => ['id' => 'out-'.$m->id, 'direction' => 'out', 'body' => $m->body, 'at' => $m->sent_at, 'origin' => $m->origin, 'status' => $m->status, 'author' => $m->user?->name]);
        $campaigns = CampaignMessage::where('contact_id', $contact->id)->whereNotNull('sent_at')->with('campaign:id,name')->latest('sent_at')->limit(30)->get()
            ->map(fn ($m) => ['id' => 'cmp-'.$m->id, 'direction' => 'out', 'body' => 'Campagne « '.($m->campaign?->name ?? '?').' »', 'at' => $m->sent_at, 'origin' => 'campaign', 'status' => $m->status, 'author' => null]);

        $contact->loadMissing(['store:id,name', 'customer', 'assignee:id,name']);

        return [
            'id' => $contact->id,
            'customer_id' => $contact->customer_id,
            'name' => $this->displayName($contact),
            'phone' => '+'.$contact->phone_e164,
            'store' => $contact->store?->name,
            'source' => $contact->customer?->sourceLabel(),
            'source_headline' => $contact->customer?->acquisition_data['headline'] ?? null,
            'prospect_stage' => $contact->customer?->prospect_stage,
            'needs_attention' => $contact->needs_attention,
            'assigned_user_id' => $contact->assigned_user_id,
            'bot_paused' => $contact->bot_paused_until?->isFuture() ?? false,
            'window_open' => WhatsAppConversation::windowOpen($contact),
            'window_ends_at' => $contact->last_inbound_at?->copy()->addDay()->toIso8601String(),
            'messages' => $in->concat($out)->concat($campaigns)->sortBy('at')->values()
                ->map(fn ($m) => [...$m, 'at' => $m['at']?->toIso8601String()]),
        ];
    }

    private function lastMessage(Contact $contact): ?string
    {
        $in = WhatsAppInboundMessage::where('contact_id', $contact->id)->latest('received_at')->first(['body', 'type', 'received_at']);
        $out = WhatsAppOutboundMessage::where('contact_id', $contact->id)->latest('sent_at')->first(['body', 'sent_at']);

        if ($out && (! $in || $out->sent_at->gt($in->received_at))) {
            return 'Vous : '.mb_strimwidth((string) $out->body, 0, 70, '…');
        }

        return $in ? mb_strimwidth((string) ($in->body ?? '['.$in->type.']'), 0, 70, '…') : null;
    }

    private function displayName(Contact $contact): string
    {
        $name = $contact->customer?->name ?? $contact->name;

        return CustomerLifecycle::isPlaceholderName($name) ? '+'.$contact->phone_e164 : (string) $name;
    }

    private function scope(User $user)
    {
        $visible = $user->visibleStoreIds();

        return Contact::whereHas('store', fn ($q) => $q->where('organization_id', $user->organization_id))
            ->when($visible !== null, fn ($q) => $q->whereIn('store_id', $visible));
    }

    private function authorizeVisible(Request $request, Contact $contact): void
    {
        $user = $request->user();
        $visible = $user->visibleStoreIds();

        if ($contact->store->organization_id !== $user->organization_id || ($visible !== null && ! in_array($contact->store_id, $visible, true))) {
            throw new HttpException(403, 'Cette discussion n’est pas dans votre périmètre.');
        }
    }
}
