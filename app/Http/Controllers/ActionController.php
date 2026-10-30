<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Store;
use App\Services\ActionLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Actions du jour : listes de clients à relancer à la main, chacun avec un
 * lien wa.me qui ouvre WhatsApp et le message prérempli (envoi depuis le
 * téléphone de l'établissement, sans l'API WhatsApp).
 */
class ActionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeId = $request->integer('store') ?: null;
        $visible = $user->visibleStoreIds();
        $scopeStores = Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->with('brand:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'brand_id']);
        $brandId = $scopeStores->contains('brand_id', $request->integer('brand')) ? $request->integer('brand') : null;
        $lists = new ActionLists($user, $storeId, $brandId);
        $multibrand = $scopeStores->pluck('brand_id')->unique()->count() >= 2;
        // Commerce sans agenda : pas de listes de rendez-vous ; vente croisée : au moins deux marques.
        $available = collect(ActionLists::LISTS)
            ->reject(fn ($list) => ! empty($list['multibrand']) && ! $multibrand)
            ->reject(fn ($list, $key) => in_array($key, ['appointment_reminder', 'no_show'], true) && ! $user->organization->hasAgenda());
        $counts = collect($available->keys())->mapWithKeys(fn ($key) => [$key => count($lists->rows($key))])->all();

        $active = $available->has((string) $request->query('list'))
            ? (string) $request->query('list')
            : (collect($counts)->filter()->keys()->first() ?? 'at_risk');

        return Inertia::render('Actions/Index', [
            'lists' => $available->map(fn ($list, $key) => [
                'key' => $key,
                'label' => $list['label'],
                'description' => $list['description'],
                'count' => $counts[$key],
            ])->values(),
            'active' => $active,
            'rows' => $lists->rows($active),
            'limit' => ActionLists::LIMIT,
            'store' => $storeId,
            'stores' => $scopeStores->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'brand' => $brandId,
            'brands' => $multibrand ? $scopeStores->pluck('brand')->filter()->unique('id')->sortBy('name')->values()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]) : [],
            'messages' => $user->organization->actionMessages(),
            'defaultMessages' => ActionLists::DEFAULT_MESSAGES,
            'canEditMessages' => $user->canManageOrganization(),
            'apiEnabled' => $user->organization->usesWhatsAppApi(),
        ]);
    }

    /**
     * Relance faite (WhatsApp ouvert depuis la liste, ou autre moyen) : le
     * client quitte la liste pendant le délai de la liste.
     */
    public function done(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(ActionLists::LISTS))],
            'customer_id' => ['required', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
            'store_id' => ['nullable', 'integer'],
            'channel' => ['nullable', Rule::in(['whatsapp_manual', 'call', 'other'])],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $storeIds = (new ActionLists($user))->storeIds();
        $customer = Customer::where('organization_id', $user->organization_id)
            ->whereHas('contacts', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->find($data['customer_id'])
            ?? throw new HttpException(403, "Ce client n'est pas dans votre périmètre.");

        $appointmentId = isset($data['appointment_id'])
            ? $customer->appointments()->whereIn('store_id', $storeIds)->whereKey($data['appointment_id'])->value('id')
            : null;

        CustomerFollowUp::create([
            'customer_id' => $customer->id,
            'store_id' => in_array($data['store_id'] ?? null, $storeIds, true) ? $data['store_id'] : null,
            'user_id' => $user->id,
            'appointment_id' => $appointmentId,
            'action' => $data['action'],
            'channel' => $data['channel'] ?? 'whatsapp_manual',
            'message' => $data['message'] ?? null,
            'done_at' => now(),
        ]);

        return back()->with('success', "Relance de {$customer->name} enregistrée.");
    }

    public function updateMessages(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array'],
            'messages.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $organization = $request->user()->organization;
        $organization->update([
            'action_messages' => array_intersect_key($data['messages'], ActionLists::DEFAULT_MESSAGES),
        ]);

        return back()->with('success', 'Messages préremplis enregistrés.');
    }
}
