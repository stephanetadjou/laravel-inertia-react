<?php

namespace App\Modules\CarteDigitale\Http;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Modules\CarteDigitale\Services\DigitalCards;
use App\Services\Audit;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Application « Carte digitale » : les cartes de l'équipe, leurs statistiques, l'impression. */
class DigitalCardController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;
        // Période des statistiques bornée par l'offre (au-delà : grisée côté écran).
        $maxDays = DigitalCards::statsDays($organization);
        $days = in_array((int) $request->query('days'), [7, 30, 90, 365], true) ? (int) $request->query('days') : min(30, $maxDays);
        $days = min($days, $maxDays);
        $since = now()->subDays($days);

        $cards = DigitalCard::where('organization_id', $organization->id)->with(['user:id,name', 'store:id,name'])->orderBy('name')->get();

        // Statistiques de la période, par carte et par type d'événement.
        $stats = DB::table('digital_card_events')->whereIn('digital_card_id', $cards->pluck('id'))->where('created_at', '>=', $since)
            ->selectRaw('digital_card_id, type, count(*) as n')->groupBy('digital_card_id', 'type')->get()
            ->groupBy('digital_card_id')->map(fn ($rows) => $rows->pluck('n', 'type')->map(fn ($n) => (int) $n));

        $customers = Customer::where('organization_id', $organization->id)->whereIn('acquisition_card_id', $cards->pluck('id'))
            ->selectRaw('acquisition_card_id, count(*) as n')->groupBy('acquisition_card_id')->pluck('n', 'acquisition_card_id');

        $rows = $cards->map(function (DigitalCard $card) use ($stats, $customers) {
            $s = $stats->get($card->id, collect());

            return [
                ...$this->present($card),
                'stats' => [
                    'views' => (int) ($s['view'] ?? 0) + (int) ($s['scan'] ?? 0),
                    'scans' => (int) ($s['scan'] ?? 0),
                    'whatsapp' => (int) ($s['whatsapp'] ?? 0),
                    'calls' => (int) ($s['call'] ?? 0),
                    'vcard' => (int) ($s['vcard'] ?? 0),
                    'booking' => (int) ($s['booking'] ?? 0),
                    'contacts' => (int) ($s['contact'] ?? 0),
                    'social' => (int) ($s['social'] ?? 0),
                    'customers' => (int) ($customers[$card->id] ?? 0),
                ],
            ];
        })->values();

        return Inertia::render('Cards/Index', [
            'cards' => $rows,
            'days' => $days,
            'periods' => collect([7, 30, 90, 365])->map(fn ($d) => ['days' => $d, 'allowed' => $d <= $maxDays, 'pack' => DigitalCards::packFor('card_stats_days', $d)])->values(),
            'canPrint' => DigitalCards::canPrint($organization),
            'printPack' => DigitalCards::packFor('card_print', true),
            'limit' => DigitalCards::limit($organization),
            'canCreate' => DigitalCards::canCreate($organization),
            'nextPack' => DigitalCards::nextPack($organization),
            'packLabel' => $organization->pack(),
            'branding' => DigitalCards::showsBranding($organization),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $organization = $request->user()->organization;
        if (! DigitalCards::canCreate($organization)) {
            return redirect()->route('cards.index')->with('error', $this->limitMessage($organization));
        }

        return Inertia::render('Cards/Edit', [
            'card' => null,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        if (! DigitalCards::canCreate($organization)) {
            return redirect()->route('cards.index')->with('error', $this->limitMessage($organization));
        }

        $data = $this->validated($request);
        $card = DigitalCard::create([
            ...collect($data)->except('photo')->all(),
            'organization_id' => $organization->id,
            'slug' => DigitalCard::uniqueSlug($data['name']),
            'photo_path' => $request->file('photo')?->store("cards/{$organization->id}"),
        ]);
        Audit::record('card.created', $card, "Carte digitale créée : {$card->name}", [], $user);

        return redirect()->route('cards.index')->with('success', 'Carte créée. Partagez son lien ou imprimez son QR code.');
    }

    public function edit(Request $request, DigitalCard $card): Response
    {
        $this->authorizeCard($request->user(), $card);

        return Inertia::render('Cards/Edit', [
            'card' => $this->present($card),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(Request $request, DigitalCard $card): RedirectResponse
    {
        $this->authorizeCard($request->user(), $card);
        $data = $this->validated($request);
        $card->update([
            ...collect($data)->except('photo')->all(),
            ...($request->hasFile('photo') ? ['photo_path' => $request->file('photo')->store("cards/{$card->organization_id}")] : []),
        ]);

        return redirect()->route('cards.index')->with('success', 'Carte mise à jour.');
    }

    public function destroy(Request $request, DigitalCard $card): RedirectResponse
    {
        $this->authorizeCard($request->user(), $card);
        Audit::record('card.deleted', $card, "Carte digitale supprimée : {$card->name}", [], $request->user());
        $card->delete();

        return redirect()->route('cards.index')->with('success', 'Carte supprimée.');
    }

    /** Carte de visite 85 × 55 mm à imprimer (ou enregistrer en PDF depuis le navigateur). */
    public function print(Request $request, DigitalCard $card): Response|RedirectResponse
    {
        $this->authorizeCard($request->user(), $card);
        if (! DigitalCards::canPrint($request->user()->organization)) {
            return redirect()->route('cards.index')->with('error', 'L’impression 85 × 55 mm est disponible à partir de l’offre '.DigitalCards::packFor('card_print', true).'. Le QR code reste téléchargeable.');
        }

        return Inertia::render('Cards/Print', [
            'card' => $this->present($card),
            'branding' => DigitalCards::showsBranding($request->user()->organization),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(DigitalCard $card): array
    {
        return [
            'id' => $card->id,
            'slug' => $card->slug,
            'url' => $card->publicUrl(),
            'qr_url' => $card->publicUrl().'?src=qr',
            'name' => $card->name,
            'job_title' => $card->job_title,
            'company' => $card->company,
            'phone' => $card->phone_e164 ? '+'.$card->phone_e164 : null,
            'whatsapp' => $card->whatsapp_e164 ? '+'.$card->whatsapp_e164 : null,
            'email' => $card->email,
            'website' => $card->website,
            'socials' => collect(DigitalCard::SOCIALS)->mapWithKeys(fn ($v, $key) => [$key => $card->socials[$key] ?? ''])->all(),
            'social_links' => $card->socialLinks(),
            'address' => $card->address,
            'bio' => $card->bio,
            'color' => $card->color,
            'photo_url' => $card->photo_path ? url("/c/{$card->slug}/photo") : null,
            'booking_enabled' => $card->booking_enabled,
            'is_active' => $card->is_active,
            'store_id' => $card->store_id,
            'store_name' => $card->store?->name,
            'user_id' => $card->user_id,
            'user_name' => $card->user?->name,
            'staff_member_id' => $card->staff_member_id,
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(User $user): array
    {
        $visible = $user->visibleStoreIds();
        $stores = Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->orderBy('name')->get(['id', 'name']);

        return [
            'stores' => $stores,
            // En multi-boutiques, la boutique est obligatoire : par défaut celle de l'utilisateur.
            'defaultStoreId' => $stores->contains('id', $user->store_id) ? $user->store_id : ($stores->count() === 1 ? $stores->first()->id : null),
            'users' => User::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            'staff' => StaffMember::whereIn('store_id', $stores->pluck('id'))->orderBy('name')->get(['id', 'name']),
            'colors' => DigitalCard::COLORS,
            'socialNetworks' => collect(DigitalCard::SOCIALS)->map(fn ($v, $key) => ['key' => $key, 'label' => $v[0], 'handle' => $v[1] !== null])->values(),
            'organizationName' => $user->organization->name,
            'canPrint' => DigitalCards::canPrint($user->organization),
            'bookingAvailable' => $user->organization->hasModule('rdv') && (bool) ($user->organization->bookingSettings()['enabled'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $orgId = $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:500'],
            'color' => ['required', Rule::in(DigitalCard::COLORS)],
            'booking_enabled' => ['boolean'],
            'is_active' => ['boolean'],
            // Plusieurs boutiques : chaque carte appartient à une boutique (contacts reçus, statistiques).
            'store_id' => [Rule::requiredIf(Store::where('organization_id', $orgId)->count() > 1), 'nullable', 'integer', Rule::exists('stores', 'id')->where('organization_id', $orgId),
                Rule::in($request->user()->visibleStoreIds() ?? Store::where('organization_id', $orgId)->pluck('id')->all())],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'staff_member_id' => ['nullable', 'integer', Rule::exists('staff_members', 'id')],
            'photo' => ['nullable', 'image', 'max:4096'],
            'socials' => ['nullable', 'array'],
            'socials.*' => ['nullable', 'string', 'max:255'],
        ]);

        // Réseaux : URL complète ou @pseudo (converti en lien).
        $socials = [];
        foreach (array_keys(DigitalCard::SOCIALS) as $key) {
            $url = DigitalCard::normalizeSocial($key, $data['socials'][$key] ?? null);
            if ($url === false) {
                throw ValidationException::withMessages(["socials.{$key}" => 'Lien invalide : collez l’adresse complète ou votre @pseudo.']);
            }
            if ($url !== null) {
                $socials[$key] = $url;
            }
        }
        $data['socials'] = $socials ?: null;

        foreach (['phone' => 'phone_e164', 'whatsapp' => 'whatsapp_e164'] as $field => $column) {
            $raw = trim((string) ($data[$field] ?? ''));
            $data[$column] = $raw === '' ? null : (PhoneNormalizer::normalize($raw)
                ?? throw ValidationException::withMessages([$field => 'Numéro invalide : attendu '.PhoneNormalizer::EXPECTED_FORMAT.'.']));
            unset($data[$field]);
        }
        $data['booking_enabled'] = (bool) ($data['booking_enabled'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    private function limitMessage($organization): string
    {
        $next = DigitalCards::nextPack($organization);
        $offer = $next === null ? '' : ' Passez à l’offre '.$next['label'].' pour '.($next['cards'] === null ? 'des cartes illimitées' : $next['cards'].' cartes').'.';

        return 'Votre offre comprend '.DigitalCards::limit($organization).' cartes digitales.'.$offer;
    }

    private function authorizeCard(User $user, DigitalCard $card): void
    {
        abort_unless($card->organization_id === $user->organization_id, 404);
    }

    /** Photo de la carte, servie depuis le stockage privé (page publique). */
    public static function photo(DigitalCard $card)
    {
        abort_if($card->photo_path === null || ! Storage::exists($card->photo_path), 404);

        return Storage::response($card->photo_path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
