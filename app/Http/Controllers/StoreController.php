<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Store;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
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

        $canManage = $user->canManageOrganization();

        return Inertia::render('Stores/Index', [
            'stores' => $query->withCount('contacts')->orderBy('name')->get()->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'brand_id' => $store->brand_id,
                'brand_name' => $store->brand?->name,
                'contacts_count' => $store->contacts_count,
                'whatsapp_phone_number_id' => $store->whatsapp_phone_number_id,
                'has_token' => filled($store->whatsapp_access_token),
                // own : identifiants propres à la boutique ; shared : repli sur le
                // compte Meta partagé (.env) ; none : aucun envoi possible.
                'connection' => ! $store->canSendWhatsApp() ? 'none' : ($store->usesSharedWhatsAppAccount() ? 'shared' : 'own'),
            ]),
            // Marques proposées dans la fenêtre de modification / création
            // (seuls les rôles qui gèrent l'organisation peuvent changer la marque).
            'brands' => $canManage
                ? Brand::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name'])
                : [],
            'canManage' => $canManage,
            // Compte Meta partagé (.env) : organisations historiques uniquement.
            'sharedAccountConfigured' => (bool) $user->organization?->uses_shared_whatsapp_account
                && filled(config('services.meta_whatsapp.token')) && filled(config('services.meta_whatsapp.phone_number_id')),
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

    /**
     * Nom et marque d'une boutique (réservé aux rôles qui gèrent
     * l'organisation, cf. middleware sur la route). La connexion WhatsApp se
     * règle dans Paramètres → WhatsApp (WhatsAppSettingsController).
     */
    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $store);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'brand_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('brands', 'id')->where('organization_id', $store->organization_id),
            ],
        ]);

        $store->update($data);

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

    /**
     * Page « QR code » d'une boutique : lien WhatsApp avec le message
     * « OUI <boutique> » pré-rempli et affiche A5 à imprimer pour recueillir
     * l'accord des clients en caisse. Le message nomme la boutique : avec le
     * compte partagé, toutes les boutiques ont le même numéro.
     */
    public function qr(Request $request, Store $store): Response
    {
        $this->authorizeVisible($request, $store);

        $store->loadMissing('brand');

        return Inertia::render('Stores/Qr', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'brand_name' => $store->brand?->name,
                'shared_account' => $store->usesSharedWhatsAppAccount(),
            ],
            'whatsappNumber' => $this->whatsappNumber($store),
            'message' => "OUI {$store->name}",
            'simulated' => WhatsAppClient::simulated(),
        ]);
    }

    /**
     * Numéro WhatsApp affiché de la boutique (chiffres seuls, ex. 24177000000),
     * lu chez Meta et mis en cache un jour. Null en simulation, sans
     * identifiants ou si Meta ne répond pas : la page le fait alors saisir.
     */
    private function whatsappNumber(Store $store): ?string
    {
        if (WhatsAppClient::simulated() || ! $store->canSendWhatsApp()) {
            return null;
        }

        $phoneNumberId = $store->whatsapp_phone_number_id ?: config('services.meta_whatsapp.phone_number_id');
        $key = "whatsapp-display-number:{$phoneNumberId}";

        if ($number = Cache::get($key)) {
            return $number;
        }

        try {
            $display = $store->whatsappClient()->verifyConnection()['display_phone_number'] ?? '';
        } catch (WhatsAppApiException|ConnectionException) {
            return null;
        }

        $number = preg_replace('/\D/', '', (string) $display);

        if (strlen($number) < 8) {
            return null;
        }

        Cache::put($key, $number, now()->addDay());

        return $number;
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
