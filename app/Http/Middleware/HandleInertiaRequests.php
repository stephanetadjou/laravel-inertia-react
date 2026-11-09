<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_name' => $user->role?->name,
                    'can_manage_organization' => $user->canManageOrganization(),
                    'can_import' => $user->canImport(),
                    'can_export' => $user->canExport(),
                    'is_platform_admin' => $user->isPlatformAdmin(),
                ] : null,
                'organization' => $user?->organization ? [
                    'name' => $user->organization->name,
                    'whatsapp_api_enabled' => $user->organization->usesWhatsAppApi(),
                    // Secteur : vocabulaire « familles » du commerce ; agenda = module Rendez-vous.
                    'sector' => $user->organization->sector,
                    // Import des ventes : une seule reprise en gestion complète, régulier en analyse seule.
                    'usage_mode' => $user->organization->usage_mode ?? 'complete',
                    'customer_label' => $user->organization->customerLabelKey(),
                    'sales_import_open' => $user->organization->salesImportOpen(),
                    'has_agenda' => $user->organization->hasAgenda(),
                    'sells_products' => $user->organization->sellsProducts(),
                    'currency' => $user->organization->currency ?? 'XAF',
                    'currency_symbol' => $user->organization->currencySymbol(),
                    'analysis_level' => $user->organization->analysisLevel(),
                    // Modules à la carte actifs (menu, fiches et réglages filtrés).
                    'modules' => $user->organization->enabledModules(),
                    'beneficiary_label' => $user->organization->beneficiaryLabel(),
                    'beneficiaries_label' => $user->organization->beneficiaryLabel(true),
                ] : null,
            ],
            // « Besoin d'aide ? » : support WhatsApp de Menentia, sinon email.
            'support' => fn () => $user ? (filled(config('services.menentia.support_whatsapp'))
                ? 'https://wa.me/'.preg_replace('/\D+/', '', (string) config('services.menentia.support_whatsapp')).'?text='.rawurlencode("Bonjour Menentia, j'ai une question sur mon compte {$user->organization?->name} : ")
                : 'mailto:'.config('services.menentia.support_email')) : null,
            // Tarifs indicatifs par message, pour le coût estimé avant envoi d'une campagne.
            'whatsappPricing' => fn () => $user ? config('services.meta_whatsapp.pricing') : null,
            // Crédits de messages NENTIX (null = sans limite) : estimation et blocage avant une campagne.
            'messageCredits' => fn () => $user?->organization?->hasCreditLimit()
                ? ['balance' => (int) $user->organization->message_credits, 'cost' => $user->organization->marketingMessageCost()]
                : null,
            // Cloche « À traiter » (cf. NotificationService), calculée seulement si la page la demande.
            'notifications' => fn () => $user ? app(NotificationService::class)->forUser($user) : null,
        ];
    }
}
