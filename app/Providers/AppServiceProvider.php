<?php

namespace App\Providers;

use App\Support\Locale;
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
        // Langue de base (tâches en file, commandes) : français ; chaque requête,
        // message WhatsApp ou envoi applique ensuite la langue de l'utilisateur ou de l'entreprise.
        Locale::apply(Locale::DEFAULT);

        // Réservation en ligne : compteurs séparés (la recherche de créneaux ne
        // consomme pas le quota d'envoi), par adresse IP ; dépassement affiché
        // dans le formulaire plutôt qu'en page d'erreur.
        RateLimiter::for('booking-slots', fn (Request $request) => Limit::perMinute(120)->by('booking-slots|'.$request->ip()));

        RateLimiter::for('booking-submit', fn (Request $request) => Limit::perMinute(10)->by('booking-submit|'.$request->ip())
            ->response(fn () => back()->withErrors(['time' => 'Trop de tentatives depuis cet appareil. Réessayez dans une minute.'])));

        // Cartes digitales publiques (/c/{slug}) : visites et clics, puis formulaire de contact.
        RateLimiter::for('card-view', fn (Request $request) => Limit::perMinute(120)->by('card-view|'.$request->ip()));
        RateLimiter::for('card-contact', fn (Request $request) => Limit::perMinute(5)->by('card-contact|'.$request->ip())
            ->response(fn () => back()->withErrors(['phone' => 'Trop de messages depuis cet appareil. Réessayez dans une minute.'])));

        // Formulaire de démo du site vitrine.
        RateLimiter::for('leads', fn (Request $request) => Limit::perMinute(5)->by('leads|'.$request->ip()));
    }
}
