<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\StaffMember;
use App\Models\Store;
use App\Services\BookingAvailability;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Réservation en ligne, page publique /rdv/{entreprise} (sans compte) :
 * prestations, créneau libre, nom et numéro WhatsApp. La réservation arrive
 * dans l'agenda « À confirmer » (ou confirmée si l'entreprise l'a choisi).
 *
 * Deux demandes pour le dernier créneau libre : la vérification et
 * l'enregistrement se font dans une seule transaction qui verrouille
 * l'établissement ; la seconde demande reçoit « créneau pris » et les
 * créneaux les plus proches.
 */
class BookingController extends Controller
{
    /** Réservations en ligne encore à venir autorisées par numéro. */
    public const MAX_PENDING_PER_PHONE = 3;

    public function show(Request $request, Organization $organization): Response
    {
        // Page fermée (ou sans prestation réservable) : explication plutôt qu'une erreur 404,
        // avec le chemin du réglage pour un membre de l'entreprise connecté.
        $closed = ! $organization->bookingSettings()['enabled'] ? 'closed' : ($this->services($organization)->isEmpty() ? 'no_services' : null);

        if ($closed !== null) {
            return Inertia::render('Booking/Closed', [
                'organization' => ['name' => $organization->name],
                'reason' => $closed,
                'isMember' => $request->user()?->organization_id === $organization->id,
            ]);
        }

        $settings = $this->settings($organization);
        $stores = $this->stores($organization);

        return Inertia::render('Booking/Show', [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'welcome' => $settings['welcome'],
            'stores' => $stores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'services' => $this->services($organization)->map(fn (CatalogItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category?->name,
                'price' => (int) $item->price,
                'duration_minutes' => (int) $item->duration_minutes,
                'store_ids' => $item->stores->pluck('id')->values(),
            ])->values(),
            // Intervenants proposés au choix (« Peu importe » par défaut), par établissement.
            'staff' => StaffMember::whereIn('store_id', $stores->pluck('id'))
                ->where('active', true)
                ->where('bookable_online', true)
                ->with('services:id')
                ->orderBy('name')
                ->get()
                ->map(fn (StaffMember $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'store_id' => $s->store_id,
                    'service_ids' => $s->services->pluck('id')->values(),
                ])->values(),
            'privacyUrl' => $organization->privacyPolicyUrl(),
        ]);
    }

