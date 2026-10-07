<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Models\Store;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendCampaignJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int, WhatsAppClient> Un client par boutique, construit à la demande. */
    private array $clientsByStoreId = [];

    /** @var array<int, string> Langue du template par organisation, résolue une seule fois. */
    private array $languagesByOrganizationId = [];

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
                            : $client->sendTemplate(
                                $message->contact->phone_e164,
                                $this->campaign->template_name,
                                $this->campaign->template_language ?: $this->templateLanguageFor($store),
                                $this->campaign->variablesFor($message->contact),
                                $this->campaign->header_image_url,
                            );

                        $message->update([
                            'status' => 'sent',
                            'meta_message_id' => $result['messages'][0]['id'] ?? null,
                        ]);
                        $this->campaign->increment('sent_count');
                    } catch (WhatsAppApiException|ConnectionException|RuntimeException $e) {
                        // RuntimeException : boutique sans identifiants et pas de
                        // compte partagé — le message échoue, la campagne continue.
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
     * Un message part toujours via le client de la boutique du contact
     * destinataire : son propre numéro WhatsApp Business s'il est configuré,
     * sinon le compte Meta partagé (cf. Store::whatsappClient()).
     */
    private function clientFor(Store $store): WhatsAppClient
    {
        return $this->clientsByStoreId[$store->id] ??= $store->whatsappClient();
    }

    private function templateLanguageFor(Store $store): string
    {
        return $this->languagesByOrganizationId[$store->organization_id] ??= MessageTemplate::languageFor(
            $store->organization_id,
            $this->campaign->template_name,
        );
    }
}
