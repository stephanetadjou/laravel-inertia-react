<?php

use App\Modules\Caisse\Http\CashController;
use Illuminate\Support\Facades\Route;

// Espace « Caisse » : la journée de l'établissement (module « caisse »).
Route::middleware(['auth', 'module:caisse'])->group(function () {
    Route::get('/cash', [CashController::class, 'index'])->name('cash.index');
    Route::post('/cash/close', [CashController::class, 'close'])->name('cash.close');
    Route::post('/cash/reopen', [CashController::class, 'reopen'])->name('cash.reopen');
});
