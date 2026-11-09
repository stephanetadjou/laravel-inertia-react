<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listes d'actions du jour : quels clients relancer, pourquoi, avec quel
 * message. Chaque ligne a un lien wa.me qui ouvre WhatsApp avec le message
 * prérempli ; l'envoi se fait à la main depuis le téléphone de
 * l'établissement, sans l'API WhatsApp.
 *
 * Une relance enregistrée (CustomerFollowUp) retire le client de la liste
 * pendant le délai de la liste. Un client qui a répondu « Stop » partout
 * (opt-out sur tous ses contacts du périmètre) n'apparaît dans aucune liste.
 */
class ActionLists
{
    public const LISTS = [
        'tasks' => [
            'label' => 'Demandes à traiter',
            'description' => 'Clients qui veulent parler à quelqu’un, être rappelés ou décaler leur rendez-vous (réponses WhatsApp).',
            'cooldown_days' => null,
        ],
        'at_risk' => [
            'label' => 'Clients à risque à relancer',
            'description' => "Bons clients en retard sur leur rythme d'achat habituel.",
            'cooldown_days' => 14,
        ],
        'second_purchase' => [
            'label' => 'Nouveaux clients à fidéliser',
            'description' => 'Un seul achat il y a 7 à 45 jours : le moment de déclencher le deuxième.',
            'cooldown_days' => 30,
        ],
        'win_back' => [
            'label' => 'Clients inactifs à reconquérir',
            'description' => 'Plus aucun achat depuis longtemps : une relance de dernière chance.',
            'cooldown_days' => 30,
        ],
        'vip_thanks' => [
            'label' => 'Meilleurs clients à remercier',
            'description' => 'Segment Champions : remerciez-les et proposez-leur les nouveautés.',
            'cooldown_days' => 60,
        ],
        'appointment_reminder' => [
            'label' => 'Rendez-vous à rappeler',
            'description' => "Rendez-vous d'aujourd'hui et de demain, pas encore rappelés.",
            'cooldown_days' => null,
        ],
        'no_show' => [
            'label' => 'Rendez-vous manqués à reprogrammer',
            'description' => 'Clients absents à leur rendez-vous ces 30 derniers jours, sans nouveau rendez-vous.',
            'cooldown_days' => null,
        ],
        'birthday' => [
            'label' => 'Anniversaires de la semaine',
            'description' => 'Clients dont l\'anniversaire tombe dans les 7 prochains jours.',
            'cooldown_days' => 300,
        ],
        'group' => [
            'label' => 'Groupe de clients',
            'description' => 'Les clients d\'un groupe préparé depuis une analyse (segment, famille, multimarque…) : relance à la main, client par client.',
            'cooldown_days' => 7,
            'needs_group' => true,
        ],
        'cross_sell' => [
            'label' => 'Vente croisée entre marques',
            'description' => 'Clients fidèles d\'une marque du groupe, jamais venus dans une autre : faites-la-leur découvrir.',
            'cooldown_days' => 60,
            'multibrand' => true,
        ],
        'absent_members' => [
            'label' => 'Absences répétées',
            'description' => 'Inscrits absents au moins 2 fois ces 30 derniers jours : prenez des nouvelles avant qu’ils décrochent.',
            'cooldown_days' => 14,
            'modules' => ['seances_groupe'],
        ],
        'subscription_ending' => [
            'label' => 'Abonnements à renouveler',
            'description' => 'Abonnements qui se terminent dans les 15 prochains jours : proposez le renouvellement.',
            'cooldown_days' => 20,
            'modules' => ['abonnements'],
        ],
        'prospect_followup' => [
            'label' => 'Prospects à relancer',
            'description' => 'Prospects restés dans leur étape plus longtemps que le délai de relance (Paramètres › Prospects).',
            'cooldown_days' => null,
            'modules' => ['prospects'],
        ],
        'class_cancelled' => [
            'label' => 'Cours annulés à annoncer',
            'description' => 'Séances annulées ces 7 derniers jours (pluie, coach absent…) : prévenez chaque inscrit, ou son parent.',
            'cooldown_days' => null,
            'modules' => ['seances_groupe'],
        ],
        'class_makeup' => [
            'label' => 'Rattrapages à annoncer',
            'description' => 'Séances de rattrapage programmées : annoncez la date aux mêmes personnes.',
            'cooldown_days' => null,
            'modules' => ['seances_groupe'],
        ],
        'service_cycle' => [
            'label' => 'Clientes à faire revenir',
            'description' => 'Le délai « revenir après N jours » de leur dernière prestation est passé, sans nouveau rendez-vous.',
            'cooldown_days' => 14,
            'modules' => ['relance_cycle'],
        ],
        'quote_followup' => [
            'label' => 'Devis à relancer',
            'description' => 'Devis envoyés il y a 3 jours ou plus, sans réponse : une relance prête à envoyer.',
            'cooldown_days' => null,
            'modules' => ['relance_devis'],
        ],
        'installment_due' => [
            'label' => 'Échéances à rappeler',
            'description' => 'Tranches à payer bientôt ou en retard, aux dates de relance choisies dans Paramètres › Modules (ex. 2 jours avant, le jour même, 7 jours après).',
            'cooldown_days' => null,
            'modules' => ['tranches', 'abonnements'],
        ],
    ];

