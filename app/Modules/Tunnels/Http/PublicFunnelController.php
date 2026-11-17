<?php

namespace App\Modules\Tunnels\Http;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OnlinePageController;
use App\Models\BookableResource;
use App\Models\Organization;
use App\Modules\Location\Services\RentalDesk;
use App\Modules\Tunnels\Models\Funnel;
use App\Modules\Tunnels\Models\FunnelLead;
use App\Services\ActionLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page publique d'un tunnel (/t/{entreprise}/{slug}) : offre, compte à
 * rebours ou places restantes, avis, formulaire court et bouton WhatsApp.
 * Après la date de fin, renvoi vers le lien public de l'entreprise.
 */
class PublicFunnelController extends Controller
{
    public function show(Request $request, Organization $organization, string $slug): Response|RedirectResponse
    {
        $funnel = $this->funnel($organization, $slug);
        if (! $funnel->isOpen()) {
            return redirect("/rdv/{$organization->slug}");
        }

        if (! $request->session()->has("funnel_seen_{$funnel->id}")) {
            $funnel->increment('visits');
            $request->session()->put("funnel_seen_{$funnel->id}", true);
        }

        $card = app(OnlinePageController::class)->card($organization, $request->query('carte'));
        $phone = ($funnel->store ?? $organization->stores()->orderBy('id')->first())?->bookingPhone();

        return Inertia::render('Funnels/Public', [
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'funnel' => [
                'slug' => $funnel->slug, 'title' => $funnel->title, 'offer' => $funnel->offer, 'benefits' => $funnel->benefits ?? [], 'reviews' => $funnel->reviews ?? [],
                'fields' => $funnel->fields ?? [], 'ends_at' => $funnel->ends_at?->toIso8601String(), 'seats_left' => $funnel->seatsLeft(),
                'image' => $funnel->image_path ? url("/t/{$organization->slug}/{$funnel->slug}/image") : null,
            ],
            'vehicles' => in_array('vehicle', $funnel->fields ?? [], true)
                ? BookableResource::where('organization_id', $organization->id)->where('kind', 'vehicule')->whereIn('status', ['for_sale', 'available'])->orderBy('name')->get(['id', 'name', 'sale_price'])
                : [],
            'source' => $card ? 'carte' : (array_key_exists((string) $request->query('src'), Funnel::SOURCES) ? $request->query('src') : 'direct'),
            'card' => $card ? ['slug' => $card->slug, 'name' => $card->name] : null,
            'whatsapp' => $phone ? ActionLists::whatsappUrl($phone, $funnel->message ?: "Bonjour, je suis intéressé(e) par « {$funnel->title} ».") : null,
            'sent' => (bool) session('funnel_sent'),
        ]);
    }

    public function submit(Request $request, Organization $organization, string $slug, RentalDesk $desk): RedirectResponse
    {
        $funnel = $this->funnel($organization, $slug);
        abort_unless($funnel->isOpen(), 410);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'website' => ['nullable', 'max:0'],
            'source' => ['nullable', Rule::in(array_keys(Funnel::SOURCES))],
            'carte' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'vehicles_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'resource_id' => ['nullable', 'integer'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($funnel->seatsLeft() === 0) {
            return back()->withErrors(['name' => 'Toutes les places sont prises : écrivez-nous sur WhatsApp.']);
        }

        ['customer' => $customer, 'request' => $vehicleRequest, 'card' => $card] = app(OnlinePageController::class)->intake(
            $organization, [...$data, 'type' => $funnel->choice, 'store_id' => $funnel->store_id],
            $desk, "Tunnel « {$funnel->title} »", 'tunnel',
        );

        FunnelLead::create([
            'funnel_id' => $funnel->id, 'customer_id' => $customer->id, 'vehicle_request_id' => $vehicleRequest?->id,
            'source' => $card ? 'carte' : ($data['source'] ?? 'direct'),
        ]);

        return redirect("/t/{$organization->slug}/{$funnel->slug}".($card ? "?carte={$card->slug}" : ''))->with('funnel_sent', true);
    }

    public function image(Organization $organization, string $slug)
    {
        $funnel = $this->funnel($organization, $slug);
        abort_unless($funnel->image_path && Storage::exists($funnel->image_path), 404);

        return Storage::response($funnel->image_path);
    }

    private function funnel(Organization $organization, string $slug): Funnel
    {
        abort_unless($organization->hasModule('tunnels'), 404);

        return Funnel::where('organization_id', $organization->id)->where('slug', $slug)->with('organization', 'store')->firstOrFail();
    }
}
