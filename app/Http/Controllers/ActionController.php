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
        $lists = new ActionLists($user, $storeId);
        $counts = $lists->counts();

        $active = array_key_exists((string) $request->query('list'), ActionLists::LISTS)
            ? (string) $request->query('list')
            : (collect($counts)->filter()->keys()->first() ?? 'at_risk');

        $visible = $user->visibleStoreIds();

        return Inertia::render('Actions/Index', [
            'lists' => collect(ActionLists::LISTS)->map(fn ($list, $key) => [
                'key' => $key,
                'label' => $list['label'],
                'description' => $list['description'],
                'count' => $counts[$key],
            ])->values(),
            'active' => $active,
            'rows' => $lists->rows($active),
            'limit' => ActionLists::LIMIT,
            'store' => $storeId,
            'stores' => Store::where('organization_id', $user->organization_id)
                ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
                ->orderBy('name')
                ->get(['id', 'name']),
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
