<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AudienceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredOrganizationController;
use App\Http\Controllers\AutoReplySettingsController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\BiDashboardController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingSettingsController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerIdentityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\LoyaltyProgramController;
use App\Http\Controllers\LoyaltyTrackingController;
use App\Http\Controllers\ManualSaleController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\MultibrandController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PdfReportController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlatformWhatsAppWebhookController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\ProspectController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalesImportController;
use App\Http\Controllers\SalesTeamController;
use App\Http\Controllers\ScheduleSettingsController;
use App\Http\Controllers\SelfServiceController;
use App\Http\Controllers\SourceReportController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppSettingsController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook Meta par établissement : public, authentifié par la signature
// X-Hub-Signature-256 (exclu de la vérification CSRF, cf. bootstrap/app.php).
// Webhook unique de l'app Meta de Menentia (numéros gérés par Menentia).
Route::get('/webhooks/whatsapp', [PlatformWhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.platform');
Route::post('/webhooks/whatsapp', [PlatformWhatsAppWebhookController::class, 'receive']);
Route::get('/webhooks/whatsapp/{key}', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp');
Route::post('/webhooks/whatsapp/{key}', [WhatsAppWebhookController::class, 'receive']);

// Politique de confidentialité publique de chaque entreprise (URL à donner à Meta).
Route::get('/confidentialite/{organization:slug}', [PrivacyPolicyController::class, 'show'])->name('privacy-policy');

// Réservation en ligne publique (sans compte), limitée en nombre de demandes par appareil.
Route::get('/rdv/{organization:slug}', [BookingController::class, 'show'])->name('booking.show');
Route::get('/rdv/{organization:slug}/creneaux', [BookingController::class, 'slots'])->middleware('throttle:booking-slots')->name('booking.slots');
Route::post('/rdv/{organization:slug}', [BookingController::class, 'store'])->middleware('throttle:booking-submit')->name('booking.store');
Route::get('/rdv/{organization:slug}/c/{token}', [BookingController::class, 'confirmation'])->name('booking.confirmation');
Route::post('/rdv/{organization:slug}/c/{token}/annuler', [BookingController::class, 'cancel'])->middleware('throttle:booking-submit')->name('booking.cancel');

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

    // Centre d'aide : guides pas à pas par module (bouton « Besoin d'aide ? »).
    Route::inertia('/aide', 'Help/Index')->name('help.index');

    // Relances manuelles (lien wa.me) : fonctionnent sans l'API WhatsApp.
    Route::get('/actions', [ActionController::class, 'index'])->name('actions.index');
    Route::post('/actions/done', [ActionController::class, 'done'])->name('actions.done');

    // Agenda : rendez-vous (module « rdv ») et cours de groupe (module « seances_groupe »).
    Route::get('/appointments', [AppointmentController::class, 'index'])->middleware('module:rdv,seances_groupe')->name('appointments.index');

    Route::middleware('module:seances_groupe')->group(function () {
        Route::get('/classes', [ClassController::class, 'index'])->name('classes.index');
        Route::post('/classes', [ClassController::class, 'store'])->name('classes.store');
        Route::get('/classes/{class}', [ClassController::class, 'show'])->name('classes.show');
        Route::put('/classes/{class}', [ClassController::class, 'update'])->name('classes.update');
        Route::post('/classes/{class}/enrollments', [ClassController::class, 'enroll'])->name('classes.enroll');
        Route::delete('/classes/{class}/enrollments/{enrollment}', [ClassController::class, 'unenroll'])->name('classes.unenroll');
        Route::get('/class-sessions/{session}', [ClassController::class, 'session'])->name('class-sessions.show');
        Route::post('/class-sessions/{session}/attendance', [ClassController::class, 'attendance'])->name('class-sessions.attendance');
        Route::post('/class-sessions/{session}/cancel', [ClassController::class, 'cancel'])->name('class-sessions.cancel');
        Route::post('/classes/{class}/makeup', [ClassController::class, 'makeup'])->name('classes.makeup');
    });

    Route::middleware('module:rdv')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::put('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
        Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status');
        Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy'])->name('appointments.destroy');
        Route::post('/appointments/{appointment}/checkout', [AppointmentController::class, 'checkout'])->name('appointments.checkout');
    });

    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');
    Route::post('/contacts/assign-group', [ContactController::class, 'assignGroup'])->name('contacts.assign-group');
    Route::post('/contacts/send-welcome-bulk', [ContactController::class, 'sendWelcomeBulk'])->middleware('whatsapp-api')->name('contacts.send-welcome-bulk');
    Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::post('/contacts/{contact}/send-test', [ContactController::class, 'sendTest'])->middleware('whatsapp-api')->name('contacts.send-test');
    Route::post('/contacts/{contact}/send-welcome', [ContactController::class, 'sendWelcome'])->middleware('whatsapp-api')->name('contacts.send-welcome');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
    Route::get('/customers/audience', [CustomerController::class, 'audience'])->name('customers.audience');
    Route::get('/customers/a-verifier', [CustomerIdentityController::class, 'reviews'])->name('customers.reviews');
    Route::post('/customers/a-verifier/{review}', [CustomerIdentityController::class, 'resolve'])->name('customers.reviews.resolve');
    Route::get('/customers/lookup', [CustomerIdentityController::class, 'lookup'])->name('customers.lookup');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::post('/customers/{customer}/merge', [CustomerIdentityController::class, 'merge'])->name('customers.merge');
    Route::post('/customers/{customer}/split', [CustomerIdentityController::class, 'split'])->name('customers.split');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    // Suivi des prospects (module « prospects ») : Kanban, étapes, commercial responsable.
    Route::middleware('module:prospects')->group(function () {
        Route::get('/prospects', [ProspectController::class, 'index'])->name('prospects.index');
        Route::post('/prospects', [ProspectController::class, 'store'])->name('prospects.store');
        Route::post('/prospects/{customer}/stage', [ProspectController::class, 'move'])->name('prospects.move');
        Route::put('/prospects/{customer}/owner', [ProspectController::class, 'assign'])->name('prospects.assign');
        Route::post('/customers/{customer}/prospect', [ProspectController::class, 'follow'])->name('customers.prospect');
        // Suivi des commerciaux : objectifs, résultats, primes.
        Route::get('/sales-team', [SalesTeamController::class, 'index'])->name('sales-team.index');
        Route::put('/sales-team/{member}/targets', [SalesTeamController::class, 'updateTargets'])->name('sales-team.targets');
        Route::get('/commissions', [SalesTeamController::class, 'commissions'])->name('commissions.index');
        Route::get('/commissions/export', [SalesTeamController::class, 'export'])->name('commissions.export');
    });
    // Personnes suivies (module « beneficiaires ») : ajout depuis le responsable, rattachement, note de santé.
    Route::middleware('module:beneficiaires')->group(function () {
        Route::post('/customers/{customer}/beneficiaries', [BeneficiaryController::class, 'store'])->name('customers.beneficiaries.store');
        Route::put('/customers/{customer}/responsible', [BeneficiaryController::class, 'updateResponsible'])->name('customers.responsible');
        Route::put('/customers/{customer}/health-note', [BeneficiaryController::class, 'updateHealthNote'])->name('customers.health-note');
    });
    Route::post('/customers/{customer}/loyalty/redeem', [LoyaltyController::class, 'redeem'])->middleware('module:fidelite')->name('customers.loyalty.redeem');
    Route::middleware('module:fidelite')->group(function () {
        Route::get('/fidelite', [LoyaltyTrackingController::class, 'index'])->name('loyalty.tracking');
        Route::get('/fidelite/export', [LoyaltyTrackingController::class, 'export'])->name('loyalty.tracking.export');
        Route::post('/fidelite/group', [LoyaltyTrackingController::class, 'group'])->name('loyalty.tracking.group');
    });
    Route::post('/customers/{customer}/loyalty-cards/{card}/redeem', [LoyaltyProgramController::class, 'redeem'])->middleware('module:fidelite')->name('customers.loyalty-cards.redeem');
    Route::post('/customers/{customer}/loyalty/adjust', [LoyaltyController::class, 'adjust'])->middleware('module:fidelite')->name('customers.loyalty.adjust');

    // Discussions WhatsApp (boîte de réception partagée) et tableau par source de pub.
    Route::get('/discussions', [ConversationController::class, 'index'])->name('discussions.index');
    Route::get('/discussions/{contact}', [ConversationController::class, 'show'])->name('discussions.show');
    Route::post('/discussions/{contact}/reply', [ConversationController::class, 'reply'])->name('discussions.reply');
    Route::put('/discussions/{contact}/assign', [ConversationController::class, 'assign'])->name('discussions.assign');
    Route::post('/discussions/{contact}/resolve', [ConversationController::class, 'resolve'])->name('discussions.resolve');
    Route::get('/sources', [SourceReportController::class, 'index'])->name('sources.index');
    Route::put('/sources/budgets', [SourceReportController::class, 'updateBudgets'])->middleware('can-manage-organization')->name('sources.budgets');

    Route::get('/chat', [ChatController::class, 'index'])->middleware('whatsapp-api')->name('chat.index');
    Route::get('/chat/{contact}', [ChatController::class, 'show'])->middleware('whatsapp-api')->name('chat.show');

    Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
    Route::put('/groups/{group}', [GroupController::class, 'update'])->name('groups.update');
    Route::delete('/groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
    Route::post('/groups/{group}/customers', [GroupController::class, 'addCustomers'])->name('groups.customers.add');
    Route::delete('/groups/{group}/customers', [GroupController::class, 'removeCustomers'])->name('groups.customers.remove');

    Route::get('/multibrand', [MultibrandController::class, 'index'])->middleware('module:lieux')->name('multibrand.index');
    // Clients d'une analyse : export pour relance, groupe ouvert dans Actions du jour.
    Route::get('/audiences/export', [AudienceController::class, 'export'])->name('audiences.export');
    Route::post('/audiences/group', [AudienceController::class, 'group'])->name('audiences.group');
    Route::get('/actions/export', [ActionController::class, 'export'])->name('actions.export');
    Route::post('/actions/group', [ActionController::class, 'group'])->name('actions.group');
    // Analyses Menentia Retail : tableaux de bord, exports, rapport de direction.
    Route::get('/rapports', [PdfReportController::class, 'index'])->name('rapports.index');
    Route::get('/rapports/{type}', [PdfReportController::class, 'download'])->name('rapports.download');
    Route::get('/customers/{customer}/fiche.pdf', [PdfReportController::class, 'customer'])->name('customers.pdf');
    Route::get('/pilotage/{tab}/export/{list}', [BiDashboardController::class, 'exportList'])->name('pilotage.export');
    Route::get('/pilotage/{tab?}', [BiDashboardController::class, 'dashboard'])->name('pilotage.index');
    Route::get('/analytics/report', [AnalyticsController::class, 'report'])->name('analytics.report');
    Route::get('/analytics/{tab}/export/{table}', [AnalyticsController::class, 'export'])->name('analytics.export');
    Route::get('/analytics/{tab?}', [AnalyticsController::class, 'index'])->name('analytics.index');

    // Catégories du catalogue, ou familles de produits du commerce.
    Route::middleware('module:catalogue,familles')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/a-classer', [CategoryController::class, 'unclassified'])->name('categories.unclassified');
        Route::post('/categories/a-classer', [CategoryController::class, 'assign'])->name('categories.assign');
        Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });

    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('/stores/{store}/qr', [StoreController::class, 'qr'])->name('stores.qr');

    Route::middleware('can-manage-organization')->group(function () {
        Route::get('/organisation', [OrganizationController::class, 'index'])->name('organisation.index');
        Route::put('/organisation', [OrganizationController::class, 'update'])->name('organisation.update');
        Route::put('/organisation/lifecycle', [OrganizationController::class, 'updateLifecycle'])->name('organisation.lifecycle');
        Route::put('/organisation/privacy', [OrganizationController::class, 'updatePrivacy'])->name('organisation.privacy');
        Route::put('/organisation/whatsapp-api', [OrganizationController::class, 'updateWhatsAppApi'])->name('organisation.whatsapp-api');
        Route::put('/actions/messages', [ActionController::class, 'updateMessages'])->name('actions.messages');

        Route::get('/settings/modules', [ModuleController::class, 'index'])->name('settings.modules');
        Route::get('/settings/auto-replies', [AutoReplySettingsController::class, 'edit'])->middleware('module:reponses_auto')->name('settings.auto-replies');
        Route::put('/settings/auto-replies', [AutoReplySettingsController::class, 'update'])->middleware('module:reponses_auto')->name('settings.auto-replies.update');
        Route::post('/settings/sector-preset', [AutoReplySettingsController::class, 'applyPreset'])->name('settings.sector-preset');
        Route::get('/settings/prospects', [ProspectController::class, 'settings'])->middleware('module:prospects')->name('settings.prospects');
        Route::put('/settings/prospects', [ProspectController::class, 'updateSettings'])->middleware('module:prospects')->name('settings.prospects.update');
        Route::put('/settings/modules', [ModuleController::class, 'update'])->name('settings.modules.update');

        Route::middleware('module:fidelite')->group(function () {
            Route::redirect('/settings/loyalty-programs', '/settings/loyalty')->name('settings.loyalty.programs');
            Route::post('/settings/loyalty-programs', [LoyaltyProgramController::class, 'store'])->name('settings.loyalty.programs.store');
            Route::put('/settings/loyalty-programs/{program}', [LoyaltyProgramController::class, 'update'])->name('settings.loyalty.programs.update');
            Route::delete('/settings/loyalty-programs/{program}', [LoyaltyProgramController::class, 'destroy'])->name('settings.loyalty.programs.destroy');
            Route::get('/settings/loyalty', [LoyaltyController::class, 'settings'])->name('settings.loyalty');
            Route::put('/settings/loyalty', [LoyaltyController::class, 'update'])->name('settings.loyalty.update');
            Route::post('/settings/loyalty/rewards', [LoyaltyController::class, 'storeReward'])->name('settings.loyalty.rewards.store');
            Route::put('/settings/loyalty/rewards/{reward}', [LoyaltyController::class, 'updateReward'])->name('settings.loyalty.rewards.update');
            Route::post('/settings/loyalty/rewards/{reward}/toggle', [LoyaltyController::class, 'toggleReward'])->name('settings.loyalty.rewards.toggle');
        });

        Route::get('/reports/daily', [ReportController::class, 'daily'])->name('reports.daily');
        Route::get('/settings/reports', [ReportController::class, 'settings'])->name('settings.reports');
        Route::put('/settings/reports', [ReportController::class, 'update'])->name('settings.reports.update');
        Route::post('/settings/reports/test', [ReportController::class, 'test'])->middleware('throttle:5,1')->name('settings.reports.test');

        // Intervenants : praticiens des rendez-vous ou coachs des cours.
        Route::middleware('module:rdv,seances_groupe')->group(function () {
            Route::get('/settings/staff', [ScheduleSettingsController::class, 'staff'])->name('settings.staff');
            Route::post('/settings/staff', [ScheduleSettingsController::class, 'storeStaff'])->name('settings.staff.store');
            Route::put('/settings/staff/{staff}', [ScheduleSettingsController::class, 'updateStaff'])->name('settings.staff.update');
            Route::post('/settings/staff/{staff}/toggle', [ScheduleSettingsController::class, 'toggleStaff'])->name('settings.staff.toggle');
        });

        Route::middleware('module:rdv')->group(function () {
            Route::get('/settings/closures', [ScheduleSettingsController::class, 'closures'])->name('settings.closures');
            Route::post('/settings/closures', [ScheduleSettingsController::class, 'storeClosure'])->name('settings.closures.store');
            Route::post('/settings/closures/holidays', [ScheduleSettingsController::class, 'holidays'])->name('settings.closures.holidays');
            Route::delete('/settings/closures/{closure}', [ScheduleSettingsController::class, 'destroyClosure'])->name('settings.closures.destroy');

            Route::get('/settings/booking', [BookingSettingsController::class, 'index'])->name('settings.booking');
            Route::put('/settings/booking', [BookingSettingsController::class, 'update'])->name('settings.booking.update');
            Route::put('/settings/booking/stores/{store}', [BookingSettingsController::class, 'updateStore'])->name('settings.booking.store');
        });

        Route::middleware('module:catalogue')->group(function () {
            Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
            Route::post('/catalog', [CatalogController::class, 'store'])->name('catalog.store');
            Route::put('/catalog/{item}', [CatalogController::class, 'update'])->name('catalog.update');
            Route::post('/catalog/{item}/toggle', [CatalogController::class, 'toggle'])->name('catalog.toggle');
        });

        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');

        Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
        Route::put('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');

        Route::post('/templates/sync', [MessageTemplateController::class, 'sync'])->middleware('whatsapp-api')->name('templates.sync');

        Route::get('/settings/whatsapp', [WhatsAppSettingsController::class, 'index'])->middleware('whatsapp-api')->name('settings.whatsapp');
        Route::put('/settings/whatsapp/{store}', [WhatsAppSettingsController::class, 'update'])->middleware('whatsapp-api')->name('settings.whatsapp.update');
        Route::post('/settings/whatsapp/{store}/test', [WhatsAppSettingsController::class, 'test'])->middleware('whatsapp-api')->name('settings.whatsapp.test');
        Route::post('/settings/whatsapp/{store}/register', [WhatsAppSettingsController::class, 'register'])->middleware('whatsapp-api')->name('settings.whatsapp.register');
        Route::post('/settings/whatsapp/{store}/send-test', [WhatsAppSettingsController::class, 'sendTest'])->middleware('whatsapp-api')->name('settings.whatsapp.send-test');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles/presets', [RoleController::class, 'presets'])->name('roles.presets');

        Route::get('/settings/audit', [AuditLogController::class, 'index'])->name('settings.audit');
        Route::get('/settings/rfm', [MultibrandController::class, 'settings'])->name('settings.rfm');
        Route::put('/settings/rfm', [MultibrandController::class, 'update'])->name('settings.rfm.update');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::post('/purchases/preview', [PurchaseController::class, 'preview'])->name('purchases.preview');
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::get('/purchases/export', [PurchaseController::class, 'export'])->name('purchases.export');
    Route::post('/purchases/manual', [ManualSaleController::class, 'store'])->name('purchases.manual.store');
    Route::get('/purchases/manual/customers', [ManualSaleController::class, 'customers'])->name('purchases.manual.customers');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
    Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->whereNumber('purchase')->name('purchases.cancel');
    Route::post('/purchases/{purchase}/restore', [PurchaseController::class, 'restore'])->whereNumber('purchase')->name('purchases.restore');

    // Échéanciers (modules « tranches » et « abonnements ») : tranches, encaissement, reçu.
    Route::middleware('module:tranches,abonnements')->group(function () {
        Route::get('/installments', [InstallmentController::class, 'index'])->name('installments.index');
        Route::post('/payment-schedules', [InstallmentController::class, 'store'])->name('payment-schedules.store');
        Route::post('/payment-schedules/{schedule}/cancel', [InstallmentController::class, 'cancel'])->name('payment-schedules.cancel');
        Route::post('/installments/{installment}/pay', [InstallmentController::class, 'pay'])->name('installments.pay');
        Route::get('/installments/{installment}/receipt', [InstallmentController::class, 'receipt'])->name('installments.receipt');
    });

    // Formules et abonnements (module « abonnements »).
    Route::middleware('module:abonnements')->group(function () {
        Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
        Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
        Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        Route::post('/subscriptions', [PlanController::class, 'subscribe'])->name('subscriptions.store');
        Route::post('/subscriptions/{subscription}/cancel', [PlanController::class, 'cancel'])->name('subscriptions.cancel');
        Route::put('/subscriptions/{subscription}/planning', [PlanController::class, 'planning'])->name('subscriptions.planning');
    });

    // Import des ventes v2 (Menentia Retail) : dépôt, mapping, vérification, traitement en file, annulation.
    Route::get('/sales-imports', [SalesImportController::class, 'index'])->name('sales-imports.index');
    Route::post('/sales-imports', [SalesImportController::class, 'store'])->middleware('throttle:20,1')->name('sales-imports.store');
    Route::get('/sales-imports/{batch}', [SalesImportController::class, 'show'])->name('sales-imports.show');
    Route::post('/sales-imports/{batch}/mapping', [SalesImportController::class, 'mapping'])->name('sales-imports.mapping');
    Route::post('/sales-imports/{batch}/confirm', [SalesImportController::class, 'confirm'])->name('sales-imports.confirm');
    Route::post('/sales-imports/{batch}/cancel', [SalesImportController::class, 'cancel'])->name('sales-imports.cancel');
    Route::get('/sales-imports/{batch}/errors', [SalesImportController::class, 'errors'])->name('sales-imports.errors');
    // Reprise de l'historique (gestion complète) et mode d'utilisation.
    Route::post('/settings/history-import/close', [SalesImportController::class, 'closeHistory'])->name('history-import.close');
    Route::post('/settings/history-import/reopen', [SalesImportController::class, 'reopenHistory'])->name('history-import.reopen');
    Route::put('/settings/usage', [SalesImportController::class, 'updateUsage'])->name('settings.usage');
    Route::put('/settings/customer-label', [SalesImportController::class, 'updateCustomerLabel'])->name('settings.customer-label');

    // Libre-service : modèles Excel des imports, liste « Pour bien démarrer », import des personnes suivies.
    Route::get('/modeles/{kind}', [ImportTemplateController::class, 'show'])->name('import-templates.show');
    Route::post('/onboarding/dismiss', [SelfServiceController::class, 'dismissOnboarding'])->name('onboarding.dismiss');
    Route::post('/imports/beneficiaries', [SelfServiceController::class, 'importBeneficiaries'])->middleware(['module:beneficiaires', 'throttle:20,1'])->name('imports.beneficiaries');

    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports/preview', [ImportController::class, 'preview'])->name('imports.preview');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');

    Route::get('/campaigns', [CampaignController::class, 'index'])->middleware('whatsapp-api')->name('campaigns.index');
    Route::get('/campaigns/segment-count', [CampaignController::class, 'segmentCount'])->middleware('whatsapp-api')->name('campaigns.segment-count');
    Route::post('/campaigns', [CampaignController::class, 'store'])->middleware('whatsapp-api')->name('campaigns.store');

    Route::get('/templates', [MessageTemplateController::class, 'index'])->middleware('whatsapp-api')->name('templates.index');
    Route::post('/templates', [MessageTemplateController::class, 'store'])->middleware('whatsapp-api')->name('templates.store');
    Route::put('/templates/{template}', [MessageTemplateController::class, 'update'])->middleware('whatsapp-api')->name('templates.update');
    Route::delete('/templates/{template}', [MessageTemplateController::class, 'destroy'])->middleware('whatsapp-api')->name('templates.destroy');
});

