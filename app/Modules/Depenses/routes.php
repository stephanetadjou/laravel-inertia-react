<?php

use App\Modules\Depenses\Http\ExpenseController;
use Illuminate\Support\Facades\Route;

// Espace « Dépenses » : on n'y gère que les dépenses (module « depenses »).
Route::middleware(['auth', 'module:depenses'])->group(function () {
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('expenses.update');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('expenses.destroy');
    Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->whereNumber('expense')->name('expenses.receipt');
});
