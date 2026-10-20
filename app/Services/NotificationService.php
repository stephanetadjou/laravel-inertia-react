<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Notifications « À traiter » du back-office (cloche de l'en-tête et encart
 * du tableau de bord), calculées à la volée sur le périmètre de l'utilisateur.
 * Pas de table : chaque entrée est un comptage sur les données existantes.
 *
 * Messages non lus : la source est isolée dans unreadMessages(). Elle renvoie
 * null tant que les messages entrants n'existent pas (webhook Meta à venir) ;
 * il suffira d'y compter les messages entrants non lus du périmètre.
 */
class NotificationService
{
    /** Une campagne « en cours » sans progrès depuis ce délai est considérée bloquée. */
    public const STUCK_AFTER_MINUTES = 30;

    /**
     * @return array{
     *     unread: array{available: bool, count: int},
     *     items: list<array{key: string, tone: string, count: int, title: string, detail: string, href: string}>,
     *     total: int
     * }
     */
    public function forUser(User $user): array
    {
        $storeIds = Store::where('organization_id', $user->organization_id)
            ->when($user->visibleStoreIds() !== null, fn ($q) => $q->whereIn('id', $user->visibleStoreIds()))
            ->pluck('id')
            ->all();

        // Sans l'API WhatsApp, rien à suivre côté campagnes, envois ni bienvenue.
        $api = $user->organization?->usesWhatsAppApi() ?? true;

        $items = array_values(array_filter([
            $this->customersToFollowUp($user, $storeIds),
            $this->bookingRequests($storeIds),
            $this->appointmentsToday($storeIds),
            $api ? $this->stuckCampaigns($storeIds) : null,
            $api ? $this->failedSends($storeIds) : null,
            $api ? $this->disconnectedStores($storeIds) : null,
            $api ? $this->pendingWelcome($storeIds) : null,
            $api ? $this->runningCampaigns($storeIds) : null,
        ]));

        $unread = $this->unreadMessages($storeIds);

        return [
            'unread' => ['available' => $unread !== null, 'count' => $unread ?? 0],
            'items' => $items,
            // Compteur de la cloche : ce qui demande une action (pas les campagnes en cours).
            'total' => ($unread ?? 0) + collect($items)->where('tone', '!=', 'info')->sum('count'),
        ];
    }

    /**
     * Messages clients non lus. À brancher sur les messages entrants lors de
     * l'intégration du webhook Meta ; null = fonctionnalité pas encore disponible.
     *
     * @param  array<int, int>  $storeIds
     */
    public function unreadMessages(array $storeIds): ?int
    {
        return null;
    }

