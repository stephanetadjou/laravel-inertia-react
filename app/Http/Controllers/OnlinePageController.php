<?php

namespace App\Http\Controllers;

use App\Models\BookableResource;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Modules\CarteDigitale\Services\DigitalCards;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Models\VehicleSale;
use App\Modules\Location\Services\RentalDesk;
use App\Services\ActionLists;
use App\Services\Audit;
use App\Services\Fleet;
use App\Services\PhoneNormalizer;
use App\Support\OnlinePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lien public d'une entreprise : « Que souhaitez-vous ? » (/rdv/{slug}) et
 * réglages (Paramètres › Ma page en ligne). Chaque envoi crée ou retrouve la
 * fiche client (source « Lien en ligne », ou carte digitale avec ?carte=),
 * la bonne demande et une tâche dans « À faire ».
 */
class OnlinePageController extends Controller
{
    public function show(Request $request, Organization $organization): Response
    {
        $card = $this->card($organization, $request->query('carte'));
        $phone = $organization->stores()->get()->map->bookingPhone()->filter()->first();
        $location = $organization->hasModule('location') || $organization->hasModule('vehicules');

        return Inertia::render('OnlinePage/Show', [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'stores' => $organization->stores()->orderBy('name')->get(['id', 'name', 'city']),
            'choices' => collect(OnlinePage::enabledChoices($organization))->map(fn ($k) => ['key' => $k, ...OnlinePage::CHOICES[$k]])->values(),
            'card' => $card ? ['slug' => $card->slug, 'name' => $card->name] : null,
            'whatsapp' => $phone ? ActionLists::whatsappUrl($phone, "Bonjour {$organization->name}, je souhaite être rappelé(e).") : null,
            'fares' => $location ? RideFare::where('organization_id', $organization->id)->orderBy('label')->get(['label', 'price']) : [],
            'categories' => $location ? BookableResource::fleet($organization->id)->where('status', 'available')->get()->groupBy('category')
                ->map(fn ($g, $c) => ['key' => $c, 'label' => BookableResource::CATEGORIES[$c] ?? $c, 'from' => (int) $g->min('price_day')])->values() : [],
            'forSale' => $location ? BookableResource::where('organization_id', $organization->id)->where('kind', 'vehicule')->where('status', 'for_sale')->orderBy('name')->get()
                ->map(fn ($v) => ['id' => $v->id, 'label' => $v->name, 'mileage' => $v->mileage, 'price' => $v->sale_price,
                    'url' => $v->getRawOriginal('sale_token') ? url('/annonce/'.$v->getRawOriginal('sale_token')) : null,
                    'reserved' => VehicleSale::where('resource_id', $v->id)->where('status', 'reserved')->exists()])->values() : [],
            'services' => CatalogItem::where('organization_id', $organization->id)->where('active', true)->whereNotNull('price')->orderBy('name')->limit(20)->get(['name', 'price']),
            'depositPercent' => 30,
            'sent' => session('online_sent'),
        ]);
    }

    /** Véhicules libres sur une période (formulaire « Louer »). */
    public function available(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($organization->hasModule('location'), 404);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from']]);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        $days = RentalDesk::days($from, $to);

        $vehicles = BookableResource::fleet($organization->id)->where('status', 'available')->orderBy('price_day')->get()
            ->filter(fn ($v) => Fleet::conflict($v->id, $from, $to) === null)
            ->map(fn ($v) => ['id' => $v->id, 'label' => $v->name, 'category' => BookableResource::CATEGORIES[$v->category] ?? null, 'price_day' => (int) $v->price_day, 'estimate' => (int) $v->price_day * $days])
            ->values();

        return response()->json(['days' => $days, 'vehicles' => $vehicles]);
    }

