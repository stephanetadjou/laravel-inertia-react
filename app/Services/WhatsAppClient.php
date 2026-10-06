<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client fin autour de la WhatsApp Business Cloud API (Meta Graph API).
 *
 * Instancié sans argument, il lit les identifiants par défaut dans
 * config('services.meta_whatsapp'). Les paramètres du constructeur
 * permettent de le réutiliser plus tard avec les identifiants d'une
 * boutique spécifique (une fois le modèle Store en place).
 *
 * Mode simulation (WHATSAPP_DRIVER=log dans .env, développement uniquement) :
 * aucun appel à Meta, aucun identifiant requis. Chaque envoi est écrit dans
 * les logs et renvoie une réponse au format Meta, pour faire tourner
 * campagnes, messages de bienvenue et tests de connexion sans compte Meta.
 * Un numéro qui se termine par 000 simule un échec (numéro non joignable).
 */
class WhatsAppClient
{
    public const DRIVER_CLOUD = 'cloud';

    public const DRIVER_LOG = 'log';

    private readonly string $token;

    private readonly string $phoneNumberId;

    private readonly string $apiVersion;

    /**
     * Jamais en production : un WHATSAPP_DRIVER=log oublié sur le serveur ne
     * doit pas faire croire que des campagnes sont parties.
     */
    public static function simulated(): bool
    {
        return config('services.meta_whatsapp.driver') === self::DRIVER_LOG && ! app()->isProduction();
    }

    public function __construct(
        ?string $token = null,
        ?string $phoneNumberId = null,
        ?string $apiVersion = null,
    ) {
        $this->token = $token ?? (string) config('services.meta_whatsapp.token');
        $this->phoneNumberId = $phoneNumberId ?? (string) config('services.meta_whatsapp.phone_number_id');
        $this->apiVersion = $apiVersion ?? (string) config('services.meta_whatsapp.api_version', 'v21.0');

        if (! self::simulated() && ($this->token === '' || $this->phoneNumberId === '')) {
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
        if (self::simulated()) {
            Log::info('[WhatsApp simulé] Test de connexion', ['phone_number_id' => $this->phoneNumberId ?: '(compte partagé)']);

            return [
                'verified_name' => 'Simulation (WHATSAPP_DRIVER=log)',
                'display_phone_number' => 'numéro simulé',
                'quality_rating' => 'GREEN',
            ];
        }

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

        if (self::simulated()) {
            return $this->simulateSend($to, ['type' => 'template', 'template' => $template]);
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
        if (self::simulated()) {
            return $this->simulateSend($to, ['type' => 'text', 'text' => $message]);
        }

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

    /**
     * Envoi simulé : journalisé, réponse au format Meta. Les numéros qui se
     * terminent par 000 échouent comme un numéro absent de WhatsApp (131026),
     * pour pouvoir tester l'affichage des échecs.
     *
     * @param  array<string, mixed>  $payload
     */
    private function simulateSend(string $to, array $payload): array
    {
        if (str_ends_with($to, '000')) {
            Log::warning('[WhatsApp simulé] Échec simulé', ['to' => $to, ...$payload]);

            throw new WhatsAppApiException($to, 400, [
                'error' => ['code' => 131026, 'message' => '(131026) Message undeliverable — échec simulé'],
            ]);
        }

        $messageId = 'wamid.SIMULATED.'.Str::uuid();
        Log::info('[WhatsApp simulé] Message envoyé', ['to' => $to, 'id' => $messageId, ...$payload]);

        return [
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => $to, 'wa_id' => $to]],
            'messages' => [['id' => $messageId]],
        ];
    }

    private function baseRequest(): PendingRequest
    {
        return Http::baseUrl("https://graph.facebook.com/{$this->apiVersion}")
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(30);
    }
}
