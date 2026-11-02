<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\PaymentSchedule;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\ActionLists;
use App\Services\Installments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ventes › Échéances (modules « tranches » et « abonnements ») : tranches à
 * payer, en retard ou payées ; nouvel échéancier (« Payer en plusieurs
 * fois ») ; encaissement d'une tranche (crée une vente) et reçu.
 */
class InstallmentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = $this->storeIds($user);
        $status = in_array($request->query('status'), ['due', 'overdue', 'paid', 'all'], true) ? $request->query('status') : 'open';
        $q = trim((string) $request->query('q', ''));
        $today = today()->toDateString();

        $base = Installment::query()
            ->whereHas('schedule', fn ($s) => $s->whereIn('store_id', $storeIds)->where('status', PaymentSchedule::STATUS_ACTIVE));

        $query = (clone $base)
            ->with(['schedule.customer:id,name,phone_e164', 'schedule.beneficiary:id,name', 'schedule.store:id,name'])
            ->when($status === 'open', fn ($w) => $w->whereNull('paid_at'))
            ->when($status === 'due', fn ($w) => $w->whereNull('paid_at')->where('due_on', '>=', $today))
            ->when($status === 'overdue', fn ($w) => $w->whereNull('paid_at')->where('due_on', '<', $today))
            ->when($status === 'paid', fn ($w) => $w->whereNotNull('paid_at'))
            ->when($request->integer('store'), fn ($w, $store) => $w->whereHas('schedule', fn ($s) => $s->where('store_id', $store)))
            ->when($q !== '', fn ($w) => $w->whereHas('schedule', fn ($s) => $s->where('label', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                ->orWhereHas('beneficiary', fn ($c) => $c->where('name', 'like', "%{$q}%"))))
            ->orderBy($status === 'paid' ? 'paid_at' : 'due_on', $status === 'paid' ? 'desc' : 'asc');

        $monthStart = today()->startOfMonth()->toDateString();
        $nextMonth = today()->startOfMonth()->addMonth()->toDateString();

        return Inertia::render('Installments/Index', [
            'filters' => ['status' => $status, 'q' => $q, 'store' => $request->integer('store') ?: null],
            'stats' => [
                'overdue_count' => (clone $base)->whereNull('paid_at')->where('due_on', '<', $today)->count(),
                'overdue_amount' => (int) (clone $base)->whereNull('paid_at')->where('due_on', '<', $today)->sum('amount'),
                'due_month' => (int) (clone $base)->whereNull('paid_at')->where('due_on', '>=', $today)->where('due_on', '<', $nextMonth)->sum('amount'),
                'paid_month' => (int) (clone $base)->whereNotNull('paid_at')->where('paid_at', '>=', $monthStart)->sum('amount'),
            ],
            'installments' => $query->paginate(50)->withQueryString()->through(fn (Installment $i) => self::present($i)),
            'stores' => Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']),
            'catalog' => CatalogItem::where('organization_id', $user->organization_id)->where('active', true)->orderBy('name')->get(['id', 'name', 'price', 'type']),
            'paymentMethods' => Purchase::PAYMENT_METHODS,
            'dueDay' => (int) $user->organization->moduleSettings('tranches')['due_day'],
        ]);
    }

    /** « Payer en plusieurs fois » : un échéancier de N tranches mensuelles. */
    public function store(Request $request, Installments $installments): RedirectResponse
    {
        $user = $request->user();
        $storeIds = $this->storeIds($user);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('organization_id', $user->organization_id)],
            'store_id' => ['required', 'integer', Rule::in($storeIds)],
            'label' => ['required', 'string', 'max:255'],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('organization_id', $user->organization_id)],
            'total' => ['required', 'integer', 'min:1'],
            'count' => ['required', 'integer', 'min:1', 'max:36'],
            'first_due_on' => ['required', 'date'],
            'pay_first_now' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
        ]);

        // Personne suivie choisie : le responsable paie, la personne est concernée.
        $customer = Customer::findOrFail($data['customer_id']);
        $payer = $customer->recipient();

        $schedule = $installments->createSchedule($user, [
            'customer_id' => $payer->id,
            'beneficiary_id' => $payer->isNot($customer) ? $customer->id : null,
            'store_id' => $data['store_id'],
            'label' => $data['label'],
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'installments' => Installments::split((int) $data['total'], (int) $data['count'], Carbon::parse($data['first_due_on'])),
        ]);

        if (! empty($data['pay_first_now'])) {
            $installments->pay($schedule->installments()->first(), $user, $data['payment_method'] ?? null);
        }

        return back()->with('success', "Échéancier « {$schedule->label} » créé : {$data['count']} tranche(s).");
    }

    /** Encaisse une tranche : une vente est créée, la tranche passe « Payée ». */
    public function pay(Request $request, Installment $installment, Installments $installments): RedirectResponse
    {
        $this->authorizeInstallment($request->user(), $installment);
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $paidAt = isset($data['paid_on']) && $data['paid_on'] !== today()->toDateString() ? Carbon::parse($data['paid_on'])->setTime(12, 0) : now();
        $installments->pay($installment, $request->user(), $data['payment_method'] ?? null, $paidAt);

        return back()->with('success', 'Tranche encaissée : '.number_format((int) $installment->amount, 0, ',', ' ').' FCFA. Le reçu est prêt.');
    }

    public function cancel(Request $request, PaymentSchedule $schedule, Installments $installments): RedirectResponse
    {
        abort_unless($schedule->organization_id === $request->user()->organization_id && in_array($schedule->store_id, $this->storeIds($request->user()), true), 404);
        $installments->cancel($schedule, $request->user());

        return back()->with('success', "Échéancier « {$schedule->label} » annulé.");
    }

    /** Reçu imprimable d'une tranche payée. */
    public function receipt(Request $request, Installment $installment): View
    {
        $this->authorizeInstallment($request->user(), $installment);
        abort_if($installment->paid_at === null, 404);

        $schedule = $installment->schedule()->with(['customer', 'beneficiary', 'store', 'installments'])->firstOrFail();
        $organization = $request->user()->organization;

        return view('receipts.installment', [
            'organization' => $organization,
            'installment' => $installment,
            'schedule' => $schedule,
            'paidTotal' => (int) $schedule->installments->whereNotNull('paid_at')->sum('amount'),
            'remaining' => (int) $schedule->installments->whereNull('paid_at')->sum('amount'),
            'method' => Purchase::PAYMENT_METHODS[$installment->payment_method] ?? null,
            'currency' => $organization->currencySymbol(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(Installment $i): array
    {
        $schedule = $i->schedule;
        $status = $i->status();
        $phone = $schedule->customer?->phone_e164;

        return [
            'id' => $i->id,
            'schedule_id' => $schedule->id,
            'number' => $i->number,
            'label' => $i->label ?? $schedule->label,
            'schedule_label' => $schedule->label,
            'amount' => (int) $i->amount,
            'due_on' => $i->due_on->toDateString(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'status' => $status,
            'status_label' => Installment::STATUSES[$status],
            'customer' => ['id' => $schedule->customer_id, 'name' => $schedule->customer?->name],
            'beneficiary' => $schedule->beneficiary ? ['id' => $schedule->beneficiary->id, 'name' => $schedule->beneficiary->name] : null,
            'store_name' => $schedule->store?->name,
            'whatsapp_url' => $phone && $status !== Installment::STATUS_PAID ? ActionLists::whatsappUrl($phone, self::reminderText($i)) : null,
        ];
    }

    /** Message de rappel d'une tranche (wa.me). */
    public static function reminderText(Installment $i): string
    {
        $schedule = $i->schedule;
        $first = ActionLists::firstNameOf((string) $schedule->customer?->name);
        $for = $schedule->beneficiary ? ' pour '.ActionLists::firstNameOf($schedule->beneficiary->name) : '';
        $date = $i->due_on->copy()->locale('fr')->translatedFormat('j F');
        $amount = number_format((int) $i->amount, 0, ',', ' ');

        return $i->due_on->lt(today())
            ? "Bonjour {$first}, sauf erreur de notre part, l'échéance de {$amount} FCFA du {$date} ({$schedule->label}{$for}) n'est pas encore réglée. Merci de passer la régler, ou de nous dire si vous souhaitez en parler."
            : "Bonjour {$first}, petit rappel : l'échéance de {$amount} FCFA ({$schedule->label}{$for}) est à régler le {$date}. Merci !";
    }

    /** @return array<int, int> */
    private function storeIds(User $user): array
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('id', $visible))
            ->pluck('id')->all();
    }

    private function authorizeInstallment(User $user, Installment $installment): void
    {
        if ($installment->organization_id !== $user->organization_id || ! in_array($installment->schedule->store_id, $this->storeIds($user), true)) {
            throw new HttpException(404);
        }
    }
}
