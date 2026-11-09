<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi d'un message de discussion (réponse d'un utilisateur, réponse
 * automatique, rappel) par le numéro WhatsApp de l'établissement du contact,
 * et sa trace dans whatsapp_outbound_messages. Un échec (fenêtre de 24 h
 * fermée, numéro non configuré…) est enregistré, jamais levé : le robot ne
 * doit pas faire échouer le traitement du webhook.
 *
 * Messages de session et utilitaires : ils ne consomment aucun crédit.
 */
class WhatsAppConversation
{
    /** La fenêtre de service WhatsApp : 24 h après le dernier message du client. */
    public static function windowOpen(Contact $contact): bool
    {
        return $contact->last_inbound_at !== null && $contact->last_inbound_at->gt(now()->subDay());
    }

    public static function text(Contact $contact, string $body, string $origin = 'manual', ?User $user = null): WhatsAppOutboundMessage
    {
        return self::send($contact, 'text', $body, ['text' => $body], $origin, $user,
            fn ($client) => $client->sendText($contact->phone_e164, $body));
    }

    /** @param  array<string, mixed>  $interactive */
    public static function interactive(Contact $contact, array $interactive, string $origin = 'auto'): WhatsAppOutboundMessage
    {
        return self::send($contact, 'interactive', (string) ($interactive['body']['text'] ?? ''), ['interactive' => $interactive], $origin, null,
            fn ($client) => $client->sendInteractive($contact->phone_e164, $interactive));
    }

    /**
     * @param  list<string>  $variables
     * @param  list<string>  $quickReplies
     */
    public static function template(Contact $contact, string $name, string $language, array $variables = [], array $quickReplies = [], string $origin = 'manual', ?User $user = null, ?string $preview = null): WhatsAppOutboundMessage
    {
        return self::send($contact, 'template', $preview ?? "Modèle « {$name} »", ['template' => $name, 'language' => $language, 'variables' => $variables, 'quick_replies' => $quickReplies], $origin, $user,
            fn ($client) => $client->sendTemplate($contact->phone_e164, $name, $language, $variables, null, $quickReplies));
    }

    /**
     * Boutons (3 au plus) ou liste (jusqu'à 10 lignes) selon le nombre d'options.
     *
     * @param  array<string, string>  $options  identifiant => libellé
     * @return array<string, mixed>
     */
    public static function menu(string $body, array $options, string $listButton = 'Voir les choix'): array
    {
        if (count($options) <= 3) {
            return [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => ['buttons' => collect($options)->map(fn ($label, $id) => ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => mb_substr($label, 0, 20)]])->values()->all()],
            ];
        }

        return [
            'type' => 'list',
            'body' => ['text' => $body],
            'action' => [
                'button' => mb_substr($listButton, 0, 20),
                'sections' => [['title' => 'Menu', 'rows' => collect($options)->take(10)->map(fn ($label, $id) => ['id' => (string) $id, 'title' => mb_substr($label, 0, 24)])->values()->all()]],
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private static function send(Contact $contact, string $type, string $body, array $payload, string $origin, ?User $user, callable $call): WhatsAppOutboundMessage
    {
        $status = 'sent';
        $error = null;
        $id = null;

        try {
            $response = $call($contact->store->whatsappClient());
            $id = $response['messages'][0]['id'] ?? null;
        } catch (Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 1000);
            Log::warning('Discussion WhatsApp : envoi en échec', ['contact_id' => $contact->id, 'error' => $error]);
        }

        return WhatsAppOutboundMessage::create([
            'store_id' => $contact->store_id,
            'contact_id' => $contact->id,
            'user_id' => $user?->id,
            'type' => $type,
            'origin' => $origin,
            'body' => $body,
            'payload' => $payload,
            'wa_message_id' => $id,
            'status' => $status,
            'error' => $error,
            'sent_at' => now(),
        ]);
    }
}