    /**
     * Bons clients en retard sur leur rythme d'achat, pas relancés depuis le
     * délai de la liste (même critère que la liste « Clients à risque »).
     *
     * @param  array<int, int>  $storeIds
     */
    private function customersToFollowUp(User $user, array $storeIds): ?array
    {
        $count = Customer::where('organization_id', $user->organization_id)
            ->where(fn ($q) => $q->whereIn('churn_risk', ['medium', 'high'])->orWhere('lifecycle_stage', Customer::STAGE_AT_RISK))
            ->where('lifecycle_stage', '!=', Customer::STAGE_CHURNED)
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $storeIds)->where('consent_status', '!=', 'opt_out'))
            ->whereDoesntHave('followUps', fn ($q) => $q->where('action', 'at_risk')
                ->where('done_at', '>=', now()->subDays(ActionLists::LISTS['at_risk']['cooldown_days'])))
            ->count();

        return $count === 0 ? null : [
            'key' => 'customers_to_follow_up',
            'tone' => 'warn',
            'count' => $count,
            'title' => $count > 1 ? "{$count} clients à risque à relancer" : '1 client à risque à relancer',
            'detail' => "En retard sur leur rythme d'achat habituel : un message WhatsApp prérempli vous attend.",
            'href' => '/actions?list=at_risk',
        ];
    }

    /** @param array<int, int> $storeIds */
    private function bookingRequests(array $storeIds): ?array
    {
        $count = Appointment::whereIn('store_id', $storeIds)
            ->where('status', 'requested')
            ->where('starts_at', '>=', now())
            ->count();

        return $count === 0 ? null : [
            'key' => 'booking_requests',
            'tone' => 'warn',
            'count' => $count,
            'title' => $count > 1 ? "{$count} réservations en ligne à confirmer" : '1 réservation en ligne à confirmer',
            'detail' => 'Prises sur votre page de réservation : confirmez-les dans l’agenda.',
            'href' => '/appointments?view=week',
        ];
    }

    /** @param array<int, int> $storeIds */
    private function appointmentsToday(array $storeIds): ?array
    {
        $count = Appointment::whereIn('store_id', $storeIds)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->whereBetween('starts_at', [today(), today()->addDay()])
            ->count();

        return $count === 0 ? null : [
            'key' => 'appointments_today',
            'tone' => 'info',
            'count' => $count,
            'title' => $count > 1 ? "{$count} rendez-vous aujourd'hui" : "1 rendez-vous aujourd'hui",
            'detail' => "Confirmez-les ou notez les absences dans l'agenda.",
            'href' => '/appointments?view=day',
        ];
    }

    /** @param array<int, int> $storeIds */
    private function stuckCampaigns(array $storeIds): ?array
    {
        $count = $this->campaigns($storeIds)
            ->whereIn('status', ['queued', 'sending'])
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->count();

        return $count === 0 ? null : [
            'key' => 'stuck_campaigns',
            'tone' => 'bad',
            'count' => $count,
            'title' => $count > 1 ? "{$count} campagnes semblent bloquées" : '1 campagne semble bloquée',
            'detail' => 'En file ou en cours depuis plus de '.self::STUCK_AFTER_MINUTES.' min : vérifiez que le traitement de la file d\'attente tourne.',
            'href' => '/campaigns?status=sending',
        ];
    }

    /** @param array<int, int> $storeIds */
    private function failedSends(array $storeIds): ?array
    {
        $count = CampaignMessage::where('status', 'failed')
            ->where('updated_at', '>=', now()->subDays(7))
            ->whereHas('contact', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->count();

        return $count === 0 ? null : [
            'key' => 'failed_sends',
            'tone' => 'bad',
            'count' => $count,
            'title' => $count > 1 ? "{$count} envois en échec cette semaine" : '1 envoi en échec cette semaine',
            'detail' => 'Numéro invalide, modèle refusé par Meta… le motif est dans l\'historique.',
            'href' => '/chat?tab=failed&period=7d',
        ];
    }

    /** @param array<int, int> $storeIds */
    private function disconnectedStores(array $storeIds): ?array
    {
        $count = Store::whereIn('id', $storeIds)->get()->reject(fn (Store $store) => $store->canSendWhatsApp())->count();

        return $count === 0 ? null : [
            'key' => 'disconnected_stores',
            'tone' => 'warn',
            'count' => $count,
            'title' => $count > 1 ? "{$count} boutiques sans WhatsApp" : '1 boutique sans WhatsApp',
            'detail' => 'Aucun identifiant WhatsApp : ni campagne ni bienvenue possible depuis ces boutiques.',
            'href' => '/stores',
        ];
    }

    /** @param array<int, int> $storeIds */
    private function pendingWelcome(array $storeIds): ?array
    {
        $count = Contact::whereIn('store_id', $storeIds)
            ->where('consent_status', 'unknown')
            ->whereNull('welcome_message_sent_at')
            ->count();

        return $count === 0 ? null : [
            'key' => 'pending_welcome',
            'tone' => 'warn',
            'count' => $count,
            'title' => $count > 1 ? "{$count} contacts attendent la bienvenue" : '1 contact attend la bienvenue',
            'detail' => 'Consentement inconnu : le message de bienvenue leur demande leur accord.',
            'href' => '/dashboard#bienvenue',
        ];
    }

    /** @param array<int, int> $storeIds */
    private function runningCampaigns(array $storeIds): ?array
    {
        $count = $this->campaigns($storeIds)
            ->whereIn('status', ['queued', 'sending'])
            ->where('updated_at', '>=', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->count();

        return $count === 0 ? null : [
            'key' => 'running_campaigns',
            'tone' => 'info',
            'count' => $count,
            'title' => $count > 1 ? "{$count} campagnes en cours d'envoi" : '1 campagne en cours d\'envoi',
            'detail' => 'Progression visible dans la page Campagnes.',
            'href' => '/campaigns?status=sending',
        ];
    }

    /**
     * @param  array<int, int>  $storeIds
     * @return Builder<Campaign>
     */
    private function campaigns(array $storeIds): Builder
    {
        return Campaign::whereHas('stores', fn ($q) => $q->whereIn('stores.id', $storeIds));
    }
}
