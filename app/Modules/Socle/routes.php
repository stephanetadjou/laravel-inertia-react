<?php

use App\Modules\Socle\Http\AppsController;
use Illuminate\Support\Facades\Route;

// Lanceur d'applications (façon Odoo) et automatisations d'une application.
Route::middleware('auth')->group(function () {
    Route::get('/apps', [AppsController::class, 'index'])->name('apps.index');
    Route::get('/apps/toutes', [AppsController::class, 'all'])->name('apps.all');
    Route::get('/apps/pulse', [AppsController::class, 'pulse'])->name('apps.pulse');
    Route::get('/apps/search', [AppsController::class, 'search'])->middleware('throttle:60,1')->name('apps.search');
    Route::get('/apps/automations', [AppsController::class, 'automations'])->name('apps.automations');
});
