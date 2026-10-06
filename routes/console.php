<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Parcours client : les passages « À risque » et « Inactif » dépendent du
// temps écoulé, on recalcule donc toutes les fiches chaque nuit.
// Nécessite la tâche cron `php artisan schedule:run` chaque minute sur le serveur.
Schedule::command('crm:refresh-lifecycle')->dailyAt('02:30')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
