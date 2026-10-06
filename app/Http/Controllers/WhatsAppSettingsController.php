<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\WhatsAppApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Paramètres → WhatsApp (administrateurs uniquement, cf. middleware
 * can-manage-organization) : chaque établissement saisit les identifiants de
 * son propre compte WhatsApp Cloud API, les teste chez Meta et enregistre son
 * numéro. Le token et l'App Secret sont chiffrés et jamais renvoyés au
 * navigateur : un champ laissé vide conserve la valeur enregistrée.
 */
class WhatsAppSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $stores = Store::where('organization_id', $request->user()->organization_id)
            ->with('brand')
            ->orderBy('name')
            ->get()
            ->each(fn (Store $store) => $store->ensureWebhookCredentials());

        return Inertia::render('Settings/WhatsApp', [
            'stores' => $stores->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'brand_name' => $store->brand?->name,
                'business_account_id' => $store->whatsapp_business_account_id,
                'phone_number_id' => $store->whatsapp_phone_number_id,
                'app_id' => $store->whatsapp_app_id,
                'has_access_token' => filled($store->whatsapp_access_token),
                'has_app_secret' => filled($store->whatsapp_app_secret),
                'connected' => $store->canSendWhatsApp(),
                'shared_account' => $store->usesSharedWhatsAppAccount(),
                'verified_name' => $store->whatsapp_verified_name,
                'display_phone_number' => $store->whatsapp_display_phone_number,
                'quality_rating' => $store->whatsapp_quality_rating,
                'messaging_limit_tier' => $store->whatsapp_messaging_limit_tier,
                'daily_limit' => $store->dailyMessagingLimit(),
                'checked_at' => $store->whatsapp_checked_at?->toIso8601String(),
                'registered_at' => $store->whatsapp_registered_at?->toIso8601String(),
                // À recopier dans l'app Meta (WhatsApp › Configuration › Webhook).
                'webhook_url' => $store->webhookUrl(),
                'webhook_verify_token' => $store->webhook_verify_token,
            ]),
        ]);
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        $data = $request->validate([
            'business_account_id' => ['nullable', 'regex:/^\d{5,25}$/'],
            'phone_number_id' => ['nullable', 'regex:/^\d{5,25}$/'],
            'app_id' => ['nullable', 'regex:/^\d{5,25}$/'],
            'access_token' => ['nullable', 'string', 'max:2000'],
            'app_secret' => ['nullable', 'string', 'max:255'],
        ], [
            '*.regex' => 'Identifiant Meta attendu : chiffres uniquement.',
        ]);

        $phoneNumberChanged = ($data['phone_number_id'] ?? null) !== $store->whatsapp_phone_number_id;

        $store->fill([
            'whatsapp_business_account_id' => $data['business_account_id'] ?? null,
            'whatsapp_phone_number_id' => $data['phone_number_id'] ?? null,
            'whatsapp_app_id' => $data['app_id'] ?? null,
        ]);

        // Secrets : jamais renvoyés au navigateur, donc un champ vide les conserve.
        if (filled($data['access_token'] ?? null)) {
            $store->whatsapp_access_token = trim($data['access_token']);
        }

        if (filled($data['app_secret'] ?? null)) {
            $store->whatsapp_app_secret = trim($data['app_secret']);
        }

        // Nouveau numéro : les constats du dernier test ne le concernent plus.
        if ($phoneNumberChanged) {
            $store->fill([
                'whatsapp_verified_name' => null,
                'whatsapp_display_phone_number' => null,
                'whatsapp_quality_rating' => null,
                'whatsapp_messaging_limit_tier' => null,
                'whatsapp_checked_at' => null,
                'whatsapp_registered_at' => null,
            ]);
        }

        $store->save();

        return back()->with('success', "Paramètres WhatsApp de {$store->name} enregistrés. Lancez « Tester » pour vérifier la connexion.");
    }

    /**
     * Lit le numéro chez Meta (nom vérifié, qualité, limite d'envoi) et garde
     * le résultat sur l'établissement : la limite sert ensuite de plafond
     * aux campagnes.
     */
    public function test(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        if (! $store->canSendWhatsApp()) {
            return back()->with('error', "{$store->name} : renseignez d'abord le Phone Number ID et le token d'accès.");
        }

        try {
            $info = $store->whatsappClient()->verifyConnection();
        } catch (WhatsAppApiException $e) {
            return back()->with('error', "{$store->name} : {$e->explanation()}");
        } catch (ConnectionException) {
            return back()->with('error', "{$store->name} : impossible de joindre Meta (réseau ou délai dépassé). Réessayez.");
        }

        $store->update([
            'whatsapp_verified_name' => $info['verified_name'] ?? null,
            'whatsapp_display_phone_number' => $info['display_phone_number'] ?? null,
            'whatsapp_quality_rating' => $info['quality_rating'] ?? null,
            'whatsapp_messaging_limit_tier' => $info['messaging_limit_tier'] ?? null,
            'whatsapp_checked_at' => now(),
        ]);

        $limit = $store->dailyMessagingLimit();

        return back()->with('success', sprintf(
            '%s connecté : %s (%s), qualité %s, limite %s.',
            $store->name,
            $info['verified_name'] ?? 'nom non vérifié',
            $info['display_phone_number'] ?? '?',
            self::qualityLabel($info['quality_rating'] ?? null),
            $limit ? number_format($limit, 0, ',', ' ').' destinataires / 24 h' : 'non communiquée',
        ));
    }

    /**
     * Enregistre le numéro auprès de la Cloud API avec le PIN de vérification
     * en deux étapes (6 chiffres). Le PIN n'est jamais stocké.
     */
    public function register(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        $data = $request->validate([
            'pin' => ['required', 'digits:6'],
        ], [
            'pin.digits' => 'Le PIN comporte exactement 6 chiffres.',
        ]);

        if (! $store->canSendWhatsApp() || blank($store->whatsapp_phone_number_id)) {
            return back()->with('error', "{$store->name} : renseignez d'abord le Phone Number ID et le token d'accès.");
        }

        try {
            $store->whatsappClient()->registerPhoneNumber($data['pin']);
        } catch (WhatsAppApiException $e) {
            return back()->with('error', "{$store->name} : {$e->explanation()}");
        } catch (ConnectionException) {
            return back()->with('error', "{$store->name} : impossible de joindre Meta (réseau ou délai dépassé). Réessayez.");
        }

        $store->update(['whatsapp_registered_at' => now()]);

        return back()->with('success', "Numéro de {$store->name} enregistré auprès de WhatsApp Cloud API.");
    }

    private static function qualityLabel(?string $rating): string
    {
        return match ($rating) {
            'GREEN' => 'élevée',
            'YELLOW' => 'moyenne',
            'RED' => 'faible',
            default => 'inconnue',
        };
    }

    private function authorizeStore(Request $request, Store $store): void
    {
        if ($store->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cet établissement n\'appartient pas à votre organisation.');
        }
    }
}
