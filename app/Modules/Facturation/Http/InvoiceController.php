<?php

namespace App\Modules\Facturation\Http;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Facturation\Models\InvoicePayment;
use App\Modules\Facturation\Services\Invoicing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Application « Factures » : devis et factures de l'entreprise, toujours
 * rattachés à un client (fiche, historique, relance WhatsApp).
 */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeIds = $this->storeIds($user);
        $type = $request->query('type') === 'quote' ? Invoice::TYPE_QUOTE : Invoice::TYPE_INVOICE;
        $status = (string) $request->query('status', 'all');
        $q = trim((string) $request->query('q', ''));
        $today = today()->toDateString();

        $base = Invoice::where('organization_id', $user->organization_id)->whereIn('store_id', $storeIds);
        if ($type === Invoice::TYPE_QUOTE) {
            $query = (clone $base)->where('type', Invoice::TYPE_QUOTE)->with(['customer:id,name,phone_e164,lifecycle_stage,responsible_id', 'store:id,name']);
            match ($status) {
                'pending' => $query->whereIn('status', ['draft', 'sent'])->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $today)),
                'accepted' => $query->whereIn('status', ['accepted', 'invoiced']),
                'lost' => $query->where(fn ($w) => $w->where('status', 'refused')->orWhere(fn ($e) => $e->where('status', 'sent')->where('valid_until', '<', $today))),
                default => null,
            };
        } else {
            $query = (clone $base)->where('type', '!=', Invoice::TYPE_QUOTE)->with(['customer:id,name,phone_e164,lifecycle_stage,responsible_id', 'store:id,name']);
            match ($status) {
                'unpaid' => $query->whereIn('status', ['open', 'partial']),
                'overdue' => $query->whereIn('status', ['open', 'partial'])->where('due_on', '<', $today),
                'paid' => $query->where('status', 'paid'),
                default => null,
            };
        }

        $query->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('number', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")
            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"))))
            ->latest('issued_on')->latest('id');

        $invoicing = app(Invoicing::class);
        $unpaid = (clone $base)->where('type', Invoice::TYPE_INVOICE)->whereIn('status', ['open', 'partial']);
        $monthStart = today()->startOfMonth();

        return Inertia::render('Invoices/Index', [
            'type' => $type,
            'filters' => ['status' => $status, 'q' => $q],
            'stats' => [
                'quotes_pending' => (clone $base)->where('type', Invoice::TYPE_QUOTE)->whereIn('status', ['draft', 'sent'])->sum('total'),
                'quotes_pending_count' => (clone $base)->where('type', Invoice::TYPE_QUOTE)->whereIn('status', ['draft', 'sent'])->count(),
                'unpaid' => (int) (clone $unpaid)->sum('total') - (int) (clone $unpaid)->sum('paid_amount'),
                'overdue_count' => (clone $unpaid)->where('due_on', '<', $today)->count(),
                'cashed_month' => (int) InvoicePayment::whereHas('invoice', fn ($i) => $i->where('organization_id', $user->organization_id)->whereIn('store_id', $storeIds))
                    ->where('paid_at', '>=', $monthStart)->sum('amount'),
                'conversion' => $this->conversionRate($base),
            ],
            'documents' => $query->paginate(30)->withQueryString()->through(fn (Invoice $i) => $this->row($i, $invoicing)),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $type = $request->query('type') === 'invoice' ? Invoice::TYPE_INVOICE : Invoice::TYPE_QUOTE;
        $customer = $request->integer('customer') ? Customer::where('organization_id', $user->organization_id)->find($request->integer('customer')) : null;

        return Inertia::render('Invoices/Edit', [
            ...$this->formOptions($user),
            'document' => null,
            'type' => $type,
            'quota' => $type === Invoice::TYPE_QUOTE ? [...Invoicing::quoteQuota($user->organization), 'message' => Invoicing::QUOTA_MESSAGE] : null,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone_e164' => $customer->phone_e164] : null,
        ]);
    }

    public function store(Request $request, Invoicing $invoicing): RedirectResponse
    {
        $user = $request->user();
        $data = $this->validated($request, $user, creating: true);
        // Offre Gratuit : 3 devis par mois calendaire (config nentix).
        if ($data['type'] === Invoice::TYPE_QUOTE && Invoicing::quoteQuota($user->organization)['blocked']) {
            return back()->with('error', Invoicing::QUOTA_MESSAGE);
        }
        $invoice = $invoicing->create($user, $data);

        return redirect()->route('invoices.show', $invoice)->with('success', "{$invoice->typeLabel()} {$invoice->number} créé(e). Envoyez-le au client par WhatsApp.");
    }

    public function show(Request $request, Invoice $invoice, Invoicing $invoicing): Response
    {
        $this->authorizeDocument($request->user(), $invoice);
        $invoice->load(['lines', 'payments', 'customer', 'store:id,name', 'quote:id,number', 'invoices']);
        $customer = $invoice->customer;

        return Inertia::render('Invoices/Show', [
            'document' => [
                ...$this->row($invoice, $invoicing),
                'notes' => $invoice->notes,
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'valid_until' => $invoice->valid_until?->toDateString(),
                'accepted_at' => $invoice->accepted_at?->toIso8601String(),
                'accepted_by_name' => $invoice->accepted_by_name,
                'viewed_at' => $invoice->viewed_at?->toIso8601String(),
                'sent_at' => $invoice->sent_at?->toIso8601String(),
                'public_url' => $invoice->publicUrl(),
                'share_url' => $invoicing->whatsappUrl($invoice),
                'quote' => $invoice->quote ? ['id' => $invoice->quote->id, 'number' => $invoice->quote->number] : null,
                'children' => $invoice->invoices->map(fn (Invoice $c) => ['id' => $c->id, 'number' => $c->number, 'type_label' => $c->typeLabel(), 'total' => $c->total, 'status_label' => $c->statusLabel()])->values(),
                'has_deposit' => $invoice->invoices->where('kind', Invoice::KIND_DEPOSIT)->where('status', '!=', 'cancelled')->isNotEmpty(),
                'lines' => $invoice->lines->map(fn ($l) => ['label' => $l->label, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount])->values(),
                'payments' => $invoice->payments->map(fn ($p) => ['id' => $p->id, 'amount' => $p->amount, 'method' => Purchase::PAYMENT_METHODS[$p->method] ?? $p->method, 'paid_at' => $p->paid_at->toIso8601String()])->values(),
            ],
            'customerCard' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone_e164' => $customer->phone_e164,
                'stage' => Customer::STAGES[$customer->lifecycle_stage] ?? null,
                'total_spent' => (int) $customer->total_spent,
                'purchases_count' => (int) $customer->purchases_count,
                'last_purchase_at' => $customer->last_purchase_at ? Carbon::parse($customer->last_purchase_at)->toDateString() : null,
                'open_documents' => Invoice::where('customer_id', $customer->id)->where('id', '!=', $invoice->id)
                    ->where(fn ($w) => $w->whereIn('status', ['open', 'partial'])->orWhere(fn ($q) => $q->where('type', Invoice::TYPE_QUOTE)->whereIn('status', ['draft', 'sent'])))
                    ->latest('id')->limit(5)->get()->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'type_label' => $i->typeLabel(), 'total' => $i->total, 'status_label' => $i->statusLabel()])->values(),
            ],
            'paymentMethods' => Purchase::PAYMENT_METHODS,
            'automation' => $request->user()->organization->automationAllowed(),
        ]);
    }

    public function edit(Request $request, Invoice $invoice): Response
    {
        $this->authorizeDocument($request->user(), $invoice);
        abort_unless($this->editable($invoice), 403, 'Ce document ne peut plus être modifié.');
        $invoice->load(['lines', 'customer']);

        return Inertia::render('Invoices/Edit', [
            ...$this->formOptions($request->user()),
            'type' => $invoice->type,
            'customer' => ['id' => $invoice->customer->id, 'name' => $invoice->customer->name, 'phone_e164' => $invoice->customer->phone_e164],
            'document' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'store_id' => $invoice->store_id,
                'title' => $invoice->title,
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                'valid_until' => $invoice->valid_until?->toDateString(),
                'discount_amount' => $invoice->discount_amount,
                'notes' => $invoice->notes,
                'lines' => $invoice->lines->map(fn ($l) => ['label' => $l->label, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'catalog_item_id' => $l->catalog_item_id])->values(),
            ],
        ]);
    }

    public function update(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeDocument($user, $invoice);
        abort_unless($this->editable($invoice), 403, 'Ce document ne peut plus être modifié.');
        $data = $this->validated($request, $user, creating: false);

        $invoice->update([
            'store_id' => $data['store_id'],
            'title' => $data['title'] ?? null,
            'issued_on' => $data['issued_on'] ?? $invoice->issued_on,
            'due_on' => $invoice->isQuote() ? null : ($data['due_on'] ?? $invoice->due_on),
            'valid_until' => $invoice->isQuote() ? ($data['valid_until'] ?? $invoice->valid_until) : null,
            'notes' => $data['notes'] ?? null,
        ]);
        $invoicing->setLines($invoice, $data['lines'], (int) ($data['discount_amount'] ?? 0));

        return redirect()->route('invoices.show', $invoice)->with('success', "{$invoice->typeLabel()} {$invoice->number} mis(e) à jour.");
    }

    public function sent(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $this->authorizeDocument($request->user(), $invoice);
        $invoicing->markSent($invoice, $request->user());

        return back();
    }

    public function reminded(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $this->authorizeDocument($request->user(), $invoice);
        $invoicing->markReminded($invoice);

        return back();
    }

    public function accept(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $this->authorizeDocument($request->user(), $invoice);
        $invoicing->accept($invoice);

        return back()->with('success', "Devis {$invoice->number} accepté. Vous pouvez le facturer, avec ou sans acompte.");
    }

    public function refuse(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $this->authorizeDocument($request->user(), $invoice);
        $invoicing->refuse($invoice);

        return back()->with('success', "Devis {$invoice->number} marqué refusé.");
    }

    public function convert(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $this->authorizeDocument($request->user(), $invoice);
        $data = $request->validate(['deposit_percent' => ['nullable', 'integer', 'min:1', 'max:99']]);
        $created = $invoicing->invoiceQuote($invoice, $request->user(), $data['deposit_percent'] ?? null);

        return redirect()->route('invoices.show', $created)->with('success', "{$created->typeLabel()} {$created->number} créée : envoyez-la au client.");
    }

    public function pay(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $this->authorizeDocument($request->user(), $invoice);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(array_keys(Purchase::PAYMENT_METHODS))],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $paidAt = isset($data['paid_on']) && $data['paid_on'] !== today()->toDateString() ? Carbon::parse($data['paid_on'])->setTime(12, 0) : now();
        $invoicing->pay($invoice, $request->user(), (int) $data['amount'], $data['method'] ?? null, $paidAt);

        return back()->with('success', 'Paiement de '.Invoicing::money((int) $data['amount']).' enregistré : il apparaît sur la fiche client et dans la caisse du jour.');
    }

    public function cancel(Request $request, Invoice $invoice, Invoicing $invoicing): RedirectResponse
    {
        $this->authorizeDocument($request->user(), $invoice);
        $invoicing->cancel($invoice, $request->user());

        return back()->with('success', "{$invoice->typeLabel()} {$invoice->number} annulé(e).");
    }

    public function print(Request $request, Invoice $invoice): View
    {
        $this->authorizeDocument($request->user(), $invoice);
        $invoice->load(['lines', 'customer', 'store', 'organization', 'payments']);

        return view('facturation::document', ['invoice' => $invoice, 'public' => false]);
    }

    /** @return array<string, mixed> */
    private function row(Invoice $i, Invoicing $invoicing): array
    {
        $status = $i->displayStatus();
        $needsReminder = ($i->isQuote() && in_array($status, ['sent'], true) && $i->sent_at && $i->sent_at->lt(now()->subDays(3)))
            || (! $i->isQuote() && in_array($status, ['open', 'partial', 'overdue'], true));

        return [
            'id' => $i->id,
            'type' => $i->type,
            'kind' => $i->kind,
            'type_label' => $i->typeLabel(),
            'number' => $i->number,
            'title' => $i->title,
            'status' => $status,
            'status_label' => $i->statusLabel(),
            'issued_on' => $i->issued_on->toDateString(),
            'due_on' => $i->due_on?->toDateString(),
            'total' => $i->total,
            'paid_amount' => $i->paid_amount,
            'balance' => $i->isQuote() ? 0 : $i->balance(),
            'customer' => ['id' => $i->customer_id, 'name' => $i->customer?->name, 'stage' => $i->customer?->lifecycle_stage],
            'store_name' => $i->store?->name,
            'editable' => $this->editable($i),
            'reminder_url' => $needsReminder ? $invoicing->whatsappUrl($i, reminder: true) : null,
            'last_reminder_at' => $i->last_reminder_at?->toIso8601String(),
        ];
    }

    private function editable(Invoice $i): bool
    {
        return $i->isQuote() ? in_array($i->status, ['draft', 'sent'], true) : $i->paid_amount === 0 && in_array($i->status, ['draft', 'open'], true) && $i->sent_at === null;
    }

    private function conversionRate(Builder $base): ?int
    {
        $answered = (clone $base)->where('type', Invoice::TYPE_QUOTE)->whereIn('status', ['accepted', 'invoiced', 'refused'])->where('issued_on', '>=', today()->subDays(90))->count();
        $won = (clone $base)->where('type', Invoice::TYPE_QUOTE)->whereIn('status', ['accepted', 'invoiced'])->where('issued_on', '>=', today()->subDays(90))->count();

        return $answered ? (int) round($won * 100 / $answered) : null;
    }

    /** @return array<string, mixed> */
    private function formOptions(User $user): array
    {
        return [
            'stores' => Store::whereIn('id', $this->storeIds($user))->orderBy('name')->get(['id', 'name']),
            'catalog' => CatalogItem::where('organization_id', $user->organization_id)->where('active', true)->orderBy('name')->get(['id', 'name', 'price', 'type']),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $user, bool $creating): array
    {
        return $request->validate([
            ...($creating ? [
                'type' => ['required', Rule::in([Invoice::TYPE_QUOTE, Invoice::TYPE_INVOICE])],
                'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('organization_id', $user->organization_id)],
            ] : []),
            'store_id' => ['required', 'integer', Rule::in($this->storeIds($user))],
            'title' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'discount_amount' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.label' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('organization_id', $user->organization_id)],
        ]);
    }

    private function authorizeDocument(User $user, Invoice $invoice): void
    {
        abort_unless($invoice->organization_id === $user->organization_id && in_array($invoice->store_id, $this->storeIds($user), true), 404);
    }

    /** @return array<int, int> */
    private function storeIds(User $user): array
    {
        $visible = $user->visibleStoreIds();

        return Store::where('organization_id', $user->organization_id)
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('id', $visible))
            ->pluck('id')->all();
    }
}