    /**
     * Variables : {prenom}, {nom}, {boutique}, {date_rdv}, {heure_rdv}, {prestation} ;
     * {beneficiaire} = prénom de la personne suivie quand on écrit à son responsable.
     */
    public const DEFAULT_MESSAGES = [
        'tasks' => 'Bonjour {prenom}, ici {boutique}. Vous nous avez demandé de vous recontacter : comment pouvons-nous vous aider ?',
        'service_cycle' => 'Bonjour {prenom}, cela fait {jours} jours depuis votre {prestation} chez {boutique}. C’est le bon moment pour revenir : on vous réserve un créneau ?',
        'quote_followup' => 'Bonjour {prenom}, ici {boutique}. Avez-vous pu regarder notre devis ? Je reste disponible pour en parler ou l’ajuster à votre besoin.',
        'at_risk' => 'Bonjour {prenom}, cela fait un moment que nous ne vous avons pas vu chez {boutique}. Nous avons de nouvelles choses qui devraient vous plaire : passez nous voir, nous serons ravis de vous accueillir !',
        'second_purchase' => 'Bonjour {prenom}, merci encore pour votre visite chez {boutique}. Tout vous convient ? Nous restons à votre disposition, et serons heureux de vous revoir très vite.',
        'win_back' => 'Bonjour {prenom}, vous nous manquez chez {boutique} ! Revenez nous voir, une attention vous attend lors de votre prochaine visite.',
        'vip_thanks' => 'Bonjour {prenom}, toute l\'équipe de {boutique} vous remercie pour votre fidélité. Vous faites partie de nos meilleurs clients : vous serez parmi les premiers informés de nos nouveautés.',
        'appointment_reminder' => 'Bonjour {prenom}, nous vous rappelons votre rendez-vous chez {boutique} le {date_rdv} à {heure_rdv}. Pouvez-vous nous confirmer votre présence ?',
        'no_show' => 'Bonjour {prenom}, nous ne vous avons pas vu à votre rendez-vous chez {boutique} le {date_rdv}. Souhaitez-vous en fixer un nouveau ?',
        'birthday' => 'Joyeux anniversaire {prenom} ! Toute l\'équipe de {boutique} vous souhaite une excellente journée.',
        'group' => 'Bonjour {prenom}, toute l\'équipe de {boutique} a pensé à vous : passez nous voir cette semaine, une attention vous attend.',
        'cross_sell' => 'Bonjour {prenom}, merci pour votre fidélité chez {boutique}. Saviez-vous que {autre_marque} fait aussi partie de notre groupe ? Passez découvrir la boutique, vous y serez accueilli comme un client fidèle.',
        'absent_members' => 'Bonjour {prenom}, tout va bien ? Nous avons remarqué des absences aux derniers cours{de_beneficiaire}. Dites-nous si nous pouvons aider : nous serons ravis de vous revoir très vite !',
        'subscription_ending' => 'Bonjour {prenom}, l’abonnement « {formule} »{de_beneficiaire} se termine le {fin}. Souhaitez-vous le renouveler ? Nous pouvons le préparer dès maintenant.',
        'prospect_followup' => 'Bonjour {prenom}, ici {boutique}. Je reviens vers vous suite à notre dernier échange : avez-vous pu y réfléchir ? Je reste disponible pour en parler ou vous montrer comment ça marche.',
        'class_cancelled' => 'Bonjour {prenom}, le cours de {cours} du {date_cours} à {heure_cours} est annulé ({motif}). Nous vous tiendrons au courant du rattrapage. Merci de votre compréhension !',
        'class_makeup' => 'Bonjour {prenom}, bonne nouvelle : le cours de {cours} annulé est rattrapé le {date_cours} à {heure_cours}. À bientôt !',
        'installment_due' => 'Bonjour {prenom}, petit rappel de {boutique} : l\'échéance de {montant} ({objet}) est à régler le {echeance}. Merci de votre confiance !',
    ];

    /** Segments considérés comme « fidèles » pour la vente croisée. */
    public const CROSS_SELL_SEGMENTS = ['champions', 'loyal', 'cant_lose'];

