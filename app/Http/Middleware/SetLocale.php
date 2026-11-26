<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue de l'interface (fr | en) : ?lang= (gardé en session), puis le choix
 * de l'utilisateur connecté, puis la langue de son entreprise ; sur une page
 * publique d'entreprise, la langue de celle-ci. Français par défaut.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->query('lang'), Locale::SUPPORTED, true)) {
            $request->session()->put('locale', $request->query('lang'));
        }

        Locale::apply(Locale::resolve($request));

        return $next($request);
    }
}
