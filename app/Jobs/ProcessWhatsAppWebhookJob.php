<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Store;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Traite un appel du webhook Meta, déjà authentifié par sa signature.
 *
 * Meta peut livrer plusieurs fois le même événement : chaque message reçu
 * n'est enregistré qu'une fois, grâce à l'index unique sur son identifiant
 * WhatsApp (wamid). Un même webhook d'application peut recevoir les
 * événements de plusieurs numéros : chacun est rattaché à l'établissement de
 * l'organisation dont c'est le Phone Number ID.
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public int $storeId, public array $payload) {}

    public function handle(): void
    {
        $receivingStore = Store::find($this->storeId);

        if (! $receivingStore) {
            return;
        }

        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $store = $this->storeFor($receivingStore, $value['metadata']['phone_number_id'] ?? null);

                if (! $store) {
                    Log::warning('Webhook WhatsApp : numéro inconnu de l\'organisation', [
                        'store_id' => $receivingStore->id,
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                    ]);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->recordInboundMessage($store, $message);
                }
            }
        }
    }

    /**
     * Établissement destinataire : celui dont c'est le numéro, dans la même
     * organisation que l'URL appelée (jamais une autre entreprise).
     */
    private function storeFor(Store $receivingStore, ?string $phoneNumberId): ?Store
    {
        if ($phoneNumberId === null || $phoneNumberId === $receivingStore->whatsapp_phone_number_id) {
            return $receivingStore;
        }

        return Store::where('organization_id', $receivingStore->organization_id)
            ->where('whatsapp_phone_number_id', $phoneNumberId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function recordInboundMessage(Store $store, array $message): void
    {
        $id = $message['id'] ?? null;
        $from = preg_replace('/\D/', '', (string) ($message['from'] ?? ''));

        if (! $id || $from === '') {
            return;
        }

        $contactId = Contact::where('store_id', $store->id)->where('phone_e164', $from)->value('id');

        // insertOrIgnore : un doublon (même wamid) est ignoré par l'index unique.
        WhatsAppInboundMessage::insertOrIgnore([
            'store_id' => $store->id,
            'contact_id' => $contactId,
            'wa_message_id' => $id,
            'from_phone' => $from,
            'type' => (string) ($message['type'] ?? 'unknown'),
            'body' => self::bodyOf($message),
            'received_at' => isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now(),
            'payload' => json_encode($message),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function bodyOf(array $message): ?string
    {
        return match ($message['type'] ?? null) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title'] ?? null,
            'image', 'video', 'document' => $message[$message['type']]['caption'] ?? null,
            default => null,
        };
    }
}
