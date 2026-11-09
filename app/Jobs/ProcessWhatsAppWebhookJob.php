<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Store;
use App\Models\WhatsAppInboundMessage;
use App\Services\Acquisition;
use App\Services\AutoReplies;
use App\Services\ProspectPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Traite un appel du webhook Meta, déjà authentifié par sa signature.
 *
 * Meta peut livrer plusieurs fois le même événement : chaque message reçu
 * n'est enregistré qu'une fois, grâce à l'index unique sur son identifiant
 * WhatsApp (wamid). Un même webhook d'application peut recevoir les
 * événements de plusieurs numéros : chacun est rattaché à l'établissement de
 * l'organisation dont c'est le Phone Number ID.
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /** @var array<int, Campaign> Campagnes dont les compteurs sont à recalculer. */
    private array $touchedCampaigns = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public int $storeId, public array $payload) {}

    public function handle(): void
    {
        $receivingStore = Store::find($this->storeId);

        if (! $receivingStore) {
            return;
        }

        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $store = $this->storeFor($receivingStore, $value['metadata']['phone_number_id'] ?? null);

                if (! $store) {
                    Log::warning('Webhook WhatsApp : numéro inconnu de l\'organisation', [
                        'store_id' => $receivingStore->id,
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                    ]);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->recordInboundMessage($store, $message, $value['contacts'] ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->applyStatus($store, $status);
                }
            }
        }

        foreach ($this->touchedCampaigns as $campaign) {
            $campaign->refreshDeliveryCounts();
        }
    }

    /**
     * Statut d'un message envoyé (sent / delivered / read / failed), retrouvé
     * par son wamid parmi les messages de campagne de l'organisation.
     *
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(Store $store, array $status): void
    {
        $id = $status['id'] ?? null;

        if (! $id) {
            return;
        }

        $message = CampaignMessage::where('meta_message_id', $id)
            ->whereHas('contact.store', fn ($q) => $q->where('organization_id', $store->organization_id))
            ->with('campaign')
            ->first();

        if (! $message) {
            return;
        }

        $at = isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now();
        $error = $status['errors'][0] ?? null;
        $errorText = $error ? trim(($error['code'] ?? '').' '.($error['error_data']['details'] ?? $error['message'] ?? $error['title'] ?? '')) : null;

        if ($message->applyMetaStatus((string) ($status['status'] ?? ''), $at, $errorText) && $message->campaign) {
            $this->touchedCampaigns[$message->campaign->id] = $message->campaign;
        }
    }

    /**
     * Établissement destinataire : celui dont c'est le numéro, dans la même
     * organisation que l'URL appelée (jamais une autre entreprise).
     */
    private function storeFor(Store $receivingStore, ?string $phoneNumberId): ?Store
    {
        if ($phoneNumberId === null || $phoneNumberId === $receivingStore->whatsapp_phone_number_id) {
            return $receivingStore;
        }

        return Store::where('organization_id', $receivingStore->organization_id)
            ->where('whatsapp_phone_number_id', $phoneNumberId)
            ->first();
    }

    /**
     * Premier message d'un numéro inconnu : la fiche client est créée (nom du
     * profil WhatsApp), avec sa source d'acquisition (referral d'une pub
     * « Click to WhatsApp », sinon « Direct ») et, si le suivi des prospects
     * est actif, le prospect entre à la première étape. Ensuite : réponses
     * automatiques (accueil, hors horaires, menu, boutons du rappel).
     *
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $profiles  value.contacts du webhook (wa_id, profile.name)
     */
    private function recordInboundMessage(Store $store, array $message, array $profiles = []): void
    {
        $id = $message['id'] ?? null;
        $from = preg_replace('/\D/', '', (string) ($message['from'] ?? ''));

        if (! $id || $from === '') {
            return;
        }

        if (WhatsAppInboundMessage::where('wa_message_id', $id)->exists()) {
            return;
        }

        $receivedAt = isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();
        $contact = Contact::where('store_id', $store->id)->where('phone_e164', $from)->first()
            ?? $this->createContact($store, $from, $profiles, $message['referral'] ?? null);
        $contactId = $contact->id;

        $body = self::bodyOf($message);

        // insertOrIgnore : un doublon (même wamid) est ignoré par l'index unique.
        $inserted = WhatsAppInboundMessage::insertOrIgnore([
            'store_id' => $store->id,
            'contact_id' => $contactId,
            'wa_message_id' => $id,
            'from_phone' => $from,
            'type' => (string) ($message['type'] ?? 'unknown'),
            'body' => $body,
            'received_at' => $receivedAt,
            'payload' => json_encode($message),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // « Stop » : le client retire son accord pour ce numéro de boutique.
        if (! $inserted) {
            return;
        }

        if ($contact->last_inbound_at === null || $contact->last_inbound_at->lt($receivedAt)) {
            $contact->forceFill(['last_inbound_at' => $receivedAt])->saveQuietly();
        }

        if (self::isStopRequest($body)) {
            $contact->update(['consent_status' => 'opt_out', 'consent_source' => 'whatsapp_stop']);

            return;
        }

        // Pas de réponse automatique à un vieux message rejoué par Meta.
        if ($receivedAt->gt(now()->subHours(6))) {
            AutoReplies::handle($contact->fresh(['store.organization', 'customer']), $message);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @param  array<string, mixed>|null  $referral
     */
    private function createContact(Store $store, string $phone, array $profiles, ?array $referral): Contact
    {
        $profileName = collect($profiles)->firstWhere('wa_id', $phone)['profile']['name'] ?? null;

        $contact = Contact::create([
            'store_id' => $store->id,
            'phone_e164' => $phone,
            'name' => filled($profileName) ? trim((string) $profileName) : 'Client +'.$phone,
            'consent_status' => 'unknown',
        ]);

        $customer = $contact->customer;

        if ($customer !== null) {
            Acquisition::record($customer, $referral);

            $organization = $store->organization;
            $pipeline = ProspectPipeline::for($organization);
            if ($pipeline->enabled() && $customer->prospect_stage === null && (int) $customer->purchases_count === 0) {
                $pipeline->start($customer);
            }
        }

        return $contact;
    }

    /**
     * Réponse de désinscription : le message entier est un mot-clé d'arrêt
     * (« STOP », « Stop promotions », « arrêt »…), pas une phrase qui le
     * contient par hasard.
     */
    public static function isStopRequest(?string $body): bool
    {
        if ($body === null) {
            return false;
        }

        $normalized = Str::of($body)->ascii()->lower()->replaceMatches('/[^a-z ]/', ' ')->squish()->value();

        return in_array($normalized, [
            'stop', 'stop promotions', 'stop promotion', 'arret', 'arreter', 'desabonner', 'se desabonner',
            'desinscription', 'unsubscribe', 'stop pub', 'stop marketing',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private static function bodyOf(array $message): ?string
    {
        return match ($message['type'] ?? null) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title'] ?? null,
            'image', 'video', 'document' => $message[$message['type']]['caption'] ?? null,
            default => null,
        };
    }
}
