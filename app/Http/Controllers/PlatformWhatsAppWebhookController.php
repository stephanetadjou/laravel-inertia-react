<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook unique de l'app Meta de Menentia (URL {APP_URL}/webhooks/whatsapp),
 * pour les numéros « gérés par Menentia » : Meta envoie ici les événements de
 * tous les comptes WhatsApp partagés avec le portefeuille Menentia.
 * - GET : vérification avec MENENTIA_WHATSAPP_VERIFY_TOKEN ;
 * - POST : signature X-Hub-Signature-256 avec MENENTIA_WHATSAPP_APP_SECRET,
 *   puis chaque changement est confié à l'établissement dont c'est le Phone
 *   Number ID (traitement en file, comme le webhook par établissement).
 */
class PlatformWhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.meta_whatsapp.platform.verify_token');

        // PHP remplace les points des paramètres de requête : hub.mode → hub_mode.
        if ($expected === ''
            || $request->query('hub_mode') !== 'subscribe'
            || ! hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $secret = (string) config('services.meta_whatsapp.platform.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header)) {
            Log::warning('Webhook WhatsApp Menentia : signature invalide');

            return response('Invalid signature', 403);
        }

        $payload = $request->json()->all();

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response('EVENT_RECEIVED', 200);
        }

        foreach (self::splitByStore($payload) as $storeId => $storePayload) {
            ProcessWhatsAppWebhookJob::dispatch($storeId, $storePayload);
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Regroupe les changements par établissement géré par Menentia, retrouvé
     * par le Phone Number ID du changement. Un numéro inconnu est ignoré :
     * jamais d'événement rattaché à une autre entreprise.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private static function splitByStore(array $payload): array
    {
        $byStore = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $phoneNumberId = $change['value']['metadata']['phone_number_id'] ?? null;
                $storeId = $phoneNumberId
                    ? Store::where('whatsapp_managed', true)->where('whatsapp_phone_number_id', (string) $phoneNumberId)->value('id')
                    : null;

                if (! $storeId) {
                    Log::warning('Webhook WhatsApp Menentia : numéro non géré', [
                        'waba_id' => $entry['id'] ?? null,
                        'phone_number_id' => $phoneNumberId,
                    ]);

                    continue;
                }

                $byStore[$storeId] ??= ['object' => $payload['object'], 'entry' => []];
                $byStore[$storeId]['entry'][] = ['id' => $entry['id'] ?? null, 'changes' => [$change]];
            }
        }

        return $byStore;
    }
}
