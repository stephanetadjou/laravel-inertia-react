<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agenda, rendez-vous, intervenants, fermetures et réservation en ligne :
 * réservés aux secteurs qui travaillent sur rendez-vous (pas au commerce,
 * cf. Organization::SECTORS).
 */
class EnsureAgendaEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->user()?->organization;

        if ($organization !== null && ! $organization->hasAgenda()) {
            $message = "L'agenda n'est pas disponible pour le secteur de votre entreprise.";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 404);
            }

            return redirect()->route('dashboard.index')->with('error', $message);
        }

        return $next($request);
    }
}
