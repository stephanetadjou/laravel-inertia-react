<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envoie le message de bienvenue/demande d'opt-in à un contact "unknown".
 * C'est la seule action qui a le droit de messager un contact hors opt_in
 * (cahier des charges §4 : l'opt-in doit bien venir de quelque part) — une
 * seule fois par contact, jamais renvoyée automatiquement, et jamais
 * utilisée pour du contenu marketing (template category=utility attendu).
 * Ne bascule jamais le consentement automatiquement : sans webhook Meta pour
 * capter la réponse du client, c'est un geste humain qui doit confirmer
 * l'opt-in depuis la fiche contact après lecture de la réponse WhatsApp.
 */
class SendWelcomeMessageJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Contact $contact, public string $templateName) {}

    public function handle(): void
    {
        $store = $this->contact->store;

        $client = new WhatsAppClient($store->whatsapp_access_token, $store->whatsapp_phone_number_id);

        try {
            $client->sendTemplate($this->contact->phone_e164, $this->templateName, 'en_US');
            $this->contact->update(['welcome_message_sent_at' => now()]);
        } catch (WhatsAppApiException|ConnectionException $e) {
            report($e);
        }
    }
}
