<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Assistant;
use App\Support\Locale;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Réponse de l'Assistant IA ClientlyX à un contact, en file d'attente. Un
 * seul travail en attente par contact : plusieurs messages à la suite
 * reçoivent une seule réponse.
 */
class AssistantReplyJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $contactId) {}

    public function uniqueId(): string
    {
        return 'assistant-'.$this->contactId;
    }

    public function handle(): void
    {
        $contact = Contact::with('store.organization', 'customer')->find($this->contactId);

        // Réponse dans la langue de l'entreprise.
        Locale::apply($contact?->store?->organization?->locale ?? Locale::DEFAULT);

        if ($contact === null || ! Assistant::active($contact)) {
            return;
        }

        if ($contact->bot_paused_until !== null && $contact->bot_paused_until->isFuture()) {
            return;
        }

        Assistant::respond($contact);
    }
}
