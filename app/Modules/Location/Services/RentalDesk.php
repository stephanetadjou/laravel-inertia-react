<?php

namespace App\Modules\Location\Services;

use App\Models\BookableResource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RentalAccount;
use App\Modules\Location\Models\VehicleInspection;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Models\VehicleSale;
use App\Services\ActionLists;
use App\Services\Audit;
use App\Services\Fleet;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le comptoir du loueur : demande → devis (Devis et factures) → réservation
 * (acompte, caution, solde) → départ (état des lieux) → retour → caution
 * rendue ou retenue. Chaque montant encaissé reste une vente du client
 * (fiche, RFM, caisse) via les factures du devis.
 */
class RentalDesk
{
    public function __construct(private Invoicing $invoicing) {}

    /** Fiche client existante (par id ou par numéro), sinon nouvelle fiche. */
    public function customerFor(User $user, int $storeId, ?int $customerId, ?string $name, ?string $phone, bool $business = false): Customer
    {
        if ($customerId) {
            return Customer::where('organization_id', $user->organization_id)->findOrFail($customerId);
        }

        $e164 = PhoneNormalizer::normalize((string) $phone) ?? throw ValidationException::withMessages(['phone' => 'Numéro WhatsApp invalide.']);
        $customer = Customer::where('organization_id', $user->organization_id)->where('phone_e164', $e164)->first()
            ?? Contact::create(['store_id' => $storeId, 'name' => trim((string) $name) ?: $e164, 'phone_e164' => $e164])->customer;

        if ($business && $customer->type !== 'business') {
            $customer->forceFill(['type' => 'business', 'company_name' => $customer->company_name ?? $customer->name])->save();
        }

        return $customer;
    }

    /** @param array<string, mixed> $data */
    public function createRequest(User $user, array $data): VehicleRequest
    {
        $customer = $this->customerFor($user, (int) $data['store_id'], $data['customer_id'] ?? null, $data['name'] ?? null, $data['phone'] ?? null, ($data['purpose'] ?? null) === 'business');

        $request = VehicleRequest::create([
            'organization_id' => $user->organization_id,
            'store_id' => $data['store_id'],
            'customer_id' => $customer->id,
            'need' => $data['need'] ?? 'rent',
            'purpose' => $data['purpose'] ?? null,
            'category' => $data['category'] ?? null,
            'resource_id' => $data['resource_id'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'with_driver' => (bool) ($data['with_driver'] ?? false),
            'budget' => $data['budget'] ?? null,
            'source' => $data['source'] ?? 'whatsapp',
            'notes' => $data['notes'] ?? null,
            'ride_from' => $data['ride_from'] ?? null,
            'ride_to' => $data['ride_to'] ?? null,
            'ride_passengers' => $data['ride_passengers'] ?? null,
            'ride_luggage' => $data['ride_luggage'] ?? null,
            'flight_number' => $data['flight_number'] ?? null,
            'status' => 'new',
            'created_by' => $user->id,
        ]);
        Audit::record('location.request', $request, "Demande de {$customer->name} : {$request->summary()}", [], $user);

        return $request;
    }

    /** Vérifie qu'un véhicule peut être réservé : en service et sans autre réservation sur la période. */
    public static function ensureAvailable(BookableResource $vehicle, Carbon $from, Carbon $to, ?int $exceptId = null): void
    {
        if ($to->lte($from)) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit être après le début.']);
        }

        if ($vehicle->status !== 'available' || ! $vehicle->active) {
            $state = mb_strtolower(BookableResource::STATUSES[$vehicle->status] ?? 'indisponible');
            throw ValidationException::withMessages(['resource_id' => "{$vehicle->label()} est indisponible ({$state})."]);
        }

        if ($conflict = Fleet::conflict($vehicle->id, $from, $to, $exceptId)) {
            throw ValidationException::withMessages(['resource_id' => "{$vehicle->label()} est déjà réservé du {$conflict->starts_at->format('d/m H\hi')} au ".Fleet::effectiveEnd($conflict)->format('d/m H\hi')
                .($conflict->customer ? " ({$conflict->customer->name})" : '').'.']);
        }
    }

