<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook Meta d'un établissement (URL /webhooks/whatsapp/{webhook_key},
 * affichée dans Paramètres → WhatsApp) :
 * - GET : vérification de l'abonnement (hub.verify_token → hub.challenge) ;
 * - POST : événements, acceptés seulement si X-Hub-Signature-256 correspond
 *   à l'App Secret de l'établissement, puis traités en file d'attente pour
 *   répondre 200 à Meta tout de suite.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, string $key): Response
    {
        $store = Store::where('webhook_key', $key)->first();

        // PHP remplace les points des paramètres de requête : hub.mode → hub_mode.
        $token = (string) $request->query('hub_verify_token');

        if (! $store
            || $request->query('hub_mode') !== 'subscribe'
            || blank($store->webhook_verify_token)
            || ! hash_equals($store->webhook_verify_token, $token)) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $key): Response
    {
        $store = Store::where('webhook_key', $key)->first();

        if (! $store) {
            return response('Not Found', 404);
        }

        if (! $store->hasValidWebhookSignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            Log::warning('Webhook WhatsApp : signature invalide', ['store_id' => $store->id]);

            return response('Invalid signature', 403);
        }

        $payload = $request->json()->all();

        if (($payload['object'] ?? null) === 'whatsapp_business_account') {
            ProcessWhatsAppWebhookJob::dispatch($store->id, $payload);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
