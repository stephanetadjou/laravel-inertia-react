<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fonctions qui envoient par l'API WhatsApp Cloud (campagnes, modèles,
 * bienvenue, paramètres du numéro, historique des messages) : disponibles
 * seulement si l'entreprise a activé l'API dans Organisation. Sinon, retour
 * aux listes d'actions (relances manuelles par lien wa.me).
 */
class EnsureWhatsAppApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->user()?->organization;

        if ($organization !== null && ! $organization->usesWhatsAppApi()) {
            $message = "L'envoi automatique par l'API WhatsApp n'est pas activé pour votre entreprise. Relancez vos clients depuis les listes d'actions, ou activez l'API dans Paramètres › Entreprise.";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 409);
            }

            return redirect()->route('actions.index')->with('error', $message);
        }

        return $next($request);
    }
}
