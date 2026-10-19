<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    public function index(Request $request): Response
    {
        $user = $request->user();
        $stores = $this->stores($request);
        $storeId = $stores->contains('id', $request->integer('store')) ? $request->integer('store') : null;

        $view = in_array($request->query('view'), ['day', 'week'], true) ? (string) $request->query('view') : 'week';
        $date = rescue(fn () => Carbon::parse((string) $request->query('date', today()->toDateString()))->startOfDay(), today(), false);
        $from = $view === 'week' ? $date->copy()->startOfWeek() : $date->copy();
        $to = $view === 'week' ? $from->copy()->addDays(7) : $from->copy()->addDay();

        $appointments = Appointment::whereIn('store_id', $storeId ? [$storeId] : $stores->pluck('id'))
            ->whereBetween('starts_at', [$from, $to])
            ->with(['customer:id,name,phone_e164', 'store:id,name', 'staff:id,name'])
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
            'appointments' => $appointments->map(fn (Appointment $a) => $this->present($a, $user->organization->actionMessages())),
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

        Appointment::create([
            ...$this->attributes($data),
            'customer_id' => $customer->id,
            'created_by' => $request->user()->id,
            'status' => $data['status'] ?? 'planned',
        ]);

        return back()->with('success', "Rendez-vous de {$customer->name} enregistré.");
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $data = $this->validated($request, creating: false);

        $appointment->update([...$this->attributes($data), 'status' => $data['status'] ?? $appointment->status]);

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
        ];
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
        ], [
            'store_id.in' => 'Choisissez un établissement de votre périmètre.',
            'phone.required_without' => 'Indiquez le numéro du client.',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function attributes(array $data): array
    {
        return [
            'store_id' => $data['store_id'],
            'starts_at' => Carbon::parse($data['starts_at']),
            'duration_minutes' => $data['duration_minutes'] ?? 30,
            'service' => $data['service'] ?? null,
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
