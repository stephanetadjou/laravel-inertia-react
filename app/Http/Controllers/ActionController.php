<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerFollowUp;
use App\Models\Group;
use App\Models\Store;
use App\Services\ActionLists;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $groups = Group::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']);
        $groupId = $groups->contains('id', $request->integer('group')) ? $request->integer('group') : null;
        $lists = new ActionLists($user, $storeId, $brandId, $groupId);
        $multibrand = $scopeStores->pluck('brand_id')->unique()->count() >= 2;
        // Commerce sans agenda : pas de listes de rendez-vous ; vente croisée : au moins deux marques.
        $available = collect(ActionLists::LISTS)
            ->reject(fn ($list) => ! empty($list['multibrand']) && ! $multibrand)
            ->reject(fn ($list, $key) => in_array($key, ['appointment_reminder', 'no_show'], true) && ! $user->organization->hasAgenda())
            // Listes d'un module (échéances, cours…) : seulement si l'un de ses modules est actif.
            ->reject(fn ($list) => isset($list['modules']) && ! collect($list['modules'])->contains(fn ($m) => $user->organization->hasModule($m)));
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
            'group' => $groupId,
            'groups' => $groups,
            'brands' => $multibrand ? $scopeStores->pluck('brand')->filter()->unique('id')->sortBy('name')->values()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name]) : [],
            'messages' => $user->organization->actionMessages(),
            'defaultMessages' => ActionLists::DEFAULT_MESSAGES,
            'canEditMessages' => $user->canManageOrganization(),
            'apiEnabled' => $user->organization->usesWhatsAppApi(),
        ]);
    }

    /**
     * Export d'une liste d'actions (clients, motif, message et lien wa.me),
     * pour relancer hors de Menentia : droit d'export, journalisé.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        if (! $user->canExport()) {
            throw new HttpException(403, 'Votre rôle ne permet pas d’exporter des données.');
        }

        [$key, $rows] = $this->listRows($request);
        Audit::export('actions du jour · '.ActionLists::LISTS[$key]['label'], count($rows), $request->only(['list', 'brand', 'store', 'group']));

        $columns = ['Nom', 'Téléphone', 'Point de vente', 'Motif', 'Consentement WhatsApp', 'Message', 'Lien WhatsApp'];
        $data = array_map(fn ($r) => [$r['name'], '+'.$r['phone_e164'], $r['store_name'], $r['reason'], match ($r['consent']) {
            'opt_in' => 'Oui', 'opt_out' => 'Refus', default => 'Inconnu',
        }, $r['message'], $r['whatsapp_url']], $rows);
        $name = 'actions-'.$key.'-'.now()->format('Y-m-d');

        if ($request->query('format') === 'xlsx') {
            return response()->streamDownload(function () use ($columns, $data) {
                $sheet = new Spreadsheet;
                $sheet->getActiveSheet()->setTitle('Relances')->fromArray([$columns, ...$data], null, 'A1', true);
                (new Xlsx($sheet))->save('php://output');
            }, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($columns, $data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';', '"', '');
            foreach ($data as $row) {
                fputcsv($out, $row, ';', '"', '');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Range les clients d'une liste d'actions dans un groupe (campagne, relance suivie). */
    public function group(Request $request): RedirectResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255']]);
        [, $rows] = $this->listRows($request);
        $user = $request->user();
        $storeIds = (new ActionLists($user))->storeIds();
        $contacts = Contact::whereIn('customer_id', array_column($rows, 'customer_id'))->whereIn('store_id', $storeIds)->pluck('id');

        if ($contacts->isEmpty()) {
            return back()->with('error', 'Cette liste est vide.');
        }

        $group = Group::firstOrCreate(['organization_id' => $user->organization_id, 'name' => trim((string) $request->input('name'))]);
        $group->contacts()->syncWithoutDetaching($contacts->all());

        return back()->with('success', count($rows)." client(s) ajouté(s) au groupe « {$group->name} ».");
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} */
    private function listRows(Request $request): array
    {
        $user = $request->user();
        $key = array_key_exists((string) $request->input('list'), ActionLists::LISTS) ? (string) $request->input('list') : 'at_risk';
        $visible = $user->visibleStoreIds();
        $brandIds = Store::where('organization_id', $user->organization_id)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->pluck('brand_id');
        $brandId = $brandIds->contains($request->integer('brand')) ? $request->integer('brand') : null;
        $groupId = Group::where('organization_id', $user->organization_id)->whereKey($request->integer('group'))->value('id');

        return [$key, (new ActionLists($user, $request->integer('store') ?: null, $brandId, $groupId))->rows($key)];
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
            'reference' => ['nullable', 'string', 'max:64'],
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
            'reference' => $data['reference'] ?? null,
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