    public function submit(Request $request, Organization $organization, RentalDesk $desk): RedirectResponse
    {
        $choices = OnlinePage::enabledChoices($organization);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_values(array_intersect($choices, ['rent', 'event', 'buy', 'test_drive', 'trial', 'visit', 'quote', 'callback'])))],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'website' => ['nullable', 'max:0'],
            'carte' => ['nullable', 'string', 'max:120'],
            'store_id' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'resource_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(array_keys(BookableResource::CATEGORIES))],
            'with_driver' => ['nullable', 'boolean'],
            'vehicles_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        ['card' => $card] = $this->intake($organization, $data, $desk);

        return redirect('/rdv/'.$organization->slug.($card ? '?carte='.$card->slug : ''))->with('online_sent', $data['type'] === 'callback'
            ? 'Merci ! Un conseiller vous écrit sur WhatsApp très vite.'
            : 'Demande reçue ! L’équipe vous répond sur WhatsApp.');
    }

    /**
     * Moteur commun du lien public et des tunnels de vente : fiche client
     * (ou carte digitale), demande du bon type avec son étape, tâche « À faire ».
     *
     * @param  array<string, mixed>  $data
     * @return array{customer: Customer, request: ?VehicleRequest, card: ?DigitalCard}
     */
    public function intake(Organization $organization, array $data, RentalDesk $desk, string $origin = 'Lien en ligne', string $acquisition = 'lien'): array
    {
        $request2 = null;
        $phone = PhoneNormalizer::normalize($data['phone']) ?? throw ValidationException::withMessages(['phone' => 'Numéro WhatsApp invalide.']);
        $choice = OnlinePage::CHOICES[$data['type']];
        $stage = OnlinePage::STAGES[$choice['stage']];
        $store = $organization->stores()->whereKey($data['store_id'] ?? 0)->first() ?? $organization->stores()->orderBy('id')->firstOrFail();
        $owner = $organization->users()->orderBy('id')->firstOrFail();
        $vehicle = filled($data['resource_id'] ?? null) ? BookableResource::where('organization_id', $organization->id)->find($data['resource_id']) : null;
        $summary = trim($choice['label']
            .($vehicle ? " · {$vehicle->name}" : '')
            .(filled($data['starts_at'] ?? null) ? ' · '.Carbon::parse($data['starts_at'])->locale('fr')->translatedFormat('j M Y H\hi') : '')
            .(filled($data['ends_at'] ?? null) ? ' → '.Carbon::parse($data['ends_at'])->locale('fr')->translatedFormat('j M H\hi') : '')
            .(filled($data['vehicles_count'] ?? null) ? " · {$data['vehicles_count']} véhicule(s)" : '')
            .(! empty($data['with_driver']) ? ' · avec chauffeur' : ''));
        $note = trim("{$origin} · étape {$stage} : {$summary}".(filled($data['message'] ?? null) ? "\n".$data['message'] : ''));

        // Carte digitale (?carte=) : même service que le formulaire de la carte (prospect au nom du commercial, tâche).
        $card = $this->card($organization, $data['carte'] ?? null);
        if ($card) {
            $customer = DigitalCards::receiveContact($card, trim($data['name']), $phone, $note, false)->fresh()->customer;
        } else {
            $customer = $desk->customerFor($owner, $store->id, null, $data['name'], $phone);
            $customer->forceFill(['acquisition_source' => $customer->acquisition_source ?? $acquisition, 'acquired_at' => $customer->acquired_at ?? now()])->save();
        }

        if (in_array($data['type'], ['rent', 'event', 'buy', 'test_drive'], true)) {
            $request2 = $desk->createRequest($owner, [
                'store_id' => $store->id, 'customer_id' => $customer->id, 'source' => 'online',
                'need' => in_array($data['type'], ['buy', 'test_drive'], true) ? 'buy' : 'rent',
                'purpose' => $data['type'] === 'event' ? 'event' : null,
                'category' => $data['category'] ?? null, 'resource_id' => $vehicle?->id,
                'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
                'with_driver' => (bool) ($data['with_driver'] ?? false), 'notes' => $note,
            ]);
            $request2->forceFill(['created_by' => null])->save();

            if ($vehicle && in_array($data['type'], ['buy', 'test_drive'], true)) {
                $sale = VehicleSale::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'resource_id' => $vehicle->id, 'customer_id' => $customer->id,
                    'status' => $data['type'] === 'test_drive' && filled($data['starts_at'] ?? null) ? 'test_drive' : 'interested',
                    'test_drive_at' => $data['type'] === 'test_drive' ? ($data['starts_at'] ?? null) : null, 'notes' => $note]);
                $request2->update(['vehicle_sale_id' => $sale->id]);
            }
        }

        if (! $card) {
            $task = Task::open([
                'organization_id' => $organization->id, 'store_id' => $store->id, 'customer_id' => $customer->id,
                'kind' => 'online_request', 'title' => "{$origin} · {$stage} : {$choice['label']}", 'details' => mb_substr($note, 0, 500),
            ]);
        }

        return ['customer' => $customer, 'request' => $request2, 'card' => $card];
    }

    // --- Paramètres › Ma page en ligne ---

    public function settings(Request $request): Response
    {
        $organization = $request->user()->organization;

        return Inertia::render('Settings/OnlinePage', [
            'enabled' => OnlinePage::isEnabled($organization),
            'available' => collect(OnlinePage::available($organization))->map(fn ($k) => ['key' => $k, ...OnlinePage::CHOICES[$k], 'stage_label' => OnlinePage::STAGES[OnlinePage::CHOICES[$k]['stage']]])->values(),
            'selected' => OnlinePage::enabledChoices($organization),
            'url' => $organization->bookingUrl(),
            'rideUrl' => $organization->hasModule('location') ? url("/rdv/{$organization->slug}/course") : null,
            'name' => $organization->name,
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $organization = $request->user()->organization;
        $data = $request->validate(['enabled' => ['boolean'], 'choices' => ['array'], 'choices.*' => [Rule::in(OnlinePage::available($organization))]]);
        $organization->forceFill(['online_page' => ['enabled' => (bool) ($data['enabled'] ?? false), 'choices' => array_values($data['choices'] ?? [])]])->save();
        Audit::record('settings.online_page', $organization, 'Ma page en ligne mise à jour', $data);

        return back()->with('success', 'Votre page en ligne est à jour.');
    }

    public function card(Organization $organization, mixed $slug): ?DigitalCard
    {
        if (! is_string($slug) || $slug === '' || ! class_exists(DigitalCard::class)) {
            return null;
        }

        return DigitalCard::where('organization_id', $organization->id)->where('slug', $slug)->where('is_active', true)->with('organization', 'user')->first();
    }
}
