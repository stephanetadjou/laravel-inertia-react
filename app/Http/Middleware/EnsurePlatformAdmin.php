<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back-office plateforme (/admin) : super-admins Menentia seulement
 * (users.is_platform_admin, cf. php artisan menentia:make-admin).
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformAdmin(), 403, 'Accès réservé à l’équipe Menentia.');

        return $next($request);
    }
}
