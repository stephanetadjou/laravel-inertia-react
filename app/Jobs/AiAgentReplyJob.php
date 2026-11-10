<?php

namespace App\Jobs;

use App\Models\AiAgent;
use App\Models\Contact;
use App\Services\AiAgents;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Réponse d'un agent IA à un contact, en file d'attente (jamais pendant le
 * webhook). Un seul travail en attente par contact : plusieurs messages
 * envoyés à la suite reçoivent une seule réponse, qui tient compte de tous.
 */
class AiAgentReplyJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $agentId, public int $contactId) {}

    public function uniqueId(): string
    {
        return (string) $this->contactId;
    }

    public function handle(): void
    {
        $agent = AiAgent::find($this->agentId);
        $contact = Contact::with('store.organization', 'customer')->find($this->contactId);

        if ($agent === null || ! $agent->active || $contact === null) {
            return;
        }

        // Discussion reprise par l'équipe entre-temps.
        if ($contact->bot_paused_until !== null && $contact->bot_paused_until->isFuture()) {
            return;
        }

        AiAgents::respond($agent, $contact);
    }
}
