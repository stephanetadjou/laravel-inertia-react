<?php

namespace App\Providers;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Charge chaque module de app/Modules/<Nom>/ comme un module Odoo : ses
 * migrations (migrations/) et ses routes (routes.php, sous auth et sous le
 * middleware module:<clé> que le fichier pose lui-même).
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (ModuleRegistry::folders() as $folder) {
            if (is_dir($folder.'/migrations')) {
                $this->loadMigrationsFrom($folder.'/migrations');
            }

            // Ponts entre modules (écouteurs d'événements), cf. app/Modules/Stock/boot.php.
            if (is_file($folder.'/boot.php')) {
                require $folder.'/boot.php';
            }

            if (is_dir($folder.'/views')) {
                $this->loadViewsFrom($folder.'/views', strtolower(basename($folder)));
            }

            if (is_file($folder.'/routes.php') && ! $this->app->routesAreCached()) {
                Route::middleware('web')->group($folder.'/routes.php');
            }
        }
    }
}
