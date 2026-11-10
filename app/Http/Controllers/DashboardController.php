<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\Onboarding;
use App\Services\ProspectPipeline;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accueil : l'essentiel pour commencer la journée. « Pour bien démarrer »
 * tant qu'il n'est pas terminé, 4 chiffres clés adaptés au secteur, puis les
 * actions du jour (demandes et discussions à traiter, listes de « À faire »).
 *
 * Les analyses détaillées sont ailleurs : CA par boutique et par marque dans
 * Analyses › Tableaux de bord › Réseau, prestations et produits dans
 * Analyses › Tableaux de bord, contacts / opt-in / VIP / WhatsApp par
 * établissement dans Paramètres › Établissements, sources dans Analyses ›
 * Sources, parcours client dans Clients.
 */
class DashboardController extends Controller
{
    /** Listes de « À faire » montrées sur l'Accueil (au plus). */
    private const ACTION_LIMIT = 6;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = $user->organization;
        $visible = $user->visibleStoreIds();
        $storeIds = Store::where('organization_id', $organization->id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->pluck('id')->all();

        $lists = new ActionLists($user);
        $available = collect(ActionLists::LISTS)
            ->reject(fn ($list) => ! empty($list['multibrand']) || ! empty($list['needs_group']))
            ->reject(fn ($list, $key) => in_array($key, ['appointment_reminder', 'no_show'], true) && ! $organization->hasAgenda())
            ->reject(fn ($list) => isset($list['modules']) && ! collect($list['modules'])->contains(fn ($m) => $organization->hasModule($m)));
        $counts = $available->keys()->mapWithKeys(fn ($key) => [$key => count($lists->rows($key))]);

        return Inertia::render('Dashboard/Index', [
            // « Pour bien démarrer » : étapes selon le secteur et les modules, cochées toutes seules.
            'onboarding' => $user->canManageOrganization() ? (new Onboarding($organization))->forDashboard() : null,
            'kpis' => $this->kpis($user, $storeIds, $counts->all()),
            'todo' => [
                'discussions' => Contact::whereIn('store_id', $storeIds)->where('needs_attention', true)->count(),
                'tasks' => Task::where('organization_id', $organization->id)->whereNull('done_at')
                    ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $storeIds))->count(),
            ],
            'actions' => $counts->filter()->except('tasks')->sortDesc()->take(self::ACTION_LIMIT)
                ->map(fn ($count, $key) => ['key' => $key, 'label' => ActionLists::LISTS[$key]['label'], 'description' => ActionLists::LISTS[$key]['description'], 'count' => $count])
                ->values(),
            'today' => $organization->hasAgenda() ? $this->todayAppointments($storeIds) : [],
        ]);
    }

    /**
     * Quatre chiffres clés selon le secteur.
     *
     * @param  array<int, int>  $storeIds
     * @param  array<string, int>  $counts  lignes de chaque liste de « À faire »
     * @return list<array{key: string, label: string, value: int, money?: bool, foot?: string, href: string, tone: string}>
     */
    private function kpis(User $user, array $storeIds, array $counts): array
    {
        $organization = $user->organization;
        $tz = $organization->timezone ?: config('app.timezone');
        $today = now($tz);
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $dayBounds = [$today->copy()->startOfDay()->setTimezone(config('app.timezone')), $today->copy()->endOfDay()->setTimezone(config('app.timezone'))];

        $revenue = fn () => ['key' => 'revenue', 'label' => 'CA du mois', 'value' => (int) Purchase::whereIn('store_id', $storeIds)->where('purchased_at', '>=', $monthStart)->sum('amount'), 'money' => true,
            'foot' => Purchase::whereIn('store_id', $storeIds)->where('purchased_at', '>=', $monthStart)->count().' vente(s)', 'href' => '/purchases', 'tone' => 'blue'];
        $appointmentsToday = fn () => ['key' => 'appointments_today', 'label' => $organization->hasModule('abonnements') ? 'Séances du jour' : 'RDV du jour',
            'value' => Appointment::whereIn('store_id', $storeIds)->whereIn('status', [...Appointment::OPEN_STATUSES, 'done'])->whereBetween('starts_at', $dayBounds)->count(), 'href' => '/appointments', 'tone' => 'cyan'];
        $toConfirm = fn () => ['key' => 'to_confirm', 'label' => 'RDV à confirmer',
            'value' => Appointment::whereIn('store_id', $storeIds)->whereIn('status', ['requested', 'planned'])->whereBetween('starts_at', [now(), now()->addDays(2)])->count(),
            'foot' => 'aujourd’hui et demain', 'href' => '/appointments', 'tone' => 'amber'];
        $toRecall = fn (string $label = 'Clients à relancer') => ['key' => 'to_recall', 'label' => $label,
            'value' => array_sum(array_intersect_key($counts, array_flip(['at_risk', 'second_purchase', 'win_back', 'service_cycle', 'prospect_followup', 'quote_followup', 'absent_members', 'subscription_ending']))),
            'foot' => 'dans « À faire »', 'href' => '/actions', 'tone' => 'rose'];
        $newContacts = fn () => ['key' => 'new_contacts', 'label' => 'Nouveaux contacts',
            'value' => Customer::where('organization_id', $organization->id)->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $storeIds))->where('created_at', '>=', now()->subDays(30))->count(),
            'foot' => '30 derniers jours', 'href' => '/customers', 'tone' => 'emerald'];

        return match ($organization->sector) {
            'beaute' => [$appointmentsToday(), $toConfirm(), $toRecall('Clientes à relancer'), $revenue()],
            'sport' => [
                $appointmentsToday(),
                ['key' => 'active_subscriptions', 'label' => 'Abonnés actifs', 'value' => Subscription::whereIn('store_id', $storeIds)->where('status', Subscription::STATUS_ACTIVE)
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()))->count(), 'href' => '/plans', 'tone' => 'emerald'],
                ['key' => 'overdue', 'label' => 'Paiements en retard', 'value' => Installment::overdue()->whereHas('schedule', fn ($q) => $q->whereIn('store_id', $storeIds))->count(), 'href' => '/installments', 'tone' => 'rose'],
                $newContacts(),
            ],
            'pro' => [
                ['key' => 'open_prospects', 'label' => 'Prospects en cours', 'value' => $this->openProspects($user, $storeIds), 'href' => '/prospects', 'tone' => 'cyan'],
                ['key' => 'quotes', 'label' => 'Devis à relancer', 'value' => (int) ($counts['quote_followup'] ?? 0), 'href' => '/actions?list=quote_followup', 'tone' => 'amber'],
                ['key' => 'to_recall', 'label' => 'Prospects à relancer', 'value' => (int) ($counts['prospect_followup'] ?? 0), 'foot' => 'délai de relance dépassé', 'href' => '/actions?list=prospect_followup', 'tone' => 'rose'],
                $revenue(),
            ],
            'commerce' => [
                $revenue(),
                ['key' => 'buyers', 'label' => 'Clients acheteurs', 'value' => Purchase::whereIn('store_id', $storeIds)->where('purchased_at', '>=', $monthStart)->whereNotNull('contact_id')->distinct()->count('contact_id'),
                    'foot' => 'ce mois-ci', 'href' => '/customers', 'tone' => 'cyan'],
                ['key' => 'at_risk', 'label' => 'Clientes à risque', 'value' => (int) ($counts['at_risk'] ?? 0), 'href' => '/actions?list=at_risk', 'tone' => 'rose'],
                $newContacts(),
            ],
            default => $organization->hasAgenda()
                ? [$appointmentsToday(), $toConfirm(), $toRecall(), $revenue()]
                : [$revenue(), $toRecall(), $newContacts(), ['key' => 'buyers', 'label' => 'Clients acheteurs', 'value' => Purchase::whereIn('store_id', $storeIds)->where('purchased_at', '>=', $monthStart)->whereNotNull('contact_id')->distinct()->count('contact_id'), 'foot' => 'ce mois-ci', 'href' => '/customers', 'tone' => 'amber']],
        };
    }

    /** @param  array<int, int>  $storeIds */
    private function openProspects(User $user, array $storeIds): int
    {
        $pipeline = ProspectPipeline::for($user->organization);
        $open = $pipeline->stages()->reject(fn ($s) => in_array($s['kind'], ['gagne', 'perdu'], true))->pluck('key');

        return Customer::where('organization_id', $user->organization_id)
            ->whereIn('prospect_stage', $open)
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->when($user->ownRecordsOnly(), fn ($q) => $q->where('owner_user_id', $user->id))
            ->count();
    }

    /**
     * Prochains rendez-vous du jour.
     *
     * @param  array<int, int>  $storeIds
     * @return list<array<string, mixed>>
     */
    private function todayAppointments(array $storeIds): array
    {
        return Appointment::whereIn('store_id', $storeIds)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->whereBetween('starts_at', [now(), now()->endOfDay()])
            ->with(['customer:id,name', 'staffMember:id,name'])
            ->orderBy('starts_at')
            ->limit(6)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'customer_id' => $a->customer_id,
                'customer' => $a->customer?->name,
                'time' => $a->starts_at->format('H\hi'),
                'service' => $a->service,
                'staff' => $a->staffMember?->name,
                'status' => $a->status,
            ])
            ->all();
    }
}
