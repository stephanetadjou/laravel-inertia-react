<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\MessageTemplate;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Un lot de messages d'une campagne (cf. SendCampaignJob). Rejouable sans
 * doublon : seuls les messages encore « pending » partent. Une erreur
 * passagère (réseau, Meta indisponible, limite de débit) fait échouer le lot
 * pour qu'il soit retenté plus tard ; une erreur définitive (numéro invalide,
 * modèle refusé) marque seulement le message en échec. Après la dernière
 * tentative, les messages restants du lot passent en échec.
 */
class SendCampaignBatchJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    /** @var array<int, WhatsAppClient> */
    private array $clientsByStoreId = [];

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(public int $campaignId, public array $messageIds) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $campaign = Campaign::find($this->campaignId);

        if (! $campaign) {
            return;
        }

        $messages = CampaignMessage::whereIn('id', $this->messageIds)
            ->where('status', 'pending')
            ->with('contact.store')
            ->get();

        $delay = (int) config('services.meta_whatsapp.send_delay_ms', 100) * 1000;

        try {
            foreach ($messages as $message) {
                $this->send($campaign, $message);

                if ($delay > 0) {
                    usleep($delay);
                }
            }
        } finally {
            $campaign->refreshDeliveryCounts();
        }
    }

    private function send(Campaign $campaign, CampaignMessage $message): void
    {
        $contact = $message->contact;
        $store = $contact->store;

        try {
            $client = $this->clientsByStoreId[$store->id] ??= $store->whatsappClient();

            $result = $campaign->message_type === 'text'
                ? $client->sendText($contact->phone_e164, $campaign->text_body)
                : $client->sendTemplate(
                    $contact->phone_e164,
                    $campaign->template_name,
                    $campaign->template_language ?: MessageTemplate::languageFor($store->organization_id, $campaign->template_name),
                    $campaign->variablesFor($contact),
                    $campaign->header_image_url,
                );

            $message->update([
                'status' => 'sent',
                'meta_message_id' => $result['messages'][0]['id'] ?? null,
                'error_message' => null,
            ]);
        } catch (WhatsAppApiException $e) {
            if ($e->isTransient()) {
                throw $e;
            }

            $message->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        } catch (ConnectionException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Établissement sans identifiants WhatsApp : échec du message, le lot continue.
            $message->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    /**
     * Toutes les tentatives épuisées : les messages encore en attente du lot
     * sont marqués en échec, pour que la campagne puisse se terminer.
     */
    public function failed(?Throwable $exception): void
    {
        CampaignMessage::whereIn('id', $this->messageIds)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_message' => 'Envoi interrompu après plusieurs tentatives : '.($exception?->getMessage() ?? 'erreur inconnue'),
                'updated_at' => now(),
            ]);

        Campaign::find($this->campaignId)?->refreshDeliveryCounts();
    }
}
