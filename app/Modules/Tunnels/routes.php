<?php

use App\Modules\Tunnels\Http\FunnelController;
use App\Modules\Tunnels\Http\PublicFunnelController;
use Illuminate\Support\Facades\Route;

// Espace « Tunnels de vente » (module « tunnels », à partir de l'offre Solo).
Route::middleware(['auth', 'module:tunnels'])->group(function () {
    Route::get('/funnels', [FunnelController::class, 'index'])->name('funnels.index');
    Route::post('/funnels', [FunnelController::class, 'store'])->name('funnels.store');
    Route::post('/funnels/{funnel}', [FunnelController::class, 'update'])->whereNumber('funnel')->name('funnels.update');
    Route::post('/funnels/{funnel}/toggle', [FunnelController::class, 'toggle'])->whereNumber('funnel')->name('funnels.toggle');
    Route::delete('/funnels/{funnel}', [FunnelController::class, 'destroy'])->whereNumber('funnel')->name('funnels.destroy');
    Route::post('/funnels/leads/{lead}/done', [FunnelController::class, 'followUpDone'])->whereNumber('lead')->name('funnels.follow-up');
});

// Page publique du tunnel, sans compte.
Route::get('/t/{organization:slug}/{slug}', [PublicFunnelController::class, 'show'])->name('funnels.public');
Route::post('/t/{organization:slug}/{slug}', [PublicFunnelController::class, 'submit'])->middleware('throttle:5,1')->name('funnels.public.submit');
Route::get('/t/{organization:slug}/{slug}/image', [PublicFunnelController::class, 'image'])->name('funnels.public.image');