    /** Nombre maximum de lignes affichées par liste. */
    public const LIMIT = 200;

    /** @var array<int, int> */
    private array $storeIds;

    /** @var array<string, string> */
    private array $messages;

    /** Marques du périmètre (vente croisée : marques proposées). @var array<int, int> */
    private array $brandIds;

    /**
     * @param  int|null  $brandId  marque choisie : listes limitées à ses points de vente
     *                             (et, en vente croisée, ses clients fidèles)
     */
    public function __construct(private User $user, ?int $storeId = null, private ?int $brandId = null, private ?int $groupId = null)
    {
        $visible = $user->visibleStoreIds();
        $scope = Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible));
        $this->brandIds = (clone $scope)->distinct()->pluck('brand_id')->all();

        $this->storeIds = $scope
            ->when($storeId !== null, fn ($q) => $q->whereKey($storeId))
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->pluck('id')
            ->all();
        $this->messages = $user->organization->actionMessages();
    }

    /** @return array<int, int> */
    public function storeIds(): array
    {
        return $this->storeIds;
    }

    /**
     * Nombre de lignes de chaque liste.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return collect(array_keys(self::LISTS))->mapWithKeys(fn (string $key) => [$key => count($this->rows($key))])->all();
    }

    /**
     * Lignes d'une liste, prêtes à afficher.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $key): array
    {
        if ($this->storeIds === []) {
            return [];
        }

        return match ($key) {
            'appointment_reminder' => $this->appointmentReminders(),
            'no_show' => $this->noShows(),
            'birthday' => $this->birthdays(),
            'cross_sell' => $this->crossSell(),
            'group' => $this->groupList(),
            'installment_due' => $this->installmentsDue(),
            'prospect_followup' => $this->prospectFollowUps(),
            'tasks' => $this->openTasks(),
            'service_cycle' => $this->serviceCycle(),
            'quote_followup' => $this->quoteFollowUps(),
            'class_cancelled', 'class_makeup' => $this->sessionNotices($key),
            default => $this->customerList($key),
        };
    }

    /** @return list<array<string, mixed>> */
    private function customerList(string $key): array
    {
        $today = today();
        $query = $this->customers($key);

        match ($key) {
            'at_risk' => $query
                ->where(fn ($q) => $q->whereIn('churn_risk', ['medium', 'high'])->orWhere('lifecycle_stage', Customer::STAGE_AT_RISK))
                ->where('lifecycle_stage', '!=', Customer::STAGE_CHURNED)
                ->orderByRaw("case when churn_risk = 'high' or lifecycle_stage = 'at_risk' then 0 else 1 end")
                ->orderByDesc('total_spent'),
            'second_purchase' => $query
                ->where('purchases_count', 1)
                ->whereBetween('last_purchase_at', [$today->copy()->subDays(45)->toDateString(), $today->copy()->subDays(7)->toDateString()])
                ->orderBy('last_purchase_at'),
            'win_back' => $query
                ->where('lifecycle_stage', Customer::STAGE_CHURNED)
                ->orderByDesc('total_spent'),
            'vip_thanks' => $query
                ->where('rfm_segment', 'champions')
                ->orderByDesc('score')
                ->orderByDesc('total_spent'),
            'absent_members' => $query
                ->whereIn('customers.id', CustomerSearch::followQuery($this->user->organization_id, 'absent:2'))
                ->orderBy('name'),
            // La personne qui pratique : la personne suivie, sinon le payeur.
            'subscription_ending' => $query
                ->whereIn('customers.id', DB::table('subscriptions')->where('organization_id', $this->user->organization_id)->where('status', 'active')
                    ->whereIn('store_id', $this->storeIds)
                    ->where('ends_on', '>=', $today->toDateString())->where('ends_on', '<', $today->copy()->addDays(16)->toDateString())
                    ->selectRaw('COALESCE(beneficiary_id, customer_id)'))
                ->orderBy('name'),
        };

        return $query->limit(self::LIMIT)->get()
            ->map(function (Customer $customer) use ($key) {
                $reason = $this->reason($key, $customer);
                $row = $this->row($key, $customer, $reason);
                $this->vars = [];

                return $row;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Vente croisée : clients fidèles (segment RFM de la marque) d'une marque
     * du périmètre, qui n'ont jamais acheté dans une autre marque du
     * périmètre. Chaque client est proposé pour sa marque principale et la
     * première marque qu'il ne connaît pas encore.
     *
     * @return list<array<string, mixed>>
     */
    private function crossSell(): array
    {
        if (count($this->brandIds) < 2) {
            return [];
        }

        $sourceBrands = DB::table('stores')->whereIn('id', $this->storeIds)->distinct()->pluck('brand_id')->all();
        $metrics = DB::table('customer_brand_metrics')
            ->whereIn('brand_id', $this->brandIds)
            ->where('order_count', '>', 0)
            ->whereIn('customer_id', $this->customers('cross_sell')->select('customers.id'))
            ->get(['customer_id', 'brand_id', 'order_count', 'net_spend', 'rfm_segment'])
            ->groupBy('customer_id');

        $brandNames = Brand::whereKey($this->brandIds)->orderBy('name')->pluck('name', 'id');
        $candidates = [];

        // Zones des marques : on propose d'abord une marque voisine (même ville ou quartier).
        $zones = DB::table('stores')->whereIn('brand_id', $this->brandIds)->whereNotNull('city')->get(['brand_id', 'city'])
            ->groupBy('brand_id')->map(fn ($s) => $s->pluck('city')->unique());

        foreach ($metrics as $customerId => $rows) {
            $loyal = $rows->whereIn('brand_id', $sourceBrands)->whereIn('rfm_segment', self::CROSS_SELL_SEGMENTS)->sortByDesc('net_spend')->first();
            $target = $loyal === null ? null : $brandNames->keys()
                ->filter(fn ($id) => ! $rows->contains('brand_id', $id))
                ->sortBy(fn ($id) => (($zones[$loyal->brand_id] ?? collect())->intersect($zones[$id] ?? collect())->isNotEmpty() ? '0' : '1').$brandNames[$id])
                ->first();

            if ($loyal !== null && $target !== null) {
                $candidates[] = ['customer_id' => (int) $customerId, 'source' => (int) $loyal->brand_id, 'orders' => (int) $loyal->order_count, 'net' => (int) $loyal->net_spend, 'target' => (int) $target];
            }
        }

        $candidates = collect($candidates)->sortByDesc('net')->take(self::LIMIT);
        $customers = Customer::whereKey($candidates->pluck('customer_id'))->get()->keyBy('id');

        return $candidates->map(function (array $c) use ($customers, $brandNames) {
            $this->otherBrand = (string) $brandNames[$c['target']];
            $sourceStores = DB::table('stores')->where('brand_id', $c['source'])->whereIn('id', $this->storeIds)->pluck('id')->all();
            $row = $this->row('cross_sell', $customers[$c['customer_id']], "Fidèle de {$brandNames[$c['source']]} ({$c['orders']} achats) · jamais venu chez {$brandNames[$c['target']]}", null, $sourceStores);
            $this->otherBrand = '';

            return $row;
        })->filter()->values()->all();
    }

    /**
     * Tranches non payées arrivées à une date de relance (jours par rapport à
     * l'échéance, ex. -2, 0, 7) : chaque relance n'est proposée qu'une fois
     * (customer_follow_ups.reference). Le message part au payeur.
     *
     * @return list<array<string, mixed>>
     */
    private function installmentsDue(): array
    {
        $offsets = collect($this->user->organization->moduleSettings('tranches')['reminders'])->map(fn ($o) => (int) $o)->sort()->values();

        if ($offsets->isEmpty()) {
            return [];
        }

        $today = today();
        $installments = Installment::unpaid()
            ->whereHas('schedule', fn ($s) => $s->whereIn('store_id', $this->storeIds))
            // Dates enregistrées avec l'heure (00:00:00) : borne haute exclue au lendemain.
            ->where('due_on', '>=', $today->copy()->subDays(max(60, $offsets->last()))->toDateString())
            ->where('due_on', '<', $today->copy()->subDays($offsets->first() - 1)->toDateString())
            ->with(['schedule.customer', 'schedule.beneficiary', 'schedule.store:id,name'])
            ->orderBy('due_on')
            ->get();

        $done = CustomerFollowUp::where('action', 'installment_due')
            ->whereIn('reference', $installments->flatMap(fn ($i) => $offsets->map(fn ($o) => "installment:{$i->id}:{$o}")))
            ->pluck('reference')->flip();

        return $installments
            ->map(function (Installment $i) use ($offsets, $today, $done) {
                $late = (int) $i->due_on->diffInDays($today, false);
                $offset = $offsets->filter(fn ($o) => $o <= $late)->last();
                $reference = "installment:{$i->id}:{$offset}";

                if ($offset === null || isset($done[$reference]) || $i->schedule->customer === null) {
                    return null;
                }

                $this->vars = [
                    '{montant}' => number_format((int) $i->amount, 0, ',', ' ').' FCFA',
                    '{echeance}' => self::date($i->due_on),
                    '{objet}' => $i->label ?? $i->schedule->label,
                ];
                $for = $i->schedule->beneficiary ? " pour {$i->schedule->beneficiary->name}" : '';
                $reason = match (true) {
                    $late < 0 => 'Échéance dans '.abs($late).' jour(s)',
                    $late === 0 => "Échéance aujourd'hui",
                    default => "En retard de {$late} jour(s)",
                }.' · '.number_format((int) $i->amount, 0, ',', ' ').' FCFA · '.($i->label ?? $i->schedule->label).$for;
                $row = $this->row('installment_due', $i->schedule->customer, $reason, null, [$i->schedule->store_id], $reference);
                $this->vars = [];

                return $row;
            })
            ->filter()
            ->take(self::LIMIT)
            ->values()
            ->all();
    }

    /**
     * Inscrits à prévenir d'une séance annulée ou d'un rattrapage : une ligne
     * par personne inscrite (le message part à son responsable s'il y en a
     * un), proposée une seule fois.
     *
     * @return list<array<string, mixed>>
     */
    private function sessionNotices(string $key): array
    {
        $sessions = ClassSession::whereHas('groupClass', fn ($q) => $q->whereIn('store_id', $this->storeIds))
            ->with('groupClass')
            ->when($key === 'class_cancelled', fn ($q) => $q->where('status', ClassSession::STATUS_CANCELLED)
                ->where('cancelled_at', '>=', now()->subDays(7))
                ->where('session_date', '>=', today()->subDay()->toDateString()))
            ->when($key === 'class_makeup', fn ($q) => $q->whereNotNull('makeup_of_id')
                ->where('status', ClassSession::STATUS_SCHEDULED)
                ->where('session_date', '>=', today()->toDateString()))
            ->orderBy('session_date')
            ->get();

        $done = CustomerFollowUp::where('action', $key)->where('reference', 'like', 'session:%')->pluck('reference')->flip();
        $service = app(GroupClasses::class);
        $rows = [];

        foreach ($sessions as $session) {
            $this->vars = [
                '{cours}' => $session->groupClass->name,
                '{date_cours}' => self::date($session->date()),
                '{heure_cours}' => str_replace(':', 'h', $session->start_time),
                '{motif}' => mb_strtolower(ClassSession::CANCEL_REASONS[$session->cancel_reason] ?? 'imprévu'),
            ];
            $reason = ($key === 'class_cancelled' ? 'Annulé' : 'Rattrapage').' · '.$session->groupClass->name.', '.$session->label();

            foreach ($service->participants($session) as $customer) {
                $reference = "session:{$session->id}:{$customer->id}";
                if (! isset($done[$reference]) && count($rows) < self::LIMIT) {
                    $rows[] = $this->row($key, $customer, $reason, null, [$session->groupClass->store_id], $reference);
                }
            }
        }
        $this->vars = [];

        return array_values(array_filter($rows));
    }

    /**
     * Prospects en retard de relance : restés dans leur étape au-delà de son
     * délai, sans relance depuis leur entrée dans l'étape.
     *
     * @return list<array<string, mixed>>
     */
    private function prospectFollowUps(): array
    {
        $pipeline = ProspectPipeline::for($this->user->organization);
        $stages = $pipeline->stages()->filter(fn ($s) => $s['delay_days'] > 0 && ! in_array($s['kind'], ['gagne', 'perdu'], true))
            // Devis envoyés : liste « Devis à relancer » quand le module est actif.
            ->reject(fn ($s) => $s['key'] === self::QUOTE_STAGE && $this->user->organization->hasModule('relance_devis'));

        return $this->customers('prospect_followup')
            ->whereIn('prospect_stage', $stages->pluck('key'))
            ->with('owner:id,name')
            ->orderBy('prospect_stage_at')
            ->get()
            ->filter(fn (Customer $c) => $pipeline->lateness($c)['late'])
            ->reject(fn (Customer $c) => $c->followUps()->where('action', 'prospect_followup')->where('done_at', '>=', $c->prospect_stage_at)->where('done_at', '>=', now()->subDays($pipeline->stage($c->prospect_stage)['delay_days']))->exists())
            ->take(self::LIMIT)
            ->map(fn (Customer $c) => $this->row('prospect_followup', $c, '« '.$pipeline->stage($c->prospect_stage)['label'].' » depuis '.$pipeline->lateness($c)['days'].' j (relance prévue après '.$pipeline->stage($c->prospect_stage)['delay_days'].' j)'.($c->owner ? ' · '.$c->owner->name : '')))
            ->filter()
            ->values()
            ->all();
    }

    /** Étape « devis envoyé » des services pro (cf. App\Support\SectorPresets). */
    public const QUOTE_STAGE = 'devis_envoye';

    /** Tâches ouvertes de « À faire » (parler à quelqu'un, être rappelé, RDV à recaser). @return list<array<string, mixed>> */
    private function openTasks(): array
    {
        return Task::where('organization_id', $this->user->organization_id)
            ->whereNull('done_at')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhereIn('store_id', $this->storeIds))
            ->with(['customer', 'appointment.store'])
            ->orderBy('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Task $task) => $this->row('tasks', $task->customer, $task->title.($task->details ? ' · '.$task->details : ''), $task->appointment, reference: 'task-'.$task->id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Cycle de prestation : dernière prestation honorée dont le délai « revenir
     * après N jours » est passé, sans rendez-vous à venir ni relance depuis.
     *
     * @return list<array<string, mixed>>
     */
    private function serviceCycle(): array
    {
        $last = DB::table('appointment_items')
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->join('catalog_items', 'catalog_items.id', '=', 'appointment_items.catalog_item_id')
            ->whereIn('appointments.store_id', $this->storeIds)
            ->where('appointments.status', 'done')
            ->whereNotNull('catalog_items.return_after_days')
            ->orderByDesc('appointments.starts_at')
            ->get(['appointments.customer_id', 'appointments.id as appointment_id', 'appointments.starts_at', 'appointment_items.name', 'catalog_items.return_after_days'])
            ->unique('customer_id');

        $today = today();
        $due = $last->filter(fn ($r) => Carbon::parse($r->starts_at)->startOfDay()->addDays((int) $r->return_after_days)->lte($today));

        if ($due->isEmpty()) {
            return [];
        }

        $customers = $this->customers('service_cycle')->whereIn('id', $due->pluck('customer_id'))
            ->whereDoesntHave('appointments', fn ($q) => $q->whereIn('status', Appointment::OPEN_STATUSES)->where('starts_at', '>=', now()))
            ->get()->keyBy('id');

        return $due->filter(fn ($r) => $customers->has($r->customer_id))
            ->take(self::LIMIT)
            ->map(function ($r) use ($customers, $today) {
                $days = (int) Carbon::parse($r->starts_at)->startOfDay()->diffInDays($today);
                $this->vars = ['{jours}' => (string) $days, '{prestation}' => $r->name];
                $row = $this->row('service_cycle', $customers[$r->customer_id], "{$r->name} il y a {$days} j (revenir après {$r->return_after_days} j)");
                $this->vars = [];

                return $row;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** Devis envoyés depuis 3 jours ou plus, sans relance depuis l'envoi. @return list<array<string, mixed>> */
    private function quoteFollowUps(): array
    {
        $pipeline = ProspectPipeline::for($this->user->organization);
        $stage = $pipeline->stage(self::QUOTE_STAGE);

        if ($stage === null) {
            return [];
        }

        $delay = (int) $stage['delay_days'] > 0 ? (int) $stage['delay_days'] : 3;

        return $this->customers('quote_followup')
            ->where('prospect_stage', self::QUOTE_STAGE)
            ->where('prospect_stage_at', '<=', now()->subDays($delay))
            ->orderBy('prospect_stage_at')
            ->limit(self::LIMIT)
            ->get()
            ->reject(fn (Customer $c) => $c->followUps()->where('action', 'quote_followup')->where('done_at', '>=', $c->prospect_stage_at)->exists())
            ->map(fn (Customer $c) => $this->row('quote_followup', $c, 'Devis envoyé il y a '.$pipeline->lateness($c)['days'].' j'))
            ->filter()
            ->values()
            ->all();
    }

    /** Variables propres à une ligne ({montant}, {echeance}…), le temps de construire son message. @var array<string, string> */
    private array $vars = [];

    /** Clients d'un groupe de l'organisation (liste « Groupe de clients »). @return list<array<string, mixed>> */
    private function groupList(): array
    {
        $group = $this->groupId ? Group::where('organization_id', $this->user->organization_id)->find($this->groupId) : null;

        if ($group === null) {
            return [];
        }

        return $this->customers('group')
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $this->storeIds)->whereHas('groups', fn ($g) => $g->where('groups.id', $group->id)))
            ->orderByDesc('total_spent')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Customer $customer) => $this->row('group', $customer, "Groupe « {$group->name} »".($customer->last_purchase_at ? ' · dernier achat le '.self::date($customer->last_purchase_at) : '')))
            ->filter()
            ->values()
            ->all();
    }

    /** Marque proposée dans le message de vente croisée ({autre_marque}). */
    private string $otherBrand = '';

    /** @return list<array<string, mixed>> */
    private function birthdays(): array
    {
        $today = today();

        return $this->customers('birthday')
            ->whereNotNull('birthdate')
            ->get()
            ->map(function (Customer $customer) use ($today) {
                $next = $customer->birthdate->copy()->year($today->year);
                if ($next->lt($today)) {
                    $next->addYear();
                }

                return [$customer, $today->diffInDays($next)];
            })
            ->filter(fn ($pair) => $pair[1] <= 7)
            ->sortBy(fn ($pair) => $pair[1])
            ->take(self::LIMIT)
            ->map(fn ($pair) => $this->row('birthday', $pair[0], match ((int) $pair[1]) {
                0 => "Anniversaire aujourd'hui",
                1 => 'Anniversaire demain',
                default => 'Anniversaire le '.self::date($pair[0]->birthdate->copy()->year($today->year)),
            }))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function appointmentReminders(): array
    {
        $reminded = CustomerFollowUp::where('action', 'appointment_reminder')->whereNotNull('appointment_id')->select('appointment_id');

        return Appointment::whereIn('store_id', $this->storeIds)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->whereBetween('starts_at', [now(), today()->addDays(2)])
            ->whereNotIn('id', $reminded)
            // Rappel WhatsApp automatique déjà parti (crm:appointment-reminders) : rien à faire à la main.
            ->whereNull('reminder_sent_at')
            ->with(['customer', 'store:id,name'])
            ->orderBy('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Appointment $a) => $this->row('appointment_reminder', $a->customer, sprintf(
                'Rendez-vous %s à %s%s',
                $a->starts_at->isToday() ? "aujourd'hui" : 'demain',
                $a->starts_at->format('H\hi'),
                $a->service ? " ({$a->service})" : '',
            ), $a))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function noShows(): array
    {
        $handled = CustomerFollowUp::where('action', 'no_show')->whereNotNull('appointment_id')->select('appointment_id');

        return Appointment::whereIn('store_id', $this->storeIds)
            ->where('status', 'no_show')
            ->where('starts_at', '>=', now()->subDays(30))
            ->whereNotIn('id', $handled)
            // Déjà reprogrammé : un rendez-vous à venir existe pour ce client.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments as next')
                ->whereColumn('next.customer_id', 'appointments.customer_id')
                ->whereIn('next.status', Appointment::OPEN_STATUSES)
                ->where('next.starts_at', '>=', now()))
            ->with(['customer', 'store:id,name'])
            ->orderByDesc('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Appointment $a) => $this->row('no_show', $a->customer, 'Absent le '.self::date($a->starts_at).($a->service ? " ({$a->service})" : ''), $a))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Fiches du périmètre joignables (au moins un contact sans opt-out), hors
     * relance récente pour cette liste.
     *
     * @return Builder<Customer>
     */
    private function customers(string $key): Builder
    {
        $cooldown = self::LISTS[$key]['cooldown_days'];

        return Customer::where('organization_id', $this->user->organization_id)
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $this->storeIds)->where('consent_status', '!=', 'opt_out'))
            ->when($cooldown !== null, fn ($q) => $q->whereDoesntHave('followUps', fn ($f) => $f
                ->where('action', $key)
                ->where('done_at', '>=', now()->subDays($cooldown))));
    }

    /** Motif et variables ({formule}, {fin}) d'un abonnement qui se termine. */
    private function endingReason(Customer $customer): string
    {
        $subscription = Subscription::current()->with('plan:id,name')
            ->where(fn ($q) => $q->where('beneficiary_id', $customer->id)->orWhere(fn ($w) => $w->whereNull('beneficiary_id')->where('customer_id', $customer->id)))
            ->whereNotNull('ends_on')->orderBy('ends_on')->first();

        if ($subscription === null) {
            return '';
        }

        $this->vars = ['{formule}' => (string) $subscription->plan?->name, '{fin}' => self::date($subscription->ends_on)];

        return "« {$subscription->plan?->name} » se termine le ".self::date($subscription->ends_on);
    }

    private function reason(string $key, Customer $customer): string
    {
        $days = $customer->last_purchase_at ? (int) $customer->last_purchase_at->diffInDays(today()) : null;

        return match ($key) {
            'at_risk' => "Dernier achat il y a {$days} jours".($customer->avg_purchase_interval_days
                ? ", d'habitude tous les {$customer->avg_purchase_interval_days} jours"
                : ''),
            'second_purchase' => "Premier achat il y a {$days} jours",
            'win_back' => "Dernier achat il y a {$days} jours · ".number_format((int) $customer->total_spent, 0, ',', ' ').' FCFA dépensés',
            'vip_thanks' => "Score {$customer->score}/100 · ".number_format((int) $customer->total_spent, 0, ',', ' ').' FCFA dépensés',
            'absent_members' => implode(' · ', CustomerScoring::signals($customer, $this->user->organization)),
            'subscription_ending' => $this->endingReason($customer),
            default => '',
        };
    }

    /**
     * @return array<string, mixed>|null null si le client n'a aucun contact joignable dans le périmètre
     */
    /**
     * @param  array<int, int>|null  $storeIds  points de vente où chercher le contact (vente croisée : ceux de la marque fidèle)
     */
    private function row(string $key, ?Customer $customer, string $reason, ?Appointment $appointment = null, ?array $storeIds = null, ?string $reference = null): ?array
    {
        if ($customer === null) {
            return null;
        }

        // Commercial : seulement les fiches dont il est responsable.
        if ($this->user->ownRecordsOnly() && $customer->owner_user_id !== $this->user->id) {
            return null;
        }

        // Personne suivie : le message part à son responsable.
        $recipient = $customer->recipient();
        $contact = $this->contactFor($recipient, $appointment?->store_id, $storeIds)
            ?? ($recipient->isNot($customer) ? $this->contactFor($recipient, null, $this->storeIds) : null);

        if ($contact === null || $contact->phone_e164 === null) {
            return null;
        }

        $storeName = $appointment?->store?->name ?? $contact->store->name;
        $message = $this->message($key, $recipient, $storeName, $appointment, $recipient->isNot($customer) ? $customer : null);

        return [
            'key' => $key.'-'.$customer->id.($appointment ? '-'.$appointment->id : '').($reference ? '-'.$reference : ''),
            'customer_id' => $customer->id,
            'appointment_id' => $appointment?->id,
            'reference' => $reference,
            'store_id' => $appointment?->store_id ?? $contact->store_id,
            'name' => $customer->name,
            'recipient_name' => $recipient->isNot($customer) ? $recipient->name : null,
            'phone_e164' => $contact->phone_e164,
            'store_name' => $storeName,
            'stage' => $customer->lifecycle_stage,
            'score' => $customer->score,
            'churn_risk' => $customer->churn_risk,
            'consent' => $contact->consent_status,
            'reason' => $reason,
            'message' => $message,
            'whatsapp_url' => self::whatsappUrl($contact->phone_e164, $message),
        ];
    }

    /**
     * Contact par lequel joindre le client : celui de la boutique du rendez-vous,
     * sinon celui du dernier achat, sinon le plus récent ; jamais un opt-out.
     */
    private function contactFor(Customer $customer, ?int $storeId, ?array $storeIds = null): ?Contact
    {
        /** @var Collection<int, Contact> $contacts */
        $contacts = $customer->contacts()
            ->whereIn('store_id', $storeId !== null ? [$storeId] : ($storeIds ?? $this->storeIds))
            ->where('consent_status', '!=', 'opt_out')
            ->with('store:id,name')
            ->withMax('purchases', 'purchased_at')
            ->get();

        return $contacts->sortByDesc(fn (Contact $c) => [(string) $c->purchases_max_purchased_at, $c->created_at?->timestamp])->first();
    }

    private function message(string $key, Customer $customer, string $storeName, ?Appointment $appointment, ?Customer $beneficiary = null): string
    {
        $firstName = CustomerLifecycle::isPlaceholderName($customer->name) ? '' : (string) strtok(trim($customer->name), ' ');

        $text = strtr($this->messages[$key] ?? '', [
            '{beneficiaire}' => $beneficiary ? self::firstNameOf($beneficiary->name) : '',
            '{de_beneficiaire}' => $beneficiary ? ' de '.self::firstNameOf($beneficiary->name) : '',
            '{prenom}' => $firstName,
            '{nom}' => CustomerLifecycle::isPlaceholderName($customer->name) ? '' : $customer->name,
            '{boutique}' => $storeName,
            '{date_rdv}' => $appointment ? self::date($appointment->starts_at) : '',
            '{heure_rdv}' => $appointment ? $appointment->starts_at->format('H\hi') : '',
            '{prestation}' => $appointment?->service ?? '',
            '{autre_marque}' => $this->otherBrand,
            ...$this->vars,
        ]);

        // « Bonjour , » quand le prénom est inconnu.
        return trim(preg_replace(['/ +,/u', '/ {2,}/u'], [',', ' '], $text));
    }

    /** Prénom d'un nom complet (« Léo Mba » → « Léo »). */
    public static function firstNameOf(string $name): string
    {
        return (string) strtok(trim($name), ' ');
    }

    public static function whatsappUrl(string $phoneE164, string $message): string
    {
        return 'https://wa.me/'.preg_replace('/\D+/', '', $phoneE164).'?text='.rawurlencode($message);
    }

    private static function date(Carbon $date): string
    {
        return $date->copy()->locale('fr')->translatedFormat('l j F');
    }
}
