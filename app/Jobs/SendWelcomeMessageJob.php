<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Services\WhatsAppApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

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
        // Revérifié au moment de l'envoi, pas seulement à la mise en file :
        // un double clic, un envoi groupé qui recoupe un envoi individuel, ou
        // un passage en opt_out entre-temps ne doivent jamais produire un
        // second message (ni un message à quelqu'un qui a refusé).
        $this->contact->refresh();

        if ($this->contact->consent_status !== 'unknown' || $this->contact->welcome_message_sent_at !== null) {
            return;
        }

        $store = $this->contact->store;

        if (! $store->canSendWhatsApp()) {
            report(new RuntimeException("Boutique {$store->name} : aucun identifiant WhatsApp (ni propre, ni compte partagé)."));

            return;
        }

        $client = $store->whatsappClient();

        try {
            $client->sendTemplate(
                $this->contact->phone_e164,
                $this->templateName,
                MessageTemplate::languageFor($store->organization_id, $this->templateName),
            );
            $this->contact->update(['welcome_message_sent_at' => now()]);
        } catch (WhatsAppApiException|ConnectionException $e) {
            report($e);
        }
    }
}
