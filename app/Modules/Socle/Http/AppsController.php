<?php

namespace App\Modules\Socle\Http;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Modules\Depenses\Models\Expense;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Socle\Services\CustomerPulse;
use App\Modules\Stock\Models\StockItem;
use App\Services\NotificationService;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lanceur d'applications : chaque grande fonction de ClientlyX est une
 * application avec son propre espace. Chaque tuile porte un chiffre
 * centré client (à relancer, en retard, servis aujourd'hui…).
 */
class AppsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Apps/Index', [
            'badges' => $this->badges($request),
            'locked' => $this->lockedApps($request->user()->organization),
            'greeting' => $this->greeting(),
            'day' => app(CustomerPulse::class)->day($request->user()),
        ]);
    }

    /** Toutes les applications : actives, puis modules disponibles à ajouter. */
    public function all(Request $request): Response
    {
        $organization = $request->user()->organization;
        $available = collect(ModuleRegistry::all())
            ->filter(fn ($m) => ! $organization->hasModule($m['key']) && $m['key'] !== 'socle')
            ->map(fn ($m) => ['key' => $m['key'], 'label' => $m['label'], 'description' => $m['description'], 'pack' => Organization::PLANS[$m['pack']]['label'] ?? $m['pack'], 'allowed' => $organization->packAllows($m['key'])])
            ->values();

        return Inertia::render('Apps/All', ['badges' => $this->badges($request), 'available' => $available, 'canManage' => $request->user()->canManageOrganization()]);
    }

    /** @return array<string, array<string, mixed>> */
    private function badges(Request $request): array
    {
        $user = $request->user();
        $organization = $user->organization;
        $visible = $user->visibleStoreIds();
        $storeIds = Store::where('organization_id', $organization->id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->pluck('id')->all();
        $badges = [];

        $notifications = app(NotificationService::class)->forUser($user);
        $badges['actions'] = ['value' => (int) ($notifications['total'] ?? 0), 'label' => 'à traiter'];

        $badges['customers'] = ['value' => Customer::where('organization_id', $organization->id)->where('lifecycle_stage', Customer::STAGE_AT_RISK)->count(), 'label' => 'à risque'];

        $badges['sales'] = ['value' => (int) Purchase::whereIn('store_id', $storeIds)->where('status', Purchase::STATUS_VALID)->where('purchased_at', '>=', today())->sum('amount'), 'label' => 'F aujourd’hui', 'money' => true];

        if ($organization->hasModule('factures')) {
            $badges['invoices'] = ['value' => Invoice::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('type', Invoice::TYPE_INVOICE)
                ->whereIn('status', ['open', 'partial'])->where('due_on', '<', today())->count(), 'label' => 'en retard', 'tone' => 'bad'];
        }

        if ($organization->hasModule('caisse')) {
            $badges['cash'] = ['value' => Purchase::whereIn('store_id', $storeIds)->where('status', Purchase::STATUS_VALID)->where('purchased_at', '>=', today())->count(), 'label' => 'ventes du jour'];
        }

        if ($organization->hasModule('stock')) {
            $badges['stock'] = ['value' => StockItem::where('organization_id', $organization->id)->whereColumn('quantity', '<=', 'min_quantity')->count(), 'label' => 'à réassortir', 'tone' => 'bad'];
        }

        if ($organization->hasModule('depenses')) {
            $badges['expenses'] = ['value' => (int) Expense::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('spent_on', '>=', today()->startOfMonth())->sum('amount'), 'label' => 'F ce mois', 'money' => true];
        }

        return $badges;
    }

    /** Pouls client de l'application ouverte (bandeau sous l'en-tête). */
    public function pulse(Request $request): JsonResponse
    {
        return response()->json(['items' => app(CustomerPulse::class)->forApp($request->user(), (string) $request->query('app', ''))]);
    }

    /** Recherche rapide (Ctrl+K) : clients par nom ou téléphone. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }
        $digits = preg_replace('/\D+/', '', $q);
        $visible = $request->user()->visibleStoreIds();
        $customers = Customer::where('organization_id', $request->user()->organization_id)
            ->when($visible !== null, fn ($w) => $w->whereHas('contacts', fn ($c) => $c->whereIn('store_id', $visible)))
            ->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('phone_e164', 'like', '%'.$digits.'%')))
            ->orderBy('name')->limit(6)->get(['id', 'name', 'phone_e164', 'lifecycle_stage']);

        return response()->json(['customers' => $customers]);
    }

    /** Automatisations WhatsApp et IA d'une application, avec leur état. */
    public function automations(Request $request): Response
    {
        $organization = $request->user()->organization;
        $app = (string) $request->query('app', '');

        $items = collect(ModuleRegistry::all())
            ->filter(fn ($m) => $m['automation'] !== null && ($app === '' || $m['app'] === $app))
            ->map(fn ($m) => [
                'key' => $m['key'],
                'label' => $m['label'],
                'description' => $m['description'],
                'channel' => $m['automation'],
                'enabled' => $organization->hasModule($m['key']),
                'pack' => Organization::PLANS[$m['pack']]['label'] ?? $m['pack'],
                'allowed' => $organization->automationAllowed() && $organization->packAllows($m['key']),
            ])->values();

        return Inertia::render('Apps/Automations', ['app' => $app, 'items' => $items, 'canManage' => $request->user()->canManageOrganization()]);
    }

    /** Applications hors du pack (affichées avec un cadenas). @return array<string, string> */
    private function lockedApps(Organization $organization): array
    {
        $locked = [];

        foreach (ModuleRegistry::all() as $module) {
            if ($module['app'] && ! $organization->packAllows($module['key']) && ! isset($locked[$module['app']])) {
                $locked[$module['app']] = Organization::PLANS[$module['pack']]['label'] ?? $module['pack'];
            }
        }

        return $locked;
    }

    private function greeting(): string
    {
        $hour = (int) now()->format('G');

        return $hour < 12 ? 'Bonjour' : ($hour < 18 ? 'Bon après-midi' : 'Bonsoir');
    }
}
