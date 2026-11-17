<?php

namespace App\Modules\Location\Services;

use App\Models\Contact;
use App\Models\Organization;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Models\VehicleSale;
use App\Services\ActionLists;
use App\Services\WhatsAppConversation;
use Illuminate\Support\Carbon;

/**
 * Relances WhatsApp du loueur : devis sans réponse à 24 h puis 72 h, rappel
 * la veille du départ, remerciement et demande d'avis après le retour.
 *
 * Offre gratuite (ou sans API) : chaque relance due apparaît avec son lien
 * wa.me, l'équipe l'envoie depuis son téléphone puis la marque faite.
 * Offres payantes avec l'API WhatsApp : `location:relances` l'envoie si la
 * fenêtre de 24 h du client est ouverte, sinon elle reste à faire à la main.
 */
class LocationFollowUps
{
    public const KINDS = [
        'quote_24' => 'Devis sans réponse (24 h)',
        'quote_72' => 'Devis sans réponse (72 h)',
        'reminder' => 'Rappel de la veille',
        'thanks' => 'Remerciement et avis',
        'test_drive' => 'Rappel de l’essai',
        'ride_reminder' => 'Rappel de la course (2 h avant)',
        'online_new' => 'Demande en ligne sans réponse',
    ];

    /**
     * @param  list<int>  $storeIds
     * @return list<array{key: string, kind: string, kind_label: string, id: int, customer_id: int, customer: string, detail: string, message: string, wa_url: ?string}>
     */
    public static function due(Organization $organization, array $storeIds, ?Carbon $now = null): array
    {
        $now ??= now();
        $items = [];
        $push = function (string $kind, VehicleRequest|Booking|VehicleSale $record, string $detail, string $message) use (&$items) {
            $items[] = [
                'key' => "{$kind}:{$record->id}", 'kind' => $kind, 'kind_label' => self::KINDS[$kind], 'id' => $record->id,
                'customer_id' => $record->customer_id, 'customer' => (string) $record->customer?->name, 'detail' => $detail,
                'message' => $message, 'wa_url' => RentalDesk::waUrl($record, $message),
            ];
        };

        $quotes = VehicleRequest::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)
            ->where('status', 'quoted')->whereNull('reminded_72_at')->where('quoted_at', '<=', $now->copy()->subHours(24))
            ->whereHas('quote', fn ($q) => $q->whereIn('status', ['draft', 'sent']))
            ->with(['customer', 'quote.customer', 'vehicle'])->get();
        $invoicing = app(Invoicing::class);
        foreach ($quotes as $request) {
            if ($request->reminded_24_at === null) {
                $push('quote_24', $request, $request->summary(), $invoicing->reminderMessage($request->quote));
            } elseif ($request->quoted_at->lte($now->copy()->subHours(72))) {
                $first = ActionLists::firstNameOf((string) $request->customer?->name);
                $push('quote_72', $request, $request->summary(), "Bonjour {$first}, votre devis {$request->quote->number} est toujours valable"
                    .($request->starts_at ? ' pour le '.$request->starts_at->locale('fr')->translatedFormat('j F') : '')
                    .'. Le véhicule peut partir à un autre client : souhaitez-vous qu’on le bloque pour vous ? '.$request->quote->publicUrl());
            }
        }

        // Demande reçue par le lien en ligne, toujours sans réponse 1 h après : on écrit au client.
        $online = VehicleRequest::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('source', 'online')->where('status', 'new')
            ->whereNull('quoted_at')->whereNull('reminded_24_at')->where('created_at', '<=', $now->copy()->subHour())->with('customer', 'vehicle', 'store')->get();
        foreach ($online as $request) {
            $first = ActionLists::firstNameOf((string) $request->customer?->name);
            $push('online_new', $request, $request->summary(), "Bonjour {$first}, merci pour votre demande ({$request->summary()}) chez {$request->store?->name}. Nous revenons vers vous avec une proposition : pouvez-vous nous confirmer vos besoins ?");
        }

