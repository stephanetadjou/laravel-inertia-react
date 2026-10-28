<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Parcours client : les passages « À risque » et « Inactif » dépendent du
// temps écoulé, on recalcule donc toutes les fiches chaque nuit.
// Nécessite la tâche cron `php artisan schedule:run` chaque minute sur le serveur.
Schedule::command('crm:refresh-lifecycle')->dailyAt('02:30')->withoutOverlapping();

// Rapport quotidien au dirigeant : envoyé dès que l'heure choisie par chaque
// entreprise est passée (Paramètres › Rapports), une fois par jour.
Schedule::command('crm:daily-report')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
