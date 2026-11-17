<?php

use App\Modules\Location\Http\BookingController;
use App\Modules\Location\Http\LocationController;
use App\Modules\Location\Http\PublicListingController;
use App\Modules\Location\Http\PublicRideController;
use App\Modules\Location\Http\RequestController;
use App\Modules\Location\Http\RideController;
use App\Modules\Location\Http\SaleController;
use Illuminate\Support\Facades\Route;

// Espace « Location » du loueur (module « location »).
Route::middleware(['auth', 'module:location'])->prefix('location')->group(function () {
    Route::redirect('/', '/location/dashboard');
    Route::get('/dashboard', [LocationController::class, 'dashboard'])->name('location.dashboard');
    Route::post('/follow-ups/{kind}/{id}', [LocationController::class, 'followUpDone'])->whereNumber('id')->name('location.follow-ups.done');
    Route::get('/accounts', [LocationController::class, 'accounts'])->name('location.accounts');
    Route::post('/accounts', [LocationController::class, 'saveAccount'])->name('location.accounts.save');

    Route::get('/requests', [RequestController::class, 'index'])->name('location.requests');
    Route::post('/requests', [RequestController::class, 'store'])->name('location.requests.store');
    Route::post('/requests/{vehicleRequest}/quote', [RequestController::class, 'quote'])->whereNumber('vehicleRequest')->name('location.requests.quote');
    Route::post('/requests/{vehicleRequest}/book', [RequestController::class, 'book'])->whereNumber('vehicleRequest')->name('location.requests.book');
    Route::post('/requests/{vehicleRequest}/lose', [RequestController::class, 'lose'])->whereNumber('vehicleRequest')->name('location.requests.lose');

    Route::get('/planning', [BookingController::class, 'planning'])->name('location.planning');
    Route::post('/bookings', [BookingController::class, 'store'])->name('location.bookings.store');
    Route::get('/bookings/{booking}/inspection/{phase}', [BookingController::class, 'inspection'])->whereNumber('booking')->name('location.inspection');
    Route::post('/bookings/{booking}/inspection/{phase}', [BookingController::class, 'saveInspection'])->whereNumber('booking')->name('location.inspection.save');
    Route::post('/bookings/{booking}/deposit', [BookingController::class, 'closeDeposit'])->whereNumber('booking')->name('location.deposit');
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->whereNumber('booking')->name('location.bookings.cancel');
    Route::get('/bookings/{booking}/contract.pdf', [BookingController::class, 'contract'])->whereNumber('booking')->name('location.bookings.contract');
    Route::post('/settings', [BookingController::class, 'saveSettings'])->name('location.settings');
    Route::get('/inspections/{inspection}/photos/{index}', [BookingController::class, 'photo'])->whereNumber(['inspection', 'index'])->name('location.inspection.photo');

    Route::post('/rides', [RideController::class, 'store'])->name('location.rides.store');
    Route::post('/requests/{vehicleRequest}/ride', [RideController::class, 'fromRequest'])->whereNumber('vehicleRequest')->name('location.rides.from-request');
    Route::post('/rides/{booking}/status', [RideController::class, 'move'])->whereNumber('booking')->name('location.rides.status');
    Route::post('/ride-fares', [RideController::class, 'storeFare'])->name('location.ride-fares.store');
    Route::delete('/ride-fares/{fare}', [RideController::class, 'destroyFare'])->whereNumber('fare')->name('location.ride-fares.destroy');

    Route::get('/sales', [SaleController::class, 'index'])->name('location.sales');
    Route::post('/vehicles/{vehicle}/for-sale', [SaleController::class, 'saveVehicle'])->whereNumber('vehicle')->name('location.vehicles.for-sale');
    Route::get('/vehicles/{vehicle}/photos/{index}', [SaleController::class, 'vehiclePhoto'])->whereNumber(['vehicle', 'index'])->name('location.vehicles.photo');
    Route::post('/vehicles/{vehicle}/buyers', [SaleController::class, 'storeBuyer'])->whereNumber('vehicle')->name('location.buyers.store');
    Route::post('/sales/{sale}/move', [SaleController::class, 'move'])->whereNumber('sale')->name('location.sales.move');
    Route::post('/sales/{sale}/sell', [SaleController::class, 'sell'])->whereNumber('sale')->name('location.sales.sell');
});

// Annonce publique d'un véhicule à vendre (lien partagé sur WhatsApp ou Facebook).
Route::get('/annonce/{token}', [PublicListingController::class, 'show'])->where('token', '[A-Za-z0-9]{20,40}')->name('location.listing');
Route::get('/annonce/{token}/photos/{index}', [PublicListingController::class, 'photo'])->where('token', '[A-Za-z0-9]{20,40}')->whereNumber('index')->name('location.listing.photo');

// Demande de course en ligne, sans compte (lien de réservation de l'entreprise).
Route::get('/rdv/{organization:slug}/course', [PublicRideController::class, 'show'])->name('location.ride-request');
Route::post('/rdv/{organization:slug}/course', [PublicRideController::class, 'store'])->middleware('throttle:5,1')->name('location.ride-request.store');
