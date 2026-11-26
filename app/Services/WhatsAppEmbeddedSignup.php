<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Connexion WhatsApp en 5 minutes (inscription intégrée Meta) : le gérant se
 * connecte avec Facebook dans une fenêtre Meta, choisit son numéro, et Meta
 * renvoie un code + les identifiants du numéro et du compte WhatsApp. Le code
 * est échangé contre un token, l'app ClientlyX est abonnée au compte (webhook
 * unique de la plateforme) et le numéro est enregistré.
 *
 * Coexistence : un numéro déjà utilisé dans l'application WhatsApp Business
 * garde l'application (même numéro, mêmes conversations sur le téléphone) ;
 * il n'est alors pas réenregistré.
 *
 * Ne fonctionne pour les clients qu'une fois ClientlyX « Tech Provider »
 * (entreprise vérifiée chez Meta et app validée). Avant cela, seuls les
 * comptes ayant un rôle sur l'app Meta ClientlyX peuvent l'utiliser.
 */
class WhatsAppEmbeddedSignup
{
    /** Réglages publics pour la page (null = inscription intégrée non configurée). */
    public static function settings(): ?array
    {
        $appId = config('services.meta_whatsapp.embedded.app_id');
        $configId = config('services.meta_whatsapp.embedded.config_id');

        if (blank($appId) || blank($configId) || blank(config('services.meta_whatsapp.platform.app_secret'))) {
            return null;
        }

        return [
            'app_id' => (string) $appId,
            'config_id' => (string) $configId,
            'api_version' => (string) config('services.meta_whatsapp.api_version', 'v21.0'),
            'coexistence' => (bool) config('services.meta_whatsapp.embedded.coexistence', true),
        ];
    }

    /**
     * Termine la connexion après la fenêtre Meta.
     *
     * @throws RuntimeException|WhatsAppApiException
     */
    public static function complete(Store $store, string $code, string $phoneNumberId, string $wabaId, bool $coexistence): void
    {
        $settings = self::settings() ?? throw new RuntimeException('Inscription intégrée non configurée.');

        $response = Http::timeout(30)->get("https://graph.facebook.com/{$settings['api_version']}/oauth/access_token", [
            'client_id' => $settings['app_id'],
            'client_secret' => config('services.meta_whatsapp.platform.app_secret'),
            'code' => $code,
        ]);

        $token = (string) $response->json('access_token');
        if ($response->failed() || $token === '') {
            throw new RuntimeException('Meta a refusé le code de connexion : '.($response->json('error.message') ?? 'réponse vide'));
        }

        $client = new WhatsAppClient($token, $phoneNumberId);
        $client->subscribeAppToBusinessAccount($wabaId);

        // Nouveau numéro : enregistrement sur Cloud API (PIN de double authentification choisi par ClientlyX).
        // Numéro de l'application WhatsApp Business (coexistence) : déjà actif, pas d'enregistrement.
        if (! $coexistence) {
            $client->registerPhoneNumber((string) random_int(100000, 999999));
        }

        $info = $client->verifyConnection();

        $store->forceFill([
            'whatsapp_phone_number_id' => $phoneNumberId,
            'whatsapp_business_account_id' => $wabaId,
            'whatsapp_access_token' => $token,
            'whatsapp_app_id' => $settings['app_id'],
            // Webhook unique de la plateforme, signé avec l'App Secret ClientlyX.
            'whatsapp_managed' => true,
            'whatsapp_verified_name' => $info['verified_name'] ?? null,
            'whatsapp_display_phone_number' => $info['display_phone_number'] ?? null,
            'whatsapp_quality_rating' => $info['quality_rating'] ?? null,
            'whatsapp_messaging_limit_tier' => $info['messaging_limit_tier'] ?? null,
            'whatsapp_checked_at' => now(),
            'whatsapp_registered_at' => now(),
        ])->save();

        $store->organization?->forceFill(['whatsapp_api_enabled' => true])->save();
    }
}
