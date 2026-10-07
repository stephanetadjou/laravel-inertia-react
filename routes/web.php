<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredOrganizationController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppSettingsController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook Meta par établissement : public, authentifié par la signature
// X-Hub-Signature-256 (exclu de la vérification CSRF, cf. bootstrap/app.php).
Route::get('/webhooks/whatsapp/{key}', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp');
Route::post('/webhooks/whatsapp/{key}', [WhatsAppWebhookController::class, 'receive']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/register', [RegisteredOrganizationController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredOrganizationController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', function () {
        return redirect()->route('dashboard.index');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');
    Route::post('/contacts/assign-group', [ContactController::class, 'assignGroup'])->name('contacts.assign-group');
    Route::post('/contacts/send-welcome-bulk', [ContactController::class, 'sendWelcomeBulk'])->name('contacts.send-welcome-bulk');
    Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::post('/contacts/{contact}/send-test', [ContactController::class, 'sendTest'])->name('contacts.send-test');
    Route::post('/contacts/{contact}/send-welcome', [ContactController::class, 'sendWelcome'])->name('contacts.send-welcome');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
    Route::get('/customers/audience', [CustomerController::class, 'audience'])->name('customers.audience');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{contact}', [ChatController::class, 'show'])->name('chat.show');

    Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
    Route::put('/groups/{group}', [GroupController::class, 'update'])->name('groups.update');
    Route::delete('/groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
    Route::post('/groups/{group}/customers', [GroupController::class, 'addCustomers'])->name('groups.customers.add');
    Route::delete('/groups/{group}/customers', [GroupController::class, 'removeCustomers'])->name('groups.customers.remove');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('/stores/{store}/qr', [StoreController::class, 'qr'])->name('stores.qr');

    Route::middleware('can-manage-organization')->group(function () {
        Route::get('/organisation', [OrganizationController::class, 'index'])->name('organisation.index');
        Route::put('/organisation', [OrganizationController::class, 'update'])->name('organisation.update');
        Route::put('/organisation/lifecycle', [OrganizationController::class, 'updateLifecycle'])->name('organisation.lifecycle');

        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

        Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
        Route::put('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');

        Route::post('/templates/sync', [MessageTemplateController::class, 'sync'])->name('templates.sync');

        Route::get('/settings/whatsapp', [WhatsAppSettingsController::class, 'index'])->name('settings.whatsapp');
        Route::put('/settings/whatsapp/{store}', [WhatsAppSettingsController::class, 'update'])->name('settings.whatsapp.update');
        Route::post('/settings/whatsapp/{store}/test', [WhatsAppSettingsController::class, 'test'])->name('settings.whatsapp.test');
        Route::post('/settings/whatsapp/{store}/register', [WhatsAppSettingsController::class, 'register'])->name('settings.whatsapp.register');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::post('/purchases/preview', [PurchaseController::class, 'preview'])->name('purchases.preview');
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');

    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports/preview', [ImportController::class, 'preview'])->name('imports.preview');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');

    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/segment-count', [CampaignController::class, 'segmentCount'])->name('campaigns.segment-count');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');

    Route::get('/templates', [MessageTemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [MessageTemplateController::class, 'store'])->name('templates.store');
    Route::put('/templates/{template}', [MessageTemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{template}', [MessageTemplateController::class, 'destroy'])->name('templates.destroy');
});
