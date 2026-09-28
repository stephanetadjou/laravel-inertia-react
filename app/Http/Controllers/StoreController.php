<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Store;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Store::where('organization_id', $user->organization_id)->with('brand');

        $visibleIds = $user->visibleStoreIds();

        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        return Inertia::render('Stores/Index', [
            'stores' => $query->orderBy('name')->get()->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'brand_name' => $store->brand->name,
                'whatsapp_phone_number_id' => $store->whatsapp_phone_number_id,
                'has_token' => $store->whatsapp_access_token !== null,
            ]),
        ]);
    }

    /**
     * Nouvelle boutique sous une marque (réservé super_admin/admin, cf.
     * middleware can-manage-organization sur la route).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $brand = Brand::where('organization_id', $request->user()->organization_id)->find($data['brand_id']);

        if (! $brand) {
            return back()->with('error', 'Marque invalide.');
        }

        Store::create([
            'organization_id' => $brand->organization_id,
            'brand_id' => $brand->id,
            'name' => $data['name'],
        ]);

        return back()->with('success', "Boutique « {$data['name']} » créée sous « {$brand->name} ».");
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeVisible($request, $store);

        $data = $request->validate([
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['nullable', 'string'],
        ]);

        $store->whatsapp_phone_number_id = $data['whatsapp_phone_number_id'] ?? null;

        // On ne touche au token que si un nouveau a été saisi : le champ arrive
        // toujours vide depuis le frontend (le token existant n'est jamais renvoyé
        // au navigateur), donc une valeur vide ne doit pas effacer le token en place.
        if (! empty($data['whatsapp_access_token'])) {
            $store->whatsapp_access_token = $data['whatsapp_access_token'];
        }

        $store->save();

        return back()->with('success', "Boutique {$store->name} mise à jour.");
    }

    public function destroy(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $store);

        if ($store->contacts()->exists()) {
            return back()->with('error', "Impossible de supprimer « {$store->name} » : des contacts y sont rattachés.");
        }

        $name = $store->name;
        $store->delete();

        return back()->with('success', "Boutique « {$name} » supprimée.");
    }

    public function testConnection(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeVisible($request, $store);

        $client = new WhatsAppClient($store->whatsapp_access_token, $store->whatsapp_phone_number_id);

        try {
            $info = $client->verifyConnection();
        } catch (WhatsAppApiException|ConnectionException $e) {
            return back()->with('error', "{$store->name} : {$e->getMessage()}");
        }

        $verifiedName = $info['verified_name'] ?? 'nom non vérifié';
        $phoneNumber = $info['display_phone_number'] ?? '?';

        return back()->with('success', "{$store->name} connectée : {$verifiedName} ({$phoneNumber}).");
    }

    private function authorizeSameOrganization(Request $request, Store $store): void
    {
        if ($store->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cette boutique n\'appartient pas à votre organisation.');
        }
    }

    /**
     * Comme authorizeSameOrganization(), en ajoutant la restriction de
     * périmètre (marque/boutique) pour les managers.
     */
    private function authorizeVisible(Request $request, Store $store): void
    {
        $this->authorizeSameOrganization($request, $store);

        $user = $request->user();
        $visibleIds = $user->visibleStoreIds();

        if ($visibleIds !== null && ! in_array($store->id, $visibleIds, true)) {
            throw new HttpException(403, 'Cette boutique n\'est pas dans votre périmètre.');
        }
    }
}
