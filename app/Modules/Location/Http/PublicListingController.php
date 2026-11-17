<?php

namespace App\Modules\Location\Http;

use App\Http\Controllers\Controller;
use App\Models\BookableResource;
use App\Services\ActionLists;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;

/**
 * Annonce publique d'un véhicule à vendre (sans compte) : photos, prix,
 * kilométrage, description et bouton « Écrire sur WhatsApp » vers l'agence.
 * Le lien porte un jeton aléatoire ; un véhicule vendu affiche « Vendu ».
 */
class PublicListingController extends Controller
{
    public function show(string $token): View
    {
        $vehicle = $this->vehicle($token);
        $photos = json_decode((string) $vehicle->getRawOriginal('photos'), true) ?: [];
        $phone = $vehicle->store?->bookingPhone();

        return view('location::listing', [
            'vehicle' => $vehicle,
            'organization' => $vehicle->store?->organization,
            'photos' => array_map(fn ($i) => url("/annonce/{$token}/photos/{$i}"), array_keys($photos)),
            'description' => $vehicle->getRawOriginal('sale_description'),
            'whatsapp' => $phone && $vehicle->status === 'for_sale'
                ? ActionLists::whatsappUrl($phone, "Bonjour, le véhicule {$vehicle->label()} en vente est-il toujours disponible ? ".url("/annonce/{$token}"))
                : null,
        ]);
    }

    public function photo(string $token, int $index)
    {
        $vehicle = $this->vehicle($token);
        $path = (json_decode((string) $vehicle->getRawOriginal('photos'), true) ?: [])[$index] ?? abort(404);
        abort_unless(Storage::exists($path), 404);

        return Storage::response($path);
    }

    private function vehicle(string $token): BookableResource
    {
        return BookableResource::where('sale_token', $token)->whereIn('status', ['for_sale', 'sold'])->with('store.organization')->firstOrFail();
    }
}
