<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Réserve les routes Organisation / Utilisateurs & Rôles aux super_admin et
 * admin — jamais aux brand_manager / store_manager.
 */
class EnsureCanManageOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canManageOrganization()) {
            throw new HttpException(403, "Vous n'avez pas accès à cette section.");
        }

        return $next($request);
    }
}
