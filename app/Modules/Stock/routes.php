<?php

use App\Modules\Stock\Http\StockController;
use Illuminate\Support\Facades\Route;

// Espace « Stock » : produits vendus avec les prestations (module « stock »).
Route::middleware(['auth', 'module:stock'])->group(function () {
    Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('/stock/movements', [StockController::class, 'movements'])->name('stock.movements');
    Route::post('/stock/track', [StockController::class, 'track'])->name('stock.track');
    Route::post('/stock/{stockItem}/receive', [StockController::class, 'receive'])->whereNumber('stockItem')->name('stock.receive');
    Route::post('/stock/{stockItem}/count', [StockController::class, 'count'])->whereNumber('stockItem')->name('stock.count');
    Route::put('/stock/{stockItem}', [StockController::class, 'update'])->whereNumber('stockItem')->name('stock.update');
    Route::delete('/stock/{stockItem}', [StockController::class, 'untrack'])->whereNumber('stockItem')->name('stock.untrack');
});
