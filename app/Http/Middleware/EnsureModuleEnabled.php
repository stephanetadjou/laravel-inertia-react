<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écrans d'un module à la carte (cf. Organization::MODULES) : accessibles si
 * au moins un des modules indiqués est actif, ex. `module:rdv,seances_groupe`.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $organization = $request->user()?->organization;

        if ($organization !== null && ! collect($modules)->contains(fn (string $module) => $organization->hasModule($module))) {
            $label = Organization::MODULES[$modules[0]]['label'] ?? $modules[0];
            $message = "Le module « {$label} » n'est pas activé pour votre entreprise (Paramètres › Modules).";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 404);
            }

            return redirect()->route('dashboard.index')->with('error', $message);
        }

        return $next($request);
    }
}
