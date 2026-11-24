<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Store;
use App\Models\WhatsAppInboundMessage;
use App\Services\AutoReplies;
use App\Services\VoiceNotes;
use App\Services\WhatsAppApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Note vocale reçue : transcription, texte enregistré dans Discussions, puis
 * réponse comme à un message écrit. Sans transcription possible, le client
 * est invité à écrire.
 */
class TranscribeVoiceNoteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 1;

    /** @param  array<string, mixed>  $message  message entrant du webhook (type audio) */
    public function __construct(public int $storeId, public int $contactId, public string $waMessageId, public array $message) {}

    public function handle(): void
    {
        $store = Store::with('organization')->find($this->storeId);
        $contact = Contact::with('store.organization', 'customer')->find($this->contactId);
        if ($store === null || $contact === null) {
            return;
        }

        $result = null;
        $mediaId = (string) ($this->message['audio']['id'] ?? '');

        if ($mediaId !== '') {
            try {
                $result = VoiceNotes::transcribe($store, $mediaId);
            } catch (WhatsAppApiException|RuntimeException $e) {
                Log::warning('Note vocale : transcription impossible', ['store_id' => $store->id, 'error' => $e->getMessage()]);
            }
        }

        if ($result === null) {
            if ($contact->bot_paused_until === null || $contact->bot_paused_until->isPast()) {
                VoiceNotes::askToWrite($contact);
            }

            return;
        }

        WhatsAppInboundMessage::where('wa_message_id', $this->waMessageId)->update(['body' => $result['text']]);

        AutoReplies::handle($contact, ['type' => 'text', 'text' => ['body' => $result['text']], 'voice' => true, 'id' => $this->waMessageId]);
    }
}
