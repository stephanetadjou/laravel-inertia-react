<?php

namespace App\Http\Controllers;

use App\Models\CampaignMessage;
use App\Models\Contact;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChatController extends Controller
{
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
        $organizationId = $user->organization_id;
        $visibleIds = $user->visibleStoreIds();

        $contacts = Contact::whereHas(
            'store',
            fn ($q) => $q->where('organization_id', $organizationId)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds)),
        )
            ->with(['store', 'campaignMessages' => fn ($q) => $q->with('campaign')->latest('updated_at')])
            ->orderBy('name')
            ->get();

        $contactSummaries = $contacts
            ->map(function (Contact $contact) {
                $lastMessage = $contact->campaignMessages->first();

                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'store_name' => $contact->store->name,
                    'last_message_preview' => $lastMessage ? $this->preview($lastMessage) : null,
                    'last_message_at' => $lastMessage?->updated_at,
                    'last_message_label' => $lastMessage?->updated_at->format('d/m/Y H:i'),
                    'message_count' => $contact->campaignMessages->count(),
                ];
            })
            ->sortByDesc(fn (array $summary) => $summary['last_message_at']?->timestamp ?? -1)
            ->values()
            ->map(fn (array $summary) => collect($summary)->except('last_message_at')->all());

        $messages = collect();

        if ($selected) {
            $selected->loadMissing(['campaignMessages' => fn ($q) => $q->with('campaign')->oldest('created_at')]);

            $messages = $selected->campaignMessages->map(fn (CampaignMessage $message) => [
                'id' => $message->id,
                'body' => $this->preview($message),
                'status' => $message->status,
                'error_message' => $message->error_message,
                'sent_at' => $message->updated_at->format('d/m/Y H:i'),
            ]);
        }

        return Inertia::render('Chat/Index', [
            'contacts' => $contactSummaries,
            'selectedContact' => $selected ? [
                'id' => $selected->id,
                'name' => $selected->name,
                'phone_e164' => $selected->phone_e164,
                'store_name' => $selected->store->name,
            ] : null,
            'messages' => $messages,
        ]);
    }

    private function preview(CampaignMessage $message): string
    {
        $campaign = $message->campaign;

        if (! $campaign) {
            return '(campagne supprimée)';
        }

        return $campaign->message_type === 'text'
            ? $campaign->text_body
            : "Template WhatsApp : {$campaign->template_name}";
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
