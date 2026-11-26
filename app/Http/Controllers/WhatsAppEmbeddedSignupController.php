<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Audit;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppEmbeddedSignup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fin de la connexion WhatsApp en 5 minutes (fenêtre Meta « inscription intégrée »). */
class WhatsAppEmbeddedSignupController extends Controller
{
    public function store(Request $request, Store $store): RedirectResponse
    {
        if ($store->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cet établissement n\'appartient pas à votre organisation.');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:1024'],
            'phone_number_id' => ['required', 'regex:/^\d{5,30}$/'],
            'waba_id' => ['required', 'regex:/^\d{5,30}$/'],
            'coexistence' => ['boolean'],
        ]);

        try {
            WhatsAppEmbeddedSignup::complete($store, $data['code'], $data['phone_number_id'], $data['waba_id'], (bool) ($data['coexistence'] ?? false));
        } catch (WhatsAppApiException $e) {
            return back()->with('error', "{$store->name} : {$e->explanation()}");
        } catch (ConnectionException) {
            return back()->with('error', "{$store->name} : impossible de joindre Meta (réseau ou délai dépassé). Réessayez.");
        } catch (RuntimeException $e) {
            return back()->with('error', "{$store->name} : {$e->getMessage()}");
        }

        Audit::record('settings.whatsapp', $store, "WhatsApp connecté en 5 minutes pour {$store->name}", ['phone_number_id' => $data['phone_number_id'], 'coexistence' => (bool) ($data['coexistence'] ?? false)]);

        return back()->with('success', "WhatsApp est connecté pour {$store->name}".(($data['coexistence'] ?? false) ? ', en gardant l’application WhatsApp Business sur le téléphone.' : '.'));
    }
}
