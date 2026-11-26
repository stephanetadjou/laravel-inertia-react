<?php

use App\Http\Middleware\EnsureCanManageOrganization;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureOrganizationActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureWhatsAppApiEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Langue de l’interface (fr | en), avant le partage des props Inertia.
            SetLocale::class,
            HandleInertiaRequests::class,
            // Entreprise suspendue depuis le back-office plateforme.
            EnsureOrganizationActive::class,
        ]);

        $middleware->validateCsrfTokens(except: ['webhooks/*', 'c/*/clic']);

        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'can-manage-organization' => EnsureCanManageOrganization::class,
            'whatsapp-api' => EnsureWhatsAppApiEnabled::class,
            'module' => EnsureModuleEnabled::class,
            'platform-admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