        $tz = $organization->timezone ?: config('app.timezone');
        $tomorrow = $now->copy()->setTimezone($tz)->addDay();
        $bookings = Booking::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('status', 'reserved')->where('kind', '!=', 'ride')->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [$tomorrow->copy()->startOfDay()->setTimezone(config('app.timezone')), $tomorrow->copy()->endOfDay()->setTimezone(config('app.timezone'))])
            ->with(['customer', 'vehicle', 'store'])->get();
        foreach ($bookings as $booking) {
            $push('reminder', $booking, $booking->vehicle->label().' · '.$booking->starts_at->format('d/m H\hi'), RentalDesk::reminderMessage($booking));
        }

        $returned = Booking::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('status', 'returned')->whereNull('thanked_at')
            ->where('returned_at', '>=', $now->copy()->subDays(14))->where('returned_at', '<=', $now)
            ->with(['customer', 'vehicle', 'store'])->get();
        foreach ($returned as $booking) {
            $push('thanks', $booking, ($booking->isRide() ? RideDesk::label($booking) : $booking->vehicle->label().' rendu').' le '.$booking->returned_at->format('d/m'), RentalDesk::thanksMessage($booking));
        }

        // Course réservée à l'avance (au moins 2 h avant) : rappel 2 h avant le départ.
        $rides = Booking::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('kind', 'ride')->where('ride_status', 'booked')->whereNull('ride_reminded_at')
            ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addHours(2))
            ->with(['customer', 'vehicle', 'store'])->get()
            ->filter(fn (Booking $b) => $b->created_at->lte($b->starts_at->copy()->subHours(2)));
        foreach ($rides as $booking) {
            $push('ride_reminder', $booking, RideDesk::label($booking).' · '.$booking->starts_at->format('d/m H\hi'), RideDesk::reminderMessage($booking));
        }

        // Essai d'un véhicule à vendre prévu demain.
        $drives = VehicleSale::where('organization_id', $organization->id)->whereIn('store_id', $storeIds)->where('status', 'test_drive')->whereNull('test_drive_reminded_at')
            ->whereBetween('test_drive_at', [$tomorrow->copy()->startOfDay()->setTimezone(config('app.timezone')), $tomorrow->copy()->endOfDay()->setTimezone(config('app.timezone'))])
            ->with(['customer', 'vehicle'])->get();
        foreach ($drives as $sale) {
            $first = ActionLists::firstNameOf((string) $sale->customer?->name);
            $push('test_drive', $sale, $sale->vehicle->label().' · essai '.$sale->test_drive_at->format('d/m H\hi'),
                "Bonjour {$first}, petit rappel : votre essai du {$sale->vehicle->name} est prévu demain à {$sale->test_drive_at->format('H\hi')}. Pensez à votre permis. À demain !");
        }

        return $items;
    }

    /** Marque une relance faite (envoyée depuis le téléphone ou par l'API). */
    public static function markDone(int $organizationId, string $kind, int $id): void
    {
        match ($kind) {
            'quote_24', 'online_new' => VehicleRequest::where('organization_id', $organizationId)->whereKey($id)->update(['reminded_24_at' => now()]),
            'quote_72' => VehicleRequest::where('organization_id', $organizationId)->whereKey($id)->update(['reminded_72_at' => now()]),
            'reminder' => Booking::where('organization_id', $organizationId)->whereKey($id)->update(['reminder_sent_at' => now()]),
            'thanks' => Booking::where('organization_id', $organizationId)->whereKey($id)->update(['thanked_at' => now()]),
            'ride_reminder' => Booking::where('organization_id', $organizationId)->whereKey($id)->update(['ride_reminded_at' => now()]),
            'test_drive' => VehicleSale::where('organization_id', $organizationId)->whereKey($id)->update(['test_drive_reminded_at' => now()]),
            default => abort(404),
        };

        if ($kind === 'quote_24' || $kind === 'quote_72') {
            $request = VehicleRequest::find($id);
            $request?->quote?->update(['last_reminder_at' => now()]);
        }
    }

    /** Envoi automatique (offres payantes, API WhatsApp, fenêtre de 24 h ouverte). Retourne le nombre envoyé. */
    public static function sendAutomatic(Organization $organization): int
    {
        if (! $organization->automationAllowed() || ! $organization->usesWhatsAppApi()) {
            return 0;
        }

        $storeIds = $organization->stores()->pluck('id')->all();
        $sent = 0;

        foreach (self::due($organization, $storeIds) as $item) {
            $record = match (true) {
                str_starts_with($item['kind'], 'quote') || $item['kind'] === 'online_new' => VehicleRequest::find($item['id']),
                $item['kind'] === 'test_drive' => VehicleSale::find($item['id']),
                default => Booking::find($item['id']),
            };
            $contact = Contact::where('store_id', $record->store_id)->where('customer_id', $item['customer_id'])->where('consent_status', '!=', 'opt_out')->first();

            if ($contact === null || ! WhatsAppConversation::windowOpen($contact)) {
                continue;
            }

            if (WhatsAppConversation::text($contact, $item['message'], 'auto')->status === 'sent') {
                self::markDone($organization->id, $item['kind'], $item['id']);
                $sent++;
            }
        }

        return $sent;
    }
}
