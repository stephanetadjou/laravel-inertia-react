<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord consolidé de l'organisation (cahier des charges §2.2).
 * Volontairement limité à des compteurs simples : les analytics avancés
 * (RFM, CA attribué, évolution dans le temps) sont hors périmètre MVP (§3.8/§7.3).
 *
 * Le filtre de dates en haut de page ne touche que les sections temporelles
 * (dernières campagnes, achats de la période, clients récents) — les
 * compteurs globaux (total contacts, boutiques connectées...) restent des
 * totaux actuels, non filtrés.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organizationId = $user->organization_id;
        $visibleIds = $user->visibleStoreIds();

        [$from, $to] = $this->resolvePeriod($request);

        $stores = Store::where('organization_id', $organizationId)
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->withCount('contacts')
            ->orderBy('name')
            ->get();

        $organizationCampaigns = Campaign::whereHas(
            'stores',
            fn ($q) => $q->where('organization_id', $organizationId)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('stores.id', $visibleIds)),
        );

        $scopedContacts = Contact::whereHas(
            'store',
            fn ($q) => $q->where('organization_id', $organizationId)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds)),
        );

        $totalContacts = (clone $scopedContacts)->count();

        $consentCounts = (clone $scopedContacts)
            ->selectRaw('consent_status, count(*) as total')
            ->groupBy('consent_status')
            ->pluck('total', 'consent_status');

        $totalSent = (clone $organizationCampaigns)->sum('sent_count');
        $totalFailed = (clone $organizationCampaigns)->sum('failed_count');
        $totalAttempted = $totalSent + $totalFailed;

        $recentCampaigns = (clone $organizationCampaigns)
            ->whereBetween('created_at', [$from, $to])
            ->with('stores')
            ->withCount('campaignMessages as total')
            ->latest()
            ->take(5)
            ->get();

        $periodPurchases = Purchase::whereHas(
            'store',
            fn ($q) => $q->where('organization_id', $organizationId)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds)),
        )->whereBetween('purchased_at', [$from, $to]);

        $recentClients = (clone $scopedContacts)
            ->with('store')
            ->whereBetween('created_at', [$from, $to])
            ->where('consent_status', 'unknown')
            ->whereNull('welcome_message_sent_at')
            ->latest()
            ->take(10)
            ->get();

        return Inertia::render('Dashboard/Index', [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'stats' => [
                'total_contacts' => $totalContacts,
                'opt_in_contacts' => (int) ($consentCounts['opt_in'] ?? 0),
                'opt_out_contacts' => (int) ($consentCounts['opt_out'] ?? 0),
                'unknown_contacts' => (int) ($consentCounts['unknown'] ?? 0),
                'total_stores' => $stores->count(),
                'connected_stores' => $stores->filter(fn (Store $store) => $store->whatsapp_access_token && $store->whatsapp_phone_number_id)->count(),
                'total_campaigns' => (clone $organizationCampaigns)->count(),
                'active_campaigns' => (clone $organizationCampaigns)->whereIn('status', ['queued', 'sending'])->count(),
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'delivery_rate' => $totalAttempted > 0 ? round($totalSent / $totalAttempted * 100) : 0,
                'period_purchases_count' => (clone $periodPurchases)->count(),
                'period_purchases_amount' => (clone $periodPurchases)->sum('amount'),
            ],
            'stores' => $stores->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'contacts_count' => $store->contacts_count,
                'connected' => (bool) ($store->whatsapp_access_token && $store->whatsapp_phone_number_id),
            ]),
            'recentCampaigns' => $recentCampaigns->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name ?: $campaign->template_name,
                'store_names' => $campaign->stores->pluck('name')->implode(', '),
                'status' => $campaign->status,
                'sent_count' => $campaign->sent_count,
                'failed_count' => $campaign->failed_count,
                'total' => $campaign->total,
                'created_at' => $campaign->created_at->format('d/m/Y H:i'),
            ]),
            'recentClients' => $recentClients->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone_e164' => $contact->phone_e164,
                'store_name' => $contact->store->name,
                'created_at' => $contact->created_at->format('d/m/Y'),
            ]),
            'welcomeTemplates' => MessageTemplate::where('organization_id', $organizationId)
                ->where('is_active', true)
                ->where('category', 'utility')
                ->orderBy('name')
                ->get(['id', 'name', 'language']),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [
                Carbon::parse((string) $request->string('from'))->startOfDay(),
                Carbon::parse((string) $request->string('to'))->endOfDay(),
            ];
        }

        return [now()->subDays(29)->startOfDay(), now()->endOfDay()];
    }
}
