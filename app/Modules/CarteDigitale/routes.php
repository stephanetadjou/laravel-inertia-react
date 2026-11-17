<?php

use App\Modules\CarteDigitale\Http\DigitalCardController;
use App\Modules\CarteDigitale\Http\PublicCardController;
use Illuminate\Support\Facades\Route;

// Page publique d'une carte, sans compte (quotas : AppServiceProvider).
Route::get('/c/{slug}', [PublicCardController::class, 'show'])->middleware('throttle:card-view')->name('cards.public');
Route::get('/c/{slug}/vcard', [PublicCardController::class, 'vcard'])->middleware('throttle:card-view')->name('cards.vcard');
Route::get('/c/{slug}/photo', [PublicCardController::class, 'photo'])->name('cards.photo');
Route::post('/c/{slug}/clic', [PublicCardController::class, 'click'])->middleware('throttle:card-view')->name('cards.click');
Route::post('/c/{slug}/contact', [PublicCardController::class, 'contact'])->middleware('throttle:card-contact')->name('cards.contact');

// Espace « Carte digitale » : coché d'office dans toutes les offres (le pack fixe le nombre de cartes) ;
// décoché dans Paramètres › Modules, l'espace disparaît mais les pages publiques restent en ligne.
Route::middleware(['auth', 'module:carte_digitale'])->group(function () {
    Route::get('/cards', [DigitalCardController::class, 'index'])->name('cards.index');
    Route::get('/cards/create', [DigitalCardController::class, 'create'])->name('cards.create');
    Route::post('/cards', [DigitalCardController::class, 'store'])->name('cards.store');
    Route::get('/cards/{card}/edit', [DigitalCardController::class, 'edit'])->whereNumber('card')->name('cards.edit');
    Route::put('/cards/{card}', [DigitalCardController::class, 'update'])->whereNumber('card')->name('cards.update');
    Route::delete('/cards/{card}', [DigitalCardController::class, 'destroy'])->whereNumber('card')->name('cards.destroy');
    Route::get('/cards/{card}/print', [DigitalCardController::class, 'print'])->whereNumber('card')->name('cards.print');
});