// Back-office plateforme Menentia (super-admins, cf. menentia:make-admin).
Route::middleware(['auth', 'platform-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboard');
    Route::get('/dashboard', Admin\DashboardController::class)->name('dashboard');
    Route::get('/organizations', [Admin\OrganizationController::class, 'index'])->name('organizations.index');
    Route::get('/organizations/{organization}', [Admin\OrganizationController::class, 'show'])->name('organizations.show');
    Route::post('/organizations/{organization}/trial', [Admin\OrganizationController::class, 'extendTrial'])->name('organizations.trial');
    Route::put('/organizations/{organization}/plan', [Admin\OrganizationController::class, 'changePlan'])->name('organizations.plan');
    Route::post('/organizations/{organization}/activate', [Admin\OrganizationController::class, 'activate'])->name('organizations.activate');
    Route::post('/organizations/{organization}/suspend', [Admin\OrganizationController::class, 'suspend'])->name('organizations.suspend');
    Route::post('/organizations/{organization}/reactivate', [Admin\OrganizationController::class, 'reactivate'])->name('organizations.reactivate');
    Route::get('/leads', [Admin\LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/export', [Admin\LeadController::class, 'export'])->name('leads.export');
    Route::put('/leads/{lead}', [Admin\LeadController::class, 'update'])->name('leads.update');
    Route::put('/organizations/{organization}/stores/{store}/whatsapp', [Admin\OrganizationController::class, 'manageWhatsApp'])->name('organizations.stores.whatsapp');
    Route::post('/organizations/{organization}/credits', [Admin\OrganizationController::class, 'credits'])->name('organizations.credits');
});
