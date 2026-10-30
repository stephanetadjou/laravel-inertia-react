<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entreprise suspendue depuis le back-office plateforme : ses utilisateurs
 * ne voient plus qu'une page « compte suspendu » (déconnexion possible).
 * Les super-admins Menentia gardent l'accès à /admin.
 */
class EnsureOrganizationActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $user?->organization;

        if (! $organization?->isSuspended()
            || $request->routeIs('logout')
            || ($user->isPlatformAdmin() && $request->is('admin', 'admin/*'))) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Le compte de votre entreprise est suspendu.'], 403);
        }

        return Inertia::render('Suspended', [
            'organization' => $organization->name,
            'reason' => $organization->suspension_reason,
            'contact' => config('services.menentia.support_email'),
        ])->toResponse($request)->setStatusCode(403);
    }
}