    /** Jours ouverts et créneaux libres d'un jour, pour les prestations choisies. */
    public function slots(Request $request, Organization $organization): JsonResponse
    {
        $settings = $this->settings($organization);
        $data = $request->validate([
            'store' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:5'],
            'items.*' => ['integer'],
            'date' => ['nullable', 'date'],
            'staff' => ['nullable', 'integer'],
        ]);

        $store = $this->stores($organization)->firstWhere('id', (int) $data['store']) ?? throw new NotFoundHttpException;
        $items = $this->items($organization, $store, $data['items']);
        $serviceIds = $items->pluck('id')->all();
        $duration = max(5, (int) $items->sum('duration_minutes'));
        $availability = new BookingAvailability($store, $settings);
        $staffId = $this->staffId($availability, $serviceIds, $data['staff'] ?? null);
        $days = $availability->openDays($duration, $serviceIds, $staffId);
        $date = isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : ($days[0] ?? today()->toDateString());

        return response()->json([
            'days' => $days,
            'date' => $date,
            'slots' => $availability->slots(Carbon::parse($date), $duration, $serviceIds, $staffId),
            'duration' => $duration,
            // Intervenants capables de réaliser ces prestations.
            'staff' => $availability->staffFor($serviceIds)->map(fn (StaffMember $s) => ['id' => $s->id, 'name' => $s->name])->values(),
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $settings = $this->settings($organization);

        $data = $request->validate([
            'store' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:5'],
            'items.*' => ['integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'consent' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'staff' => ['nullable', 'integer'],
            // Piège à robots : champ caché, toujours vide pour un humain.
            'website' => ['nullable', 'max:0'],
        ], [
            'items.required' => 'Choisissez au moins une prestation.',
            'name.required' => 'Indiquez votre nom.',
            'phone.required' => 'Indiquez votre numéro WhatsApp.',
            'website.max' => 'Réservation refusée.',
        ]);

        $store = $this->stores($organization)->firstWhere('id', (int) $data['store']) ?? throw new NotFoundHttpException;
        $items = $this->items($organization, $store, $data['items']);
        $duration = max(5, (int) $items->sum('duration_minutes'));
        $phone = PhoneNormalizer::normalize($data['phone'])
            ?? throw ValidationException::withMessages(['phone' => 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.']);
        $start = Carbon::parse("{$data['date']} {$data['time']}");

        $pending = Appointment::where('source', Appointment::SOURCE_ONLINE)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->where('starts_at', '>=', now())
            ->whereHas('customer', fn ($q) => $q->where('organization_id', $organization->id)->where('phone_e164', $phone))
            ->count();

        if ($pending >= self::MAX_PENDING_PER_PHONE) {
            throw ValidationException::withMessages(['phone' => 'Vous avez déjà plusieurs rendez-vous à venir : contactez l’établissement pour en prendre un autre.']);
        }

        $serviceIds = $items->pluck('id')->all();

        $appointment = DB::transaction(function () use ($store, $settings, $start, $duration, $items, $serviceIds, $data, $phone) {
            // Verrou de l'établissement : deux réservations simultanées sont traitées l'une après l'autre.
            $locked = Store::whereKey($store->id)->lockForUpdate()->first();
            $locked->touch();

            $availability = new BookingAvailability($locked, $settings);
            $staffId = $this->staffId($availability, $serviceIds, $data['staff'] ?? null);

            if (! $availability->isFree($start, $duration, $serviceIds, $staffId)) {
                throw ValidationException::withMessages(['time' => 'Ce créneau vient d’être pris. Choisissez un autre horaire.']);
            }

            $staff = $availability->assign($start, $duration, $serviceIds, $staffId);

            $contact = Contact::where('store_id', $store->id)->where('phone_e164', $phone)->first()
                ?? Contact::create([
                    'store_id' => $store->id,
                    'phone_e164' => $phone,
                    'name' => trim($data['name']),
                    'consent_status' => 'unknown',
                ]);

            // Le client coche la case : son accord est daté et sourcé ; jamais retiré ici.
            if (! empty($data['consent']) && $contact->consent_status !== 'opt_in') {
                $contact->update(['consent_status' => 'opt_in', 'consent_at' => now(), 'consent_source' => 'reservation_en_ligne']);
            }

            $appointment = Appointment::create([
                'store_id' => $store->id,
                'customer_id' => $contact->customer_id,
                'starts_at' => $start,
                'duration_minutes' => $duration,
                'service' => $items->pluck('name')->implode(' + '),
                'status' => $settings['auto_confirm'] ? 'confirmed' : 'requested',
                'notes' => $data['notes'] ?? null,
                'source' => Appointment::SOURCE_ONLINE,
                'cancel_token' => Str::random(40),
                'staff_member_id' => $staff?->id,
            ]);
            $appointment->items()->createMany($items->map(fn (CatalogItem $item) => [
                'catalog_item_id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
                'duration_minutes' => (int) $item->duration_minutes,
            ])->all());

            return $appointment;
        });

        return redirect()->route('booking.confirmation', [$organization->slug, $appointment->cancel_token]);
    }

    public function confirmation(Organization $organization, string $token): Response
    {
        $appointment = $this->byToken($organization, $token);
        $store = $appointment->store;
        $when = $appointment->starts_at->copy()->locale('fr')->translatedFormat('l j F \à H\hi');
        $text = "Bonjour, je viens de réserver « {$appointment->service} » chez {$store->name} le {$when}. Au nom de {$appointment->customer->name}.";

        return Inertia::render('Booking/Confirmation', [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'appointment' => [
                'service' => $appointment->service,
                'starts_at' => $appointment->starts_at->format('Y-m-d\TH:i'),
                'duration_minutes' => $appointment->duration_minutes,
                'store_name' => $store->name,
                'status' => $appointment->status,
                'price' => (int) $appointment->items->sum('price'),
                'customer_name' => $appointment->customer->name,
                'staff_name' => $appointment->staffMember?->name,
                'cancellable' => in_array($appointment->status, Appointment::OPEN_STATUSES, true) && $appointment->starts_at->isFuture(),
            ],
            'whatsappUrl' => $store->bookingPhone() ? 'https://wa.me/'.$store->bookingPhone().'?text='.rawurlencode($text) : null,
            'token' => $token,
        ]);
    }

    public function cancel(Organization $organization, string $token): RedirectResponse
    {
        $appointment = $this->byToken($organization, $token);

        if (in_array($appointment->status, Appointment::OPEN_STATUSES, true) && $appointment->starts_at->isFuture()) {
            $appointment->update(['status' => 'cancelled']);
        }

        return redirect()->route('booking.confirmation', [$organization->slug, $token]);
    }

    /** @return array{enabled: bool, auto_confirm: bool, slot_step: int, min_notice_minutes: int, horizon_days: int, welcome: string} */
    private function settings(Organization $organization): array
    {
        $settings = $organization->bookingSettings();

        if (! $settings['enabled']) {
            throw new NotFoundHttpException("La réservation en ligne n'est pas ouverte pour cette entreprise.");
        }

        return $settings;
    }

    /** @return Collection<int, Store> établissements ouverts au moins un jour */
    private function stores(Organization $organization): Collection
    {
        return $organization->stores()->orderBy('name')->get()
            ->filter(fn (Store $s) => array_filter($s->bookingHours()) !== [])
            ->values();
    }

    /** @return Collection<int, CatalogItem> */
    private function services(Organization $organization): Collection
    {
        return CatalogItem::where('organization_id', $organization->id)
            ->where('type', CatalogItem::TYPE_SERVICE)
            ->where('active', true)
            ->where('bookable_online', true)
            ->with(['category:id,name', 'stores:id'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Prestations choisies, réservables dans l'établissement.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, CatalogItem>
     */
    private function items(Organization $organization, Store $store, array $ids): Collection
    {
        $available = $this->services($organization)
            ->filter(fn (CatalogItem $item) => $item->stores->isEmpty() || $item->stores->contains('id', $store->id))
            ->keyBy('id');
        $items = collect($ids)->map(fn ($id) => $available->get((int) $id))->filter()->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Choisissez une prestation proposée dans cet établissement.']);
        }

        return $items;
    }

    /**
     * Intervenant choisi, s'il est proposé pour ces prestations (sinon « peu importe »).
     *
     * @param  array<int, int>  $serviceIds
     */
    private function staffId(BookingAvailability $availability, array $serviceIds, mixed $requested): ?int
    {
        $id = (int) $requested;

        return $id > 0 && $availability->staffFor($serviceIds)->contains('id', $id) ? $id : null;
    }

    private function byToken(Organization $organization, string $token): Appointment
    {
        return Appointment::where('cancel_token', $token)
            ->whereHas('store', fn ($q) => $q->where('organization_id', $organization->id))
            ->with(['store', 'customer', 'items', 'staffMember'])
            ->firstOrFail();
    }
}
