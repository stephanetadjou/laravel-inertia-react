<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Réservation en ligne : compteurs séparés (la recherche de créneaux ne
        // consomme pas le quota d'envoi), par adresse IP ; dépassement affiché
        // dans le formulaire plutôt qu'en page d'erreur.
        RateLimiter::for('booking-slots', fn (Request $request) => Limit::perMinute(120)->by('booking-slots|'.$request->ip()));

        RateLimiter::for('booking-submit', fn (Request $request) => Limit::perMinute(10)->by('booking-submit|'.$request->ip())
            ->response(fn () => back()->withErrors(['time' => 'Trop de tentatives depuis cet appareil. Réessayez dans une minute.'])));
    }
}
