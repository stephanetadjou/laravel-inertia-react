<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client fin autour de la WhatsApp Business Cloud API (Meta Graph API).
 *
 * Instancié sans argument, il lit les identifiants par défaut dans
 * config('services.meta_whatsapp'). Les paramètres du constructeur
 * permettent de le réutiliser plus tard avec les identifiants d'une
 * boutique spécifique (une fois le modèle Store en place).
 */
class WhatsAppClient
{
    private readonly string $token;

    private readonly string $phoneNumberId;

    private readonly string $apiVersion;

    public function __construct(
        ?string $token = null,
        ?string $phoneNumberId = null,
        ?string $apiVersion = null,
    ) {
        $this->token = $token ?? (string) config('services.meta_whatsapp.token');
        $this->phoneNumberId = $phoneNumberId ?? (string) config('services.meta_whatsapp.phone_number_id');
        $this->apiVersion = $apiVersion ?? (string) config('services.meta_whatsapp.api_version', 'v21.0');

        if ($this->token === '' || $this->phoneNumberId === '') {
            throw new RuntimeException(
                'Identifiants WhatsApp manquants : définissez META_WHATSAPP_TOKEN et META_PHONE_NUMBER_ID dans .env.'
            );
        }
    }

    /**
     * Vérifie que le token et le phone_number_id sont valides, sans envoyer
     * de message. Utilisé pour l'écran "statut de connexion" d'une boutique.
     */
    public function verifyConnection(): array
    {
        $response = $this->baseRequest()->get("/{$this->phoneNumberId}", [
            'fields' => 'verified_name,display_phone_number,quality_rating',
        ]);

        if ($response->failed()) {
            throw new WhatsAppApiException($this->phoneNumberId, $response->status(), $response->json());
        }

        return $response->json();
    }

    /**
     * Envoie un message template WhatsApp approuvé à un destinataire.
     *
     * @param  string  $to  Numéro au format international sans "+" (ex: 24177123456)
     * @param  string[]  $bodyParameters  Valeurs des variables {{1}}, {{2}}... du corps du template
     */
    public function sendTemplate(
        string $to,
        string $templateName,
        string $languageCode = 'en_US',
        array $bodyParameters = [],
    ): array {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if ($bodyParameters !== []) {
            $template['components'] = [
                [
                    'type' => 'body',
                    'parameters' => array_map(
                        static fn (string $text) => ['type' => 'text', 'text' => $text],
                        $bodyParameters
                    ),
                ],
            ];
        }

        $response = $this->baseRequest()->post("/{$this->phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);

        if ($response->failed()) {
            throw new WhatsAppApiException($to, $response->status(), $response->json());
        }

        return $response->json();
    }

    /**
     * Envoie un message texte libre. Ne fonctionne que si le destinataire a
     * écrit à la boutique dans les 24 dernières heures (fenêtre de service
     * WhatsApp) — en dehors de cette fenêtre, Meta rejette l'envoi (erreur
     * #131047) et un template approuvé est obligatoire.
     */
    public function sendText(string $to, string $message): array
    {
        $response = $this->baseRequest()->post("/{$this->phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message],
        ]);

        if ($response->failed()) {
            throw new WhatsAppApiException($to, $response->status(), $response->json());
        }

        return $response->json();
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl("https://graph.facebook.com/{$this->apiVersion}")
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(30);
    }
}
