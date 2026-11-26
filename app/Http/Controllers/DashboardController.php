<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\LearnerExam;
use App\Models\LearnerTraining;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Rental;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Location\Models\VehicleRequest;
use App\Services\ActionLists;
use App\Services\Fleet;
use App\Services\HomeOverview;
use App\Services\Occupation;
use App\Services\Onboarding;
use App\Services\ProspectPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accueil (page d'arrivée, tous secteurs) : vue globale de l'activité,
 * actions du jour, recommandations, comparaison des boutiques et tuiles du
 * secteur (App\Services\HomeOverview). Ancien résumé :
 * l'essentiel pour commencer la journée. « Pour bien démarrer »
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
        $stores = Store::where('organization_id', $organization->id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('name')->get(['id', 'name']);
        $visibleIds = $stores->pluck('id')->all();
        // Filtre boutique : seulement parmi les boutiques visibles.
        $storeId = $stores->contains('id', $request->integer('store')) ? $request->integer('store') : null;
        $storeIds = $storeId ? [$storeId] : $visibleIds;
        $period = (string) $request->query('period', 'month');

        $lists = new ActionLists($user);
        $available = collect(ActionLists::LISTS)
            ->reject(fn ($list) => ! empty($list['multibrand']) || ! empty($list['needs_group']))
            ->reject(fn ($list, $key) => in_array($key, ['appointment_reminder', 'no_show'], true) && ! $organization->hasAgenda())
            ->reject(fn ($list) => isset($list['modules']) && ! collect($list['modules'])->contains(fn ($m) => $organization->hasModule($m)));
        $counts = $available->keys()->mapWithKeys(fn ($key) => [$key => count($lists->rows($key))]);
        $overview = new HomeOverview($user, $storeIds, $visibleIds, $period);
        $activity = $overview->activity();

        return Inertia::render('Dashboard/Index', [
            // « Pour bien démarrer » : étapes selon le secteur et les modules, cochées toutes seules.
            'onboarding' => $user->canManageOrganization() ? (new Onboarding($organization))->forDashboard() : null,
            'header' => [
                'first_name' => Str::of((string) $user->name)->trim()->explode(' ')->first(),
                'organization' => $organization->name,
                'stores_count' => count($visibleIds),
            ],
            'filters' => ['period' => $activity['period'], 'store' => $storeId],
            'periods' => HomeOverview::periodOptions(),
            'stores' => count($visibleIds) > 1 ? $stores->values() : [],
            // Bloc 1 « Mon activité » : 6 chiffres comparés au mois précédent et à N-1.
            'activity' => $activity,
            // Bloc 2 « À faire aujourd'hui ».
            'todo' => $overview->todo($counts->all()),
            // Bloc 3 « Recommandations » : trois audiences de Fiches clients.
            'recommendations' => $overview->recommendations(),
            // Bloc 4 « Comparaison des boutiques » : seulement si plusieurs boutiques visibles.
            'comparison' => $storeId === null ? $overview->stores() : null,
            // Bloc 5 « Mon secteur » : tuiles propres au secteur.
            'sector' => $organization->sector === 'commerce'
                ? $overview->commerceTiles()
                : array_values(array_filter($this->kpis($user, $storeIds, $counts->all()), fn ($k) => ! in_array($k['key'], ['revenue', 'buyers', 'new_contacts'], true))),
            'today' => $organization->hasAgenda() ? $this->todayAppointments($storeIds) : [],
            // Moteur d'occupation : la semaine en cours et les creux / saturations à traiter.
            'occupation' => Occupation::applies($organization) ? $this->occupation($organization, $storeIds) : null,
        ]);
    }

    /**
     * @param  array<int, int>  $storeIds
     * @return array<string, mixed>
     */
    private function occupation(Organization $organization, array $storeIds): array
    {
        $engine = new Occupation($organization, $storeIds);
        $week = $engine->period(today()->startOfWeek(), today()->startOfWeek()->addWeek());
        $signals = $engine->signals();

        return [
            'rate' => $week['rate'],
            'free_hours' => $week['free_hours'],
            'classes' => $week['classes'],
            'heatmap' => $week['heatmap'],
            'resources' => array_slice($week['resources'], 0, 5),
            'signals' => array_slice($signals, 0, 6),
            'signal_count' => count($signals),
        ];
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

        // Clients acheteurs du mois : des fiches clients (une personne peut avoir un contact par boutique).
        $buyers = fn () => Purchase::whereIn('purchases.store_id', $storeIds)->where('purchased_at', '>=', $monthStart)
            ->join('contacts', 'contacts.id', '=', 'purchases.contact_id')->distinct()->count('contacts.customer_id');
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
            'formation' => [
                [...$appointmentsToday(), 'label' => 'Leçons du jour'],
                ['key' => 'learners', 'label' => 'Apprenants en formation', 'value' => LearnerTraining::active()->whereIn('store_id', $storeIds)->count(), 'href' => '/learners', 'tone' => 'emerald'],
                ['key' => 'exams', 'label' => 'Examens à venir', 'value' => LearnerExam::where('result', 'scheduled')->whereHas('learnerTraining', fn ($q) => $q->whereIn('store_id', $storeIds))
                    ->where('exam_on', '>=', today()->toDateString())->where('exam_on', '<=', today()->addDays(30)->toDateString())->count(), 'foot' => '30 prochains jours', 'href' => '/learners?exam=1', 'tone' => 'amber'],
                ['key' => 'dropping', 'label' => 'Apprenants qui décrochent', 'value' => (int) ($counts['learners_dropping'] ?? 0), 'href' => '/actions?list=learners_dropping', 'tone' => 'rose'],
            ],
            'vehicules' => [
                ['key' => 'vehicles_out', 'label' => 'Véhicules sortis', 'value' => Rental::whereIn('store_id', $storeIds)->where('status', 'ongoing')->count(), 'href' => '/vehicles', 'tone' => 'cyan'],
                ['key' => 'vehicles_free', 'label' => 'Libres demain', 'value' => Fleet::availableOn($organization->id, today()->addDay(), $storeIds)->count(),
                    'foot' => 'stock de sécurité : '.$organization->occupationSettings()['safety_stock'], 'href' => '/vehicles', 'tone' => 'emerald'],
                ['key' => 'late_returns', 'label' => 'Retours en retard', 'value' => Rental::whereIn('store_id', $storeIds)->where('status', 'ongoing')->where('ends_at', '<', now())->count(), 'href' => '/actions?list=rental_late', 'tone' => 'rose'],
                $revenue(),
            ],
            'location' => [
                ['key' => 'requests', 'label' => 'Demandes à traiter', 'value' => VehicleRequest::whereIn('store_id', $storeIds)->whereIn('status', ['new', 'quoted'])->count(), 'href' => '/location/requests', 'tone' => 'amber'],
                ['key' => 'vehicles_out', 'label' => 'Véhicules sortis', 'value' => Rental::whereIn('store_id', $storeIds)->where('status', 'ongoing')->count(), 'href' => '/location/planning', 'tone' => 'cyan'],
                ['key' => 'vehicles_free', 'label' => 'Libres demain', 'value' => Fleet::availableOn($organization->id, today()->addDay(), $storeIds)->count(), 'href' => '/location/planning', 'tone' => 'emerald'],
                $revenue(),
            ],
            'pro' => [
                ['key' => 'open_prospects', 'label' => 'Prospects en cours', 'value' => $this->openProspects($user, $storeIds), 'href' => '/prospects', 'tone' => 'cyan'],
                ['key' => 'quotes', 'label' => 'Devis à relancer', 'value' => (int) ($counts['quote_followup'] ?? 0), 'href' => '/actions?list=quote_followup', 'tone' => 'amber'],
                ['key' => 'to_recall', 'label' => 'Prospects à relancer', 'value' => (int) ($counts['prospect_followup'] ?? 0), 'foot' => 'délai de relance dépassé', 'href' => '/actions?list=prospect_followup', 'tone' => 'rose'],
                $revenue(),
            ],
            'commerce' => [
                $revenue(),
                ['key' => 'buyers', 'label' => 'Clients acheteurs', 'value' => $buyers(),
                    'foot' => 'ce mois-ci', 'href' => '/customers', 'tone' => 'cyan'],
                ['key' => 'at_risk', 'label' => 'Clientes à risque', 'value' => (int) ($counts['at_risk'] ?? 0), 'href' => '/actions?list=at_risk', 'tone' => 'rose'],
                $newContacts(),
            ],
            default => $organization->hasAgenda()
                ? [$appointmentsToday(), $toConfirm(), $toRecall(), $revenue()]
                : [$revenue(), $toRecall(), $newContacts(), ['key' => 'buyers', 'label' => 'Clients acheteurs', 'value' => $buyers(), 'foot' => 'ce mois-ci', 'href' => '/customers', 'tone' => 'amber']],
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
