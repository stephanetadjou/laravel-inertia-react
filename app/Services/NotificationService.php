<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
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

        $items = array_values(array_filter([
            $this->stuckCampaigns($storeIds),
            $this->failedSends($storeIds),
            $this->disconnectedStores($storeIds),
            $this->pendingWelcome($storeIds),
            $this->runningCampaigns($storeIds),
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
