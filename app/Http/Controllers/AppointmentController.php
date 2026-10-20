<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\CustomerScoring;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Agenda des rendez-vous, par établissement du périmètre de l'utilisateur.
 * Un rendez-vous se prend depuis la fiche client ou depuis l'agenda (client
 * existant ou nouveau : un numéro inconnu crée le contact dans l'établissement).
 */
class AppointmentController extends Controller
{
    /** Relations lues par present(). */
    public const RELATIONS = ['customer:id,name,phone_e164', 'store:id,name', 'staff:id,name', 'items', 'purchase:id,amount'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $stores = $this->stores($request);
        $storeId = $stores->contains('id', $request->integer('store')) ? $request->integer('store') : null;

        // Mois : semaines complètes (lundi → dimanche) qui couvrent le mois, comme un calendrier mural.
        $view = in_array($request->query('view'), ['day', 'week', 'month'], true) ? (string) $request->query('view') : 'week';
        $date = rescue(fn () => Carbon::parse((string) $request->query('date', today()->toDateString()))->startOfDay(), today(), false);
        [$from, $to] = match ($view) {
            'month' => [$date->copy()->startOfMonth()->startOfWeek(), $date->copy()->endOfMonth()->endOfWeek()->addDay()->startOfDay()],
            'week' => [$date->copy()->startOfWeek(), $date->copy()->startOfWeek()->addDays(7)],
            default => [$date->copy(), $date->copy()->addDay()],
        };
        $storeIds = $storeId ? [$storeId] : $stores->pluck('id')->all();
        $messages = $user->organization->actionMessages();

        $appointments = Appointment::whereIn('store_id', $storeIds)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->with(self::RELATIONS)
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('Appointments/Index', [
            'view' => $view,
            'date' => $date->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->copy()->subDay()->toDateString(),
            'store' => $storeId,
            'stores' => $stores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'staff' => $this->staffOptions($request),
            'statuses' => Appointment::STATUSES,
            'catalog' => self::catalogFor($user->organization_id),
            'appointments' => $appointments->map(fn (Appointment $a) => $this->present($a, $messages)),
            // Colonne « À venir » : prochains rendez-vous, quelle que soit la période affichée.
            'upcoming' => Appointment::whereIn('store_id', $storeIds)
                ->whereIn('status', Appointment::OPEN_STATUSES)
                ->where('starts_at', '>=', now())
                ->with(self::RELATIONS)
                ->orderBy('starts_at')
                ->take(6)
                ->get()
                ->map(fn (Appointment $a) => $this->present($a, $messages)),
            'counts' => [
                'total' => $appointments->where('status', '!=', 'cancelled')->count(),
                'confirmed' => $appointments->where('status', 'confirmed')->count(),
                'done' => $appointments->where('status', 'done')->count(),
                'no_show' => $appointments->where('status', 'no_show')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, creating: true);
        $customer = $this->resolveCustomer($request, $data);
        $items = $this->catalogItems($request, $data);

        DB::transaction(function () use ($request, $data, $customer, $items) {
            $appointment = Appointment::create([
                ...$this->attributes($data, $items),
                'customer_id' => $customer->id,
                'created_by' => $request->user()->id,
                'status' => $data['status'] ?? 'planned',
            ]);
            $this->syncItems($appointment, $items);
        });

        return back()->with('success', "Rendez-vous de {$customer->name} enregistré.");
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $data = $this->validated($request, creating: false);
        $items = $this->catalogItems($request, $data);

        DB::transaction(function () use ($appointment, $data, $items) {
            $appointment->update([...$this->attributes($data, $items), 'status' => $data['status'] ?? $appointment->status]);
            $this->syncItems($appointment, $items);
        });

        return back()->with('success', 'Rendez-vous mis à jour.');
    }

    /** Changement rapide de statut (confirmé, honoré, absent, annulé). */
    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Appointment::STATUSES))]]);

        $appointment->update(['status' => $data['status']]);

        return back()->with('success', 'Rendez-vous : '.Appointment::STATUSES[$data['status']].'.');
    }

    /**
     * Encaissement d'un rendez-vous : crée la vente (prestations du
     * rendez-vous, produits vendus sur place, lignes libres) pour le contact
     * du client dans l'établissement, et passe le rendez-vous à « Honoré ».
     * La vente alimente aussitôt le CA, le parcours client, le RFM et le churn.
     */
    public function checkout(Request $request, Appointment $appointment, CustomerScoring $scoring): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $organizationId = $request->user()->organization_id;

        if ($appointment->purchase_id !== null) {
            throw ValidationException::withMessages(['lines' => 'Ce rendez-vous est déjà encaissé.']);
        }

        $data = $request->validate([
            'purchased_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('organization_id', $organizationId)],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:100000000'],
        ], [
            'lines.required' => 'Ajoutez au moins une ligne à encaisser.',
        ]);

        $catalog = CatalogItem::whereIn('id', collect($data['lines'])->pluck('catalog_item_id')->filter())
            ->with('category:id,name')
            ->get()
            ->keyBy('id');

        $lines = collect($data['lines'])->map(function (array $line) use ($catalog) {
            $item = isset($line['catalog_item_id']) ? $catalog->get($line['catalog_item_id']) : null;

            return [
                'catalog_item_id' => $item?->id,
                'type' => $item?->type,
                'name' => $line['name'],
                'quantity' => (int) $line['quantity'],
                'unit_price' => (int) $line['unit_price'],
                'amount' => (int) $line['quantity'] * (int) $line['unit_price'],
                'category' => $item?->category?->name,
            ];
        });

        $purchase = DB::transaction(function () use ($appointment, $data, $lines) {
            $customer = $appointment->customer;
            $contact = Contact::where('store_id', $appointment->store_id)->where('customer_id', $customer->id)->first()
                ?? Contact::create([
                    'store_id' => $appointment->store_id,
                    'phone_e164' => $customer->phone_e164,
                    'name' => $customer->name,
                    'consent_status' => 'unknown',
                ]);

            $purchase = Purchase::create([
                'contact_id' => $contact->id,
                'store_id' => $appointment->store_id,
                'amount' => $lines->sum('amount'),
                'quantity' => $lines->sum('quantity'),
                // Catégorie de la vente (top catégories) : celle de la première ligne qui en a une.
                'product_category' => $lines->pluck('category')->filter()->first() ?? $lines->first()['name'],
                'purchased_at' => isset($data['purchased_at']) ? Carbon::parse($data['purchased_at'])->toDateString() : $appointment->starts_at->toDateString(),
            ]);
            $purchase->items()->createMany($lines->map(fn ($line) => collect($line)->except('category')->all())->all());

            $appointment->update(['purchase_id' => $purchase->id, 'status' => 'done']);

            return $purchase;
        });

        $scoring->refreshOrganization($request->user()->organization);

        return back()->with('success', 'Encaissé : '.number_format((int) $purchase->amount, 0, ',', ' ').' FCFA, enregistré dans les ventes.');
    }

    public function destroy(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $appointment->delete();

        return back()->with('success', 'Rendez-vous supprimé.');
    }

    /**
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    public static function present(Appointment $a, array $messages): array
    {
        $text = strtr($messages['appointment_reminder'], [
            '{prenom}' => (string) strtok(trim((string) $a->customer?->name), ' '),
            '{nom}' => (string) $a->customer?->name,
            '{boutique}' => (string) $a->store?->name,
            '{date_rdv}' => $a->starts_at->copy()->locale('fr')->translatedFormat('l j F'),
            '{heure_rdv}' => $a->starts_at->format('H\hi'),
            '{prestation}' => (string) $a->service,
        ]);

        return [
            'id' => $a->id,
            'starts_at' => $a->starts_at->format('Y-m-d\TH:i'),
            'duration_minutes' => $a->duration_minutes,
            'service' => $a->service,
            'status' => $a->status,
            'notes' => $a->notes,
            'store_id' => $a->store_id,
            'store_name' => $a->store?->name,
            'staff_user_id' => $a->staff_user_id,
            'staff_name' => $a->staff?->name,
            'customer_id' => $a->customer_id,
            'customer_name' => $a->customer?->name,
            'phone_e164' => $a->customer?->phone_e164,
            'whatsapp_url' => $a->customer ? ActionLists::whatsappUrl($a->customer->phone_e164, $text) : null,
            'items' => $a->items->map(fn (AppointmentItem $i) => [
                'catalog_item_id' => $i->catalog_item_id,
                'name' => $i->name,
                'price' => $i->price,
                'duration_minutes' => $i->duration_minutes,
            ])->values(),
            'price' => (int) $a->items->sum('price'),
            'purchase_id' => $a->purchase_id,
            'paid_amount' => $a->purchase ? (int) $a->purchase->amount : null,
        ];
    }

    /**
     * Articles actifs du catalogue, pour la prise de rendez-vous et l'encaissement.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function catalogFor(int $organizationId): Collection
    {
        return CatalogItem::where('organization_id', $organizationId)
            ->where('active', true)
            ->with(['category:id,name', 'stores:id'])
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $item) => $item->present());
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $storeIds = $this->stores($request)->pluck('id')->all();

        return $request->validate([
            'store_id' => ['required', 'integer', Rule::in($storeIds)],
            'customer_id' => $creating ? ['nullable', 'integer', 'required_without:phone'] : ['prohibited'],
            'phone' => $creating ? ['nullable', 'string', 'max:30', 'required_without:customer_id'] : ['prohibited'],
            'name' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'service' => ['nullable', 'string', 'max:255'],
            'staff_user_id' => ['nullable', 'integer', Rule::in($this->staffOptions($request)->pluck('id'))],
            'status' => ['nullable', Rule::in(array_keys(Appointment::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Prestations du catalogue choisies pour le rendez-vous.
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ], [
            'store_id.in' => 'Choisissez un établissement de votre périmètre.',
            'phone.required_without' => 'Indiquez le numéro du client.',
        ]);
    }

    /**
     * Prestations choisies, dans l'ordre du formulaire (une même prestation
     * peut être choisie deux fois). Seules les prestations de l'entreprise.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, CatalogItem>|null null = champ absent (prestations inchangées)
     */
    private function catalogItems(Request $request, array $data): ?Collection
    {
        if (! array_key_exists('item_ids', $data)) {
            return null;
        }

        $ids = array_map('intval', $data['item_ids'] ?? []);
        $items = CatalogItem::where('organization_id', $request->user()->organization_id)
            ->where('type', CatalogItem::TYPE_SERVICE)
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id) => $items->get($id))->filter()->values();
    }

    /** @param Collection<int, CatalogItem>|null $items */
    private function syncItems(Appointment $appointment, ?Collection $items): void
    {
        if ($items === null) {
            return;
        }

        $appointment->items()->delete();
        $appointment->items()->createMany($items->map(fn (CatalogItem $item) => [
            'catalog_item_id' => $item->id,
            'name' => $item->name,
            'price' => $item->price,
            'duration_minutes' => (int) $item->duration_minutes,
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, CatalogItem>|null  $items
     */
    private function attributes(array $data, ?Collection $items = null): array
    {
        // Avec des prestations du catalogue : libellé et durée par défaut en découlent.
        $label = $items !== null && $items->isNotEmpty() ? $items->pluck('name')->implode(' + ') : null;
        $duration = $data['duration_minutes'] ?? ($items !== null && $items->isNotEmpty() ? (int) $items->sum('duration_minutes') : 30);

        return [
            'store_id' => $data['store_id'],
            'starts_at' => Carbon::parse($data['starts_at']),
            'duration_minutes' => max(5, $duration),
            'service' => $label ?? ($data['service'] ?? null),
            'staff_user_id' => $data['staff_user_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Client du rendez-vous : fiche existante du périmètre, ou client trouvé /
     * créé par son numéro dans l'établissement du rendez-vous.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(Request $request, array $data): Customer
    {
        $organizationId = $request->user()->organization_id;
        $storeIds = $this->stores($request)->pluck('id')->all();

        if (! empty($data['customer_id'])) {
            return Customer::where('organization_id', $organizationId)
                ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $storeIds))
                ->find($data['customer_id'])
                ?? throw new HttpException(403, "Ce client n'est pas dans votre périmètre.");
        }

        $phone = PhoneNormalizer::normalize((string) $data['phone'])
            ?? throw ValidationException::withMessages(['phone' => 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.']);

        $contact = Contact::where('store_id', $data['store_id'])->where('phone_e164', $phone)->first()
            ?? Contact::create([
                'store_id' => $data['store_id'],
                'phone_e164' => $phone,
                'name' => trim((string) ($data['name'] ?? '')) ?: 'Client '.$phone,
                'consent_status' => 'unknown',
            ]);

        return $contact->customer()->firstOrFail();
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        if (! $this->stores($request)->contains('id', $appointment->store_id)) {
            throw new HttpException(403, "Ce rendez-vous n'est pas dans votre périmètre.");
        }
    }

    /** @return Collection<int, Store> */
    private function stores(Request $request): Collection
    {
        $user = $request->user();
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, array{id: int, name: string}> */
    private function staffOptions(Request $request): Collection
    {
        return User::where('organization_id', $request->user()->organization_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);
    }
}