    /** Tarif journalier pour ce client : tarif négocié du compte entreprise, sinon tarif du véhicule. */
    public static function dayRate(Customer $customer, ?BookableResource $vehicle, ?int $rate = null): int
    {
        $base = (int) ($rate ?? $vehicle?->price_day ?? 0);
        $account = RentalAccount::where('customer_id', $customer->id)->first();

        return $account ? $account->rateFor($base) : $base;
    }

    public static function days(Carbon $from, Carbon $to): int
    {
        return max(1, (int) ceil($from->diffInMinutes($to) / 1440));
    }

    /**
     * Devis de location (ou d'achat) depuis une demande, marqué envoyé ; on
     * l'ouvre ensuite sur WhatsApp (lien wa.me) depuis le téléphone.
     *
     * @param  array{resource_id?: ?int, rate?: ?int, driver_rate?: ?int, deposit?: ?int, price?: ?int, notes?: ?string}  $data
     */
    public function quote(VehicleRequest $request, User $user, array $data): Invoice
    {
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['request' => 'Cette demande est déjà close.']);
        }

        $vehicle = filled($data['resource_id'] ?? null) ? BookableResource::where('organization_id', $request->organization_id)->findOrFail($data['resource_id']) : $request->vehicle;
        $customer = $request->customer;

        if ($request->need === 'buy') {
            $price = (int) ($data['price'] ?? $vehicle?->sale_price ?? 0);
            $lines = [['label' => 'Achat '.($vehicle?->label() ?? 'véhicule'), 'quantity' => 1, 'unit_price' => $price]];
        } else {
            if ($request->starts_at === null || $request->ends_at === null) {
                throw ValidationException::withMessages(['starts_at' => 'Indiquez les dates de la location avant le devis.']);
            }
            $days = self::days($request->starts_at, $request->ends_at);
            $rate = self::dayRate($customer, $vehicle, isset($data['rate']) ? (int) $data['rate'] : null);
            $what = $vehicle?->label() ?? (BookableResource::CATEGORIES[$request->category] ?? 'véhicule');
            $lines = [['label' => "Location {$what} du {$request->starts_at->format('d/m H\hi')} au {$request->ends_at->format('d/m H\hi')}", 'quantity' => $days, 'unit_price' => $rate]];
            if ($request->with_driver && (int) ($data['driver_rate'] ?? 0) > 0) {
                $lines[] = ['label' => 'Chauffeur', 'quantity' => $days, 'unit_price' => (int) $data['driver_rate']];
            }
        }

        $deposit = (int) ($data['deposit'] ?? 0);
        $notes = trim(implode("\n", array_filter([
            $deposit > 0 ? 'Caution remboursable : '.Invoicing::money($deposit).', versée à la prise du véhicule et rendue au retour après état des lieux.' : null,
            $data['notes'] ?? null,
        ])));

        return DB::transaction(function () use ($request, $user, $lines, $notes, $vehicle) {
            $quote = $this->invoicing->create($user, [
                'type' => Invoice::TYPE_QUOTE,
                'customer_id' => $request->customer_id,
                'store_id' => $request->store_id,
                'title' => $request->summary(),
                'valid_until' => today()->addDays(7)->toDateString(),
                'notes' => $notes ?: null,
                'lines' => $lines,
            ]);
            $this->invoicing->markSent($quote, $user);
            $request->update(['status' => 'quoted', 'quote_id' => $quote->id, 'quoted_at' => now(), 'resource_id' => $vehicle?->id ?? $request->resource_id]);

            return $quote;
        });
    }

    /**
     * Devis → réservation en un clic : contrôle de disponibilité (véhicule
     * verrouillé), contrat, devis accepté puis facture d'acompte.
     *
     * @param  array{resource_id: int, starts_at?: ?string, ends_at?: ?string, deposit?: ?int, deposit_percent?: ?int, pickup_place?: ?string, driver_name?: ?string, rate?: ?int}  $data
     */
    public function book(VehicleRequest $request, User $user, array $data): Booking
    {
        if (! $request->isOpen() || $request->need !== 'rent') {
            throw ValidationException::withMessages(['request' => 'Seule une demande de location ouverte peut être réservée.']);
        }

        $from = Carbon::parse($data['starts_at'] ?? $request->starts_at);
        $to = Carbon::parse($data['ends_at'] ?? $request->ends_at);

        $booking = DB::transaction(function () use ($request, $user, $data, $from, $to) {
            // Verrou sur le véhicule : deux réservations simultanées du même véhicule ne passent pas toutes les deux.
            $vehicle = BookableResource::where('organization_id', $request->organization_id)->whereKey($data['resource_id'])->lockForUpdate()->firstOrFail();
            self::ensureAvailable($vehicle, $from, $to);

            $datesChanged = ! $request->starts_at?->eq($from) || ! $request->ends_at?->eq($to) || (int) $request->resource_id !== $vehicle->id;
            $request->update(['starts_at' => $from, 'ends_at' => $to, 'resource_id' => $vehicle->id]);

            // Sans devis (ou devis sur d'autres dates / un autre véhicule) : devis refait sur la réservation.
            $quote = $request->quote;
            if ($quote === null || ($datesChanged && in_array($quote->status, ['draft', 'sent'], true))) {
                if ($quote) {
                    $this->invoicing->refuse($quote);
                    $request->update(['status' => 'new', 'quote_id' => null]);
                }
                $quote = $this->quote($request->fresh(), $user, ['resource_id' => $vehicle->id, 'rate' => $data['rate'] ?? null, 'deposit' => $data['deposit'] ?? null]);
            }

            if (in_array($quote->status, ['draft', 'sent'], true)) {
                $this->invoicing->accept($quote, $request->customer->name);
            }

            $booking = Booking::create([
                'organization_id' => $request->organization_id,
                'store_id' => $request->store_id,
                'resource_id' => $vehicle->id,
                'customer_id' => $request->customer_id,
                'kind' => self::days($from, $to) >= 30 ? 'long' : 'short',
                'status' => 'reserved',
                'starts_at' => $from,
                'ends_at' => $to,
                'rate' => self::dayRate($request->customer, $vehicle, $data['rate'] ?? null),
                'rate_period' => 'day',
                'amount' => (int) $quote->total,
                'deposit' => $data['deposit'] ?? null,
                'vehicle_request_id' => $request->id,
                'quote_id' => $quote->id,
                'pickup_place' => $data['pickup_place'] ?? null,
                'km_included' => ($kmDay = (int) ($request->organization?->moduleSettings('location')['km_per_day'] ?? 0)) > 0 ? $kmDay * self::days($from, $to) : null,
                'driver_name' => $request->with_driver ? ($data['driver_name'] ?? null) : null,
                'confirmed_at' => now(),
                'created_by' => $user->id,
            ]);

            $percent = (int) ($data['deposit_percent'] ?? 0);
            if ($percent > 0 && $percent < 100) {
                $this->invoicing->invoiceQuote($quote->fresh(), $user, $percent);
            }

            $request->update(['status' => 'won', 'rental_id' => $booking->id, 'closed_at' => now()]);

            return $booking;
        });

        Audit::record('location.booked', $booking, "Réservation de {$booking->vehicle->label()} pour {$booking->customer->name}", ['amount' => $booking->amount], $user);

        return $booking;
    }

    /** Demande perdue : la raison est obligatoire (elle nourrit le tableau de bord). */
    public function lose(VehicleRequest $request, User $user, string $reason, ?string $note = null): void
    {
        if (! array_key_exists($reason, VehicleRequest::LOST_REASONS)) {
            throw ValidationException::withMessages(['lost_reason' => 'Choisissez la raison de la perte.']);
        }

        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['request' => 'Cette demande est déjà close.']);
        }

        DB::transaction(function () use ($request, $reason, $note) {
            if ($request->quote && in_array($request->quote->status, ['draft', 'sent'], true)) {
                $this->invoicing->refuse($request->quote);
            }
            $request->update(['status' => 'lost', 'lost_reason' => $reason, 'lost_note' => $note ? mb_substr($note, 0, 255) : null, 'closed_at' => now()]);
        });
        Audit::record('location.request_lost', $request, 'Demande perdue : '.VehicleRequest::LOST_REASONS[$reason], [], $user);
    }

    /**
     * Départ : état des lieux (photos, kilométrage, carburant, dommages
     * constatés), caution encaissée, véhicule sorti.
     *
     * @param  list<string>  $photos  chemins déjà enregistrés
     */
    public function depart(Booking $booking, User $user, ?int $km, ?int $fuel, array $photos = [], ?string $damages = null, ?string $signedName = null): VehicleInspection
    {
        if ($booking->status !== 'reserved') {
            throw ValidationException::withMessages(['booking' => 'Cette réservation n’est pas en attente de départ.']);
        }

        return DB::transaction(function () use ($booking, $user, $km, $fuel, $photos, $damages, $signedName) {
            $inspection = $booking->inspections()->create(['phase' => 'out', 'mileage' => $km, 'fuel' => $fuel, 'photos' => $photos, 'damages' => $damages, 'signed_name' => $signedName, 'user_id' => $user->id]);
            $booking->update([
                'status' => 'ongoing', 'departed_at' => now(), 'km_out' => $km ?? $booking->vehicle->mileage, 'fuel_out' => $fuel,
                'deposit_status' => (int) $booking->deposit > 0 ? 'held' : null,
            ]);
            if ($km !== null) {
                $booking->vehicle->update(['mileage' => max((int) $booking->vehicle->mileage, $km)]);
            }

            return $inspection;
        });
    }

    /** Retour : état des lieux ; la caution reste à clôturer (rendue ou retenue). */
    public function giveBack(Booking $booking, User $user, ?int $km, ?int $fuel, array $photos = [], ?string $damages = null, ?string $signedName = null): VehicleInspection
    {
        if ($booking->status !== 'ongoing') {
            throw ValidationException::withMessages(['booking' => 'Ce véhicule n’est pas sorti.']);
        }

        if ($km !== null && $booking->km_out !== null && $km < $booking->km_out) {
            throw ValidationException::withMessages(['mileage' => 'Le kilométrage au retour est inférieur à celui du départ ('.number_format($booking->km_out, 0, ',', ' ').' km).']);
        }

        return DB::transaction(function () use ($booking, $user, $km, $fuel, $photos, $damages, $signedName) {
            $inspection = $booking->inspections()->create(['phase' => 'in', 'mileage' => $km, 'fuel' => $fuel, 'photos' => $photos, 'damages' => $damages, 'signed_name' => $signedName, 'user_id' => $user->id]);
            $booking->update(['status' => 'returned', 'returned_at' => now(), 'km_in' => $km, 'fuel_in' => $fuel]);

            // Kilomètres au-delà du forfait du contrat : facturés au retour (une vente du client).
            $price = (int) ($booking->organization?->moduleSettings('location')['extra_km_price'] ?? 0);
            if ($km !== null && $booking->km_out !== null && $booking->km_included && $price > 0) {
                $extra = ($km - $booking->km_out) - $booking->km_included;
                if ($extra > 0) {
                    $this->sale($booking, $user, $extra * $price, "Kilomètres supplémentaires ({$extra} km) – {$booking->vehicle->label()}", null);
                    $booking->update(['extra_km_amount' => $extra * $price]);
                }
            }
            if ($km !== null) {
                $booking->vehicle->update(['mileage' => max((int) $booking->vehicle->mileage, $km)]);
            }

            return $inspection;
        });
    }

    /**
     * Clôture de la caution : rendue en entier, ou retenue (en partie ou en
     * totalité) avec un motif. Une retenue est une vente du client.
     */
    public function closeDeposit(Booking $booking, User $user, int $retained, ?string $reason = null, ?string $note = null, ?string $paymentMethod = null): void
    {
        if ($booking->status !== 'returned' || $booking->deposit_status !== 'held') {
            throw ValidationException::withMessages(['deposit' => 'Aucune caution à clôturer sur cette réservation.']);
        }

        if ($retained < 0 || $retained > (int) $booking->deposit) {
            throw ValidationException::withMessages(['retained' => 'La retenue doit être comprise entre 0 et '.Invoicing::money((int) $booking->deposit).'.']);
        }

        if ($retained > 0 && ! array_key_exists((string) $reason, Booking::DEPOSIT_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif de la retenue.']);
        }

        DB::transaction(function () use ($booking, $user, $retained, $reason, $note, $paymentMethod) {
            $booking->update([
                'deposit_status' => $retained === 0 ? 'returned' : ($retained >= (int) $booking->deposit ? 'retained' : 'partial'),
                'deposit_retained' => $retained,
                'deposit_reason' => $retained > 0 ? $reason : null,
                'deposit_note' => $note ? mb_substr($note, 0, 255) : null,
                'deposit_closed_at' => now(),
            ]);

            if ($retained > 0) {
                $this->sale($booking, $user, $retained, 'Retenue sur caution ('.mb_strtolower(Booking::DEPOSIT_REASONS[$reason]).') – '.$booking->vehicle->label(), $paymentMethod);
            }
        });
        Audit::record('location.deposit', $booking, 'Caution clôturée : '.($retained ? Invoicing::money($retained).' retenus' : 'rendue'), ['retained' => $retained], $user);
    }

    public function cancel(Booking $booking, User $user): void
    {
        if ($booking->status !== 'reserved') {
            throw ValidationException::withMessages(['booking' => 'Seule une réservation pas encore partie peut être annulée.']);
        }
        $booking->update(['status' => 'cancelled']);
        Audit::record('location.cancelled', $booking, "Réservation de {$booking->vehicle->label()} annulée", [], $user);
    }

    // --- Messages WhatsApp (liens wa.me, ou envoi par l'API sur les offres payantes) ---

    public static function confirmationMessage(Booking $booking): string
    {
        $first = ActionLists::firstNameOf((string) $booking->customer?->name);

        return "Bonjour {$first}, votre réservation chez {$booking->store?->name} est confirmée : {$booking->vehicle->label()} du "
            .$booking->starts_at->locale('fr')->translatedFormat('l j F à H\hi').' au '.$booking->ends_at->locale('fr')->translatedFormat('l j F à H\hi')
            .($booking->pickup_place ? ", prise en charge : {$booking->pickup_place}" : '')
            .($booking->driver_name ? ", chauffeur : {$booking->driver_name}" : '')
            .'. Montant : '.Invoicing::money((int) $booking->amount)
            .((int) $booking->deposit > 0 ? ', caution : '.Invoicing::money((int) $booking->deposit).' (rendue au retour)' : '')
            .($booking->quote ? ". Détail : {$booking->quote->publicUrl()}" : '.');
    }

    public static function reminderMessage(Booking $booking): string
    {
        $first = ActionLists::firstNameOf((string) $booking->customer?->name);

        return "Bonjour {$first}, petit rappel : votre {$booking->vehicle->name} vous attend demain à {$booking->starts_at->format('H\hi')}"
            .($booking->pickup_place ? " ({$booking->pickup_place})" : '')
            .($booking->driver_name ? ", avec votre chauffeur {$booking->driver_name}" : '')
            .'. Pensez à votre permis et à votre pièce d’identité'.((int) $booking->deposit > 0 ? ', et à la caution de '.Invoicing::money((int) $booking->deposit) : '').'. À demain !';
    }

    public static function thanksMessage(Booking $booking): string
    {
        $first = ActionLists::firstNameOf((string) $booking->customer?->name);

        if ($booking->isRide()) {
            return "Bonjour {$first}, merci d’avoir voyagé avec {$booking->store?->name} ! Votre avis compte : sur 5, quelle note donnez-vous à votre course et à votre chauffeur ? Pour votre prochain transfert, répondez simplement à ce message.";
        }

        return "Bonjour {$first}, merci d’avoir loué votre {$booking->vehicle->name} chez {$booking->store?->name} ! Votre avis compte : sur 5, quelle note nous donnez-vous ? Un mot sur ce qu’on peut améliorer ? Pour votre prochaine location, répondez simplement à ce message.";
    }

    public static function receiptMessage(Booking $booking): string
    {
        $first = ActionLists::firstNameOf((string) $booking->customer?->name);
        $deposit = (int) $booking->deposit;
        $retained = (int) $booking->deposit_retained;
        $line = $retained === 0
            ? 'caution de '.Invoicing::money($deposit).' rendue en totalité'
            : 'caution de '.Invoicing::money($deposit).' : '.Invoicing::money($retained).' retenus ('.mb_strtolower(Booking::DEPOSIT_REASONS[$booking->deposit_reason] ?? 'autre').($booking->deposit_note ? ' : '.$booking->deposit_note : '').'), '.Invoicing::money($booking->depositRefund()).' rendus';

        return "Bonjour {$first}, reçu de retour du {$booking->vehicle->label()} le ".($booking->returned_at ?? now())->format('d/m/Y à H\hi')
            .($booking->km_in ? ', '.number_format($booking->km_in, 0, ',', ' ').' km' : '')
            .($booking->fuel_in !== null ? ", carburant {$booking->fuel_in} %" : '')
            .". {$line}. Merci et à bientôt chez {$booking->store?->name} !";
    }

    public static function waUrl(Booking|VehicleRequest|VehicleSale $record, string $message): ?string
    {
        $phone = $record->customer?->recipient()->phone_e164;

        return $phone ? ActionLists::whatsappUrl($phone, $message) : null;
    }

    /** Vente au nom du client d'une réservation (course terminée, retenue sur caution). */
    public function recordSale(Booking $booking, User $user, int $amount, string $label, ?string $paymentMethod = null): Purchase
    {
        return $this->sale($booking, $user, $amount, $label, $paymentMethod);
    }

    private function sale(Booking $booking, User $user, int $amount, string $label, ?string $paymentMethod): Purchase
    {
        $customer = $booking->customer;
        $contact = Contact::where('store_id', $booking->store_id)->where('customer_id', $customer->id)->first()
            ?? Contact::create(['store_id' => $booking->store_id, 'phone_e164' => $customer->phone_e164, 'name' => $customer->name, 'consent_status' => 'unknown']);

        $purchase = Purchase::create([
            'contact_id' => $contact->id, 'store_id' => $booking->store_id, 'amount' => $amount, 'quantity' => 1, 'subtotal' => $amount, 'discount_amount' => 0,
            'currency' => $user->organization->currency ?? 'XAF', 'product_category' => 'Location de véhicules', 'purchased_at' => now(),
            'payment_method' => $paymentMethod ?? 'cash', 'type' => Purchase::TYPE_SALE, 'source' => 'rental',
        ]);
        PurchaseItem::create(['purchase_id' => $purchase->id, 'type' => 'service', 'name' => mb_substr($label, 0, 255), 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount, 'discount_amount' => 0]);

        return $purchase;
    }
}
