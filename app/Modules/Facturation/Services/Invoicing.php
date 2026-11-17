<?php

namespace App\Modules\Facturation\Services;

use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Facturation\Models\InvoicePayment;
use App\Services\ActionLists;
use App\Services\Audit;
use App\Services\CustomerScoring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Devis et factures : création, envoi par lien WhatsApp, acceptation en
 * ligne, facture d'acompte et de solde, paiements. Un paiement crée une
 * vente du client (fiche, RFM, fidélité, caisse du jour), comme une tranche.
 */
class Invoicing
{
    /**
     * @param  array{customer_id: int, store_id: int, type: string, title?: ?string, issued_on?: ?string, due_on?: ?string, valid_until?: ?string, discount_amount?: ?int, notes?: ?string, lines: list<array{label: string, quantity: float|int|string, unit_price: int|string, catalog_item_id?: ?int}>}  $data
     */
    public function create(User $user, array $data): Invoice
    {
        return DB::transaction(function () use ($user, $data) {
            $type = $data['type'];
            $issued = Carbon::parse($data['issued_on'] ?? today());
            $invoice = Invoice::create([
                'organization_id' => $user->organization_id,
                'store_id' => $data['store_id'],
                'customer_id' => $data['customer_id'],
                'type' => $type,
                'kind' => $data['kind'] ?? Invoice::KIND_FULL,
                'quote_id' => $data['quote_id'] ?? null,
                'number' => $this->nextNumber($user->organization_id, $type, $issued),
                'status' => $type === Invoice::TYPE_QUOTE ? 'draft' : 'open',
                'title' => $data['title'] ?? null,
                'issued_on' => $issued,
                'due_on' => $type === Invoice::TYPE_INVOICE ? ($data['due_on'] ?? $issued->copy()->addDays(15)) : null,
                'valid_until' => $type === Invoice::TYPE_QUOTE ? ($data['valid_until'] ?? $issued->copy()->addDays(30)) : null,
                'deposit_percent' => $data['deposit_percent'] ?? null,
                'notes' => $data['notes'] ?? null,
                'token' => Str::random(32),
                'created_by' => $user->id,
            ]);

            $this->setLines($invoice, $data['lines'], (int) ($data['discount_amount'] ?? 0));
            Audit::record('invoice.created', $invoice, "{$invoice->typeLabel()} {$invoice->number} créé(e)", ['total' => $invoice->total], $user);

            return $invoice;
        });
    }

    /** @param list<array{label: string, quantity: float|int|string, unit_price: int|string, catalog_item_id?: ?int}> $lines */
    public function setLines(Invoice $invoice, array $lines, int $discount = 0): void
    {
        $invoice->lines()->delete();
        $subtotal = 0;

        foreach (array_values($lines) as $position => $line) {
            $quantity = max(0, (float) $line['quantity']);
            $amount = (int) round($quantity * (int) $line['unit_price']);
            $subtotal += $amount;
            $invoice->lines()->create([
                'catalog_item_id' => $line['catalog_item_id'] ?? null,
                'label' => mb_substr((string) $line['label'], 0, 255),
                'quantity' => $quantity,
                'unit_price' => (int) $line['unit_price'],
                'amount' => $amount,
                'position' => $position,
            ]);
        }

        $discount = min($discount, $subtotal);
        $invoice->update(['subtotal' => $subtotal, 'discount_amount' => $discount, 'total' => $subtotal - $discount]);
    }

