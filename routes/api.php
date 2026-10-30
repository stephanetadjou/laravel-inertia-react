<?php

use App\Http\Controllers\Api\LeadController;
use Illuminate\Support\Facades\Route;

/*
 * API publique lue par le site vitrine et, plus tard, la marketplace.
 * Préfixe « api » ajouté par bootstrap/app.php.
 */
Route::prefix('public/v1')->group(function () {
    Route::post('/leads', [LeadController::class, 'store'])->middleware('throttle:leads')->name('api.leads.store');
});
