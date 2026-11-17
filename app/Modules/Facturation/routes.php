<?php

use App\Modules\Facturation\Http\InvoiceController;
use App\Modules\Facturation\Http\PublicDocumentController;
use Illuminate\Support\Facades\Route;

// Espace « Factures » : devis, factures, paiements (module « factures »).
Route::middleware(['auth', 'module:factures'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->whereNumber('invoice')->name('invoices.edit');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice')->name('invoices.update');
    Route::post('/invoices/{invoice}/sent', [InvoiceController::class, 'sent'])->whereNumber('invoice')->name('invoices.sent');
    Route::post('/invoices/{invoice}/reminded', [InvoiceController::class, 'reminded'])->whereNumber('invoice')->name('invoices.reminded');
    Route::post('/invoices/{invoice}/accept', [InvoiceController::class, 'accept'])->whereNumber('invoice')->name('invoices.accept');
    Route::post('/invoices/{invoice}/refuse', [InvoiceController::class, 'refuse'])->whereNumber('invoice')->name('invoices.refuse');
    Route::post('/invoices/{invoice}/convert', [InvoiceController::class, 'convert'])->whereNumber('invoice')->name('invoices.convert');
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->whereNumber('invoice')->name('invoices.pay');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('invoice')->name('invoices.cancel');
    Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->whereNumber('invoice')->name('invoices.print');
});

// Lien envoyé au client par WhatsApp : consulter, accepter ou refuser un devis.
Route::get('/d/{token}', [PublicDocumentController::class, 'show'])->name('documents.show');
Route::post('/d/{token}/accept', [PublicDocumentController::class, 'accept'])->middleware('throttle:10,1')->name('documents.accept');
Route::post('/d/{token}/refuse', [PublicDocumentController::class, 'refuse'])->middleware('throttle:10,1')->name('documents.refuse');