    /** Numéro suivant de l'année : DEV-2026-0001, FAC-2026-0001, AV-2026-0001. */
    public function nextNumber(int $organizationId, string $type, Carbon $date): string
    {
        $prefix = Invoice::PREFIXES[$type].'-'.$date->year.'-';
        $last = Invoice::where('organization_id', $organizationId)->where('number', 'like', $prefix.'%')->lockForUpdate()->max('number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Message WhatsApp d'envoi avec le lien du document. */
    public function shareMessage(Invoice $invoice): string
    {
        $first = ActionLists::firstNameOf((string) $invoice->customer?->name);
        $store = $invoice->store?->name ?? $invoice->organization?->name;
        $amount = self::money($invoice->total);

        if ($invoice->isQuote()) {
            return "Bonjour {$first}, voici votre devis {$invoice->number} de {$store} ({$amount}). Vous pouvez le consulter et l’accepter en un clic ici : {$invoice->publicUrl()}";
        }

        return "Bonjour {$first}, voici votre {$this->lower($invoice)} {$invoice->number} de {$store} : {$amount}"
            .($invoice->due_on ? ', à régler avant le '.$invoice->due_on->locale('fr')->translatedFormat('j F') : '')
            .". Détail : {$invoice->publicUrl()}";
    }

    /** Message de relance (devis sans réponse, facture impayée). */
    public function reminderMessage(Invoice $invoice): string
    {
        $first = ActionLists::firstNameOf((string) $invoice->customer?->name);

        if ($invoice->isQuote()) {
            return "Bonjour {$first}, avez-vous pu regarder notre devis {$invoice->number} ? Nous pouvons l’ajuster à votre besoin. Pour l’accepter : {$invoice->publicUrl()}";
        }

        $late = $invoice->due_on && $invoice->due_on->lt(today());

        return $late
            ? "Bonjour {$first}, sauf erreur de notre part, la facture {$invoice->number} ({$this->money($invoice->balance())} restant) n’est pas encore réglée. Merci de nous dire quand vous pouvez la régler : {$invoice->publicUrl()}"
            : "Bonjour {$first}, petit rappel : la facture {$invoice->number} ({$this->money($invoice->balance())}) est à régler avant le ".$invoice->due_on?->locale('fr')->translatedFormat('j F').'. Merci !';
    }

    public function whatsappUrl(Invoice $invoice, bool $reminder = false): ?string
    {
        $phone = $invoice->customer?->recipient()->phone_e164;

        return $phone ? ActionLists::whatsappUrl($phone, $reminder ? $this->reminderMessage($invoice) : $this->shareMessage($invoice)) : null;
    }

    /** Marque le document envoyé (le lien WhatsApp part depuis le téléphone). */
    public function markSent(Invoice $invoice, User $user): void
    {
        $invoice->update([
            'sent_at' => $invoice->sent_at ?? now(),
            'status' => $invoice->isQuote() && $invoice->status === 'draft' ? 'sent' : $invoice->status,
        ]);
        Audit::record('invoice.sent', $invoice, "{$invoice->typeLabel()} {$invoice->number} envoyé(e) par WhatsApp", [], $user);
    }

    public function markReminded(Invoice $invoice): void
    {
        $invoice->update(['last_reminder_at' => now()]);
    }

    /** Le client accepte le devis en ligne (page publique) ou l'équipe le marque accepté. */
    public function accept(Invoice $quote, ?string $name = null): void
    {
        if (! $quote->isQuote() || ! in_array($quote->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Ce devis ne peut plus être accepté.']);
        }

        $quote->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by_name' => $name ? mb_substr($name, 0, 255) : null]);
    }

    public function refuse(Invoice $quote): void
    {
        if (! $quote->isQuote() || ! in_array($quote->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Ce devis ne peut plus être refusé.']);
        }

        $quote->update(['status' => 'refused', 'refused_at' => now()]);
    }

    /**
     * Devis accepté → facture : complète, ou d'acompte (pourcentage), puis
     * facture du solde (total du devis moins les acomptes déjà facturés).
     */
    public function invoiceQuote(Invoice $quote, User $user, ?int $depositPercent = null): Invoice
    {
        if (! $quote->isQuote() || ! in_array($quote->status, ['accepted', 'sent', 'draft', 'invoiced'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Ce devis ne peut pas être facturé.']);
        }

        $quote->loadMissing('lines', 'invoices');
        $deposits = $quote->invoices->where('kind', Invoice::KIND_DEPOSIT)->where('status', '!=', 'cancelled');
        $alreadyBalanced = $quote->invoices->whereIn('kind', [Invoice::KIND_BALANCE, Invoice::KIND_FULL])->where('status', '!=', 'cancelled')->isNotEmpty();

        if ($alreadyBalanced) {
            throw ValidationException::withMessages(['invoice' => 'Ce devis est déjà entièrement facturé.']);
        }

        $base = [
            'customer_id' => $quote->customer_id,
            'store_id' => $quote->store_id,
            'type' => Invoice::TYPE_INVOICE,
            'quote_id' => $quote->id,
            'title' => $quote->title,
        ];

        if ($depositPercent !== null && $deposits->isEmpty()) {
            $amount = (int) round($quote->total * $depositPercent / 100);
            $invoice = $this->create($user, [...$base,
                'kind' => Invoice::KIND_DEPOSIT,
                'deposit_percent' => $depositPercent,
                'due_on' => today()->addDays(7)->toDateString(),
                'lines' => [['label' => "Acompte de {$depositPercent} % sur le devis {$quote->number}", 'quantity' => 1, 'unit_price' => $amount]],
            ]);
        } else {
            $lines = $quote->lines->map(fn ($l) => ['label' => $l->label, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'catalog_item_id' => $l->catalog_item_id])->all();
            $invoice = $this->create($user, [...$base,
                'kind' => $deposits->isEmpty() ? Invoice::KIND_FULL : Invoice::KIND_BALANCE,
                'discount_amount' => $quote->discount_amount,
                'lines' => $lines,
            ]);

            // Solde : les acomptes facturés viennent en déduction.
            if ($deposits->isNotEmpty()) {
                $lines[] = ['label' => 'Acomptes déjà facturés ('.$deposits->pluck('number')->join(', ').')', 'quantity' => 1, 'unit_price' => 0];
                $this->setLines($invoice, $lines, (int) $quote->discount_amount + (int) $deposits->sum('total'));
            }
        }

        $quote->update(['status' => 'invoiced', 'accepted_at' => $quote->accepted_at ?? now()]);

        return $invoice;
    }

    /** Enregistre un paiement : vente du client, puis statut de la facture. */
    public function pay(Invoice $invoice, User $user, int $amount, ?string $method = null, ?Carbon $paidAt = null): InvoicePayment
    {
        if ($invoice->isQuote() || in_array($invoice->status, ['paid', 'cancelled', 'draft'], true)) {
            throw ValidationException::withMessages(['amount' => 'Cette facture ne peut pas recevoir de paiement.']);
        }

        if ($amount < 1 || $amount > $invoice->balance()) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être compris entre 1 et '.self::money($invoice->balance()).'.']);
        }

        $paidAt ??= now();

        $payment = DB::transaction(function () use ($invoice, $user, $amount, $method, $paidAt) {
            $customer = $invoice->customer;
            $contact = $this->contactFor($customer, $invoice->store_id);
            $first = $invoice->lines()->first();
            $item = $first?->catalog_item_id ? CatalogItem::with('category')->find($first->catalog_item_id) : null;

            $purchase = Purchase::create([
                'contact_id' => $contact->id,
                'store_id' => $invoice->store_id,
                'amount' => $amount,
                'quantity' => 1,
                'subtotal' => $amount,
                'discount_amount' => 0,
                'currency' => $user->organization->currency ?? 'XAF',
                'category_id' => $item?->category_id,
                'product_category' => $item?->category?->name ?? ($invoice->title ?: 'Facture'),
                'purchased_at' => $paidAt,
                'payment_method' => $method,
                'receipt_number' => $invoice->number,
                'type' => Purchase::TYPE_SALE,
                'source' => 'invoice',
                'note' => "Paiement {$invoice->typeLabel()} {$invoice->number}",
            ]);

            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'catalog_item_id' => $item?->id,
                'type' => $item?->type ?? CatalogItem::TYPE_SERVICE,
                'name' => mb_substr(($invoice->title ?: $first?->label ?: 'Facture')." ({$invoice->number})", 0, 255),
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'discount_amount' => 0,
                'category_id' => $item?->category_id,
                'product_category' => $item?->category?->name,
            ]);

            $payment = $invoice->payments()->create([
                'purchase_id' => $purchase->id,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => $paidAt,
                'user_id' => $user->id,
            ]);

            $paid = $invoice->paid_amount + $amount;
            $invoice->update(['paid_amount' => $paid, 'status' => $paid >= $invoice->total ? 'paid' : 'partial']);

            return $payment;
        });

        app(CustomerScoring::class)->refreshOrganization($user->organization);
        Audit::record('invoice.paid', $invoice, "Paiement de {$this->money($amount)} sur {$invoice->number}", ['purchase_id' => $payment->purchase_id], $user);

        return $payment;
    }

    /** Annule une facture sans paiement (les paiements reçus restent des ventes). */
    public function cancel(Invoice $invoice, User $user): void
    {
        if ($invoice->paid_amount > 0) {
            throw ValidationException::withMessages(['invoice' => 'Une facture déjà payée en partie ne peut pas être annulée : faites un avoir.']);
        }

        $invoice->update(['status' => 'cancelled']);

        if ($invoice->quote && $invoice->quote->status === 'invoiced' && $invoice->quote->invoices()->where('status', '!=', 'cancelled')->doesntExist()) {
            $invoice->quote->update(['status' => 'accepted']);
        }

        Audit::record('invoice.cancelled', $invoice, "{$invoice->typeLabel()} {$invoice->number} annulé(e)", [], $user);
    }

    public static function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }

    private function lower(Invoice $invoice): string
    {
        return mb_strtolower(str_replace('’', "'", $invoice->typeLabel()));
    }

    /** Contact du payeur dans l'établissement (créé au besoin). */
    private function contactFor(Customer $customer, int $storeId): Contact
    {
        $payer = $customer->recipient();

        return $payer->contacts()->where('store_id', $storeId)->first()
            ?? Contact::create([
                'store_id' => $storeId,
                'customer_id' => $payer->id,
                'name' => $payer->name,
                'phone_e164' => $payer->phone_e164,
            ]);
    }
}
