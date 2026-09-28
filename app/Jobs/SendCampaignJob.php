<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Store;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int, WhatsAppClient> Un client par boutique, construit à la demande. */
    private array $clientsByStoreId = [];

    public function __construct(public Campaign $campaign) {}

    public function handle(): void
    {
        $this->campaign->update(['status' => 'sending']);

        $this->campaign->campaignMessages()
            ->where('status', 'pending')
            ->with('contact.store')
            ->chunk(50, function ($messages) {
                foreach ($messages as $message) {
                    $store = $message->contact->store;

                    try {
                        $client = $this->clientFor($store);

                        $result = $this->campaign->message_type === 'text'
                            ? $client->sendText($message->contact->phone_e164, $this->campaign->text_body)
                            : $client->sendTemplate($message->contact->phone_e164, $this->campaign->template_name, 'en_US');

                        $message->update([
                            'status' => 'sent',
                            'meta_message_id' => $result['messages'][0]['id'] ?? null,
                        ]);
                        $this->campaign->increment('sent_count');
                    } catch (WhatsAppApiException|ConnectionException $e) {
                        $message->update([
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                        ]);
                        $this->campaign->increment('failed_count');
                    }

                    // Respect des limites de débit WhatsApp (cf. whatsapp_bulk.php).
                    usleep(300000);
                }
            });

        $this->campaign->update(['status' => 'completed']);
    }

    /**
     * Chaque boutique a son propre numéro WhatsApp Business : un message doit
     * toujours partir via le client de la boutique du contact destinataire,
     * jamais un client unique partagé par toute la campagne.
     */
    private function clientFor(Store $store): WhatsAppClient
    {
        return $this->clientsByStoreId[$store->id] ??= new WhatsAppClient(
            $store->whatsapp_access_token,
            $store->whatsapp_phone_number_id,
        );
    }
}
