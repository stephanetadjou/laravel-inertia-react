<?php

namespace App\Modules\Facturation\Models;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Devis, facture ou avoir d'un client (module « factures »). */
class Invoice extends Model
{
    public const TYPE_QUOTE = 'quote';

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT = 'credit';

    public const KIND_FULL = 'full';

    public const KIND_DEPOSIT = 'deposit';

    public const KIND_BALANCE = 'balance';

    public const PREFIXES = [self::TYPE_QUOTE => 'DEV', self::TYPE_INVOICE => 'FAC', self::TYPE_CREDIT => 'AV'];

    /** Statuts enregistrés ; « expired » et « overdue » sont calculés (cf. displayStatus). */
    public const QUOTE_STATUSES = [
        'draft' => 'Brouillon',
        'sent' => 'Envoyé',
        'accepted' => 'Accepté',
        'refused' => 'Refusé',
        'invoiced' => 'Facturé',
        'expired' => 'Expiré',
    ];

    public const INVOICE_STATUSES = [
        'draft' => 'Brouillon',
        'open' => 'À payer',
        'partial' => 'Payée en partie',
        'paid' => 'Payée',
        'cancelled' => 'Annulée',
        'overdue' => 'En retard',
    ];

    protected $fillable = [
        'organization_id', 'store_id', 'customer_id', 'type', 'kind', 'quote_id', 'number', 'status', 'title',
        'issued_on', 'due_on', 'valid_until', 'subtotal', 'discount_amount', 'total', 'paid_amount', 'deposit_percent',
        'notes', 'token', 'sent_at', 'viewed_at', 'accepted_at', 'accepted_by_name', 'refused_at', 'last_reminder_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'refused_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quote_id');
    }

    /** Factures (acompte, solde) issues d'un devis. */
    public function invoices(): HasMany
    {
        return $this->hasMany(self::class, 'quote_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_at');
    }

    public function isQuote(): bool
    {
        return $this->type === self::TYPE_QUOTE;
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->paid_amount);
    }

    /** Statut affiché : expiré (devis) ou en retard (facture) calculés à la date du jour. */
    public function displayStatus(): string
    {
        if ($this->isQuote()) {
            return $this->status === 'sent' && $this->valid_until && $this->valid_until->lt(today()) ? 'expired' : $this->status;
        }

        return in_array($this->status, ['open', 'partial'], true) && $this->due_on && $this->due_on->lt(today()) ? 'overdue' : $this->status;
    }

    public function statusLabel(): string
    {
        $labels = $this->isQuote() ? self::QUOTE_STATUSES : self::INVOICE_STATUSES;

        return $labels[$this->displayStatus()] ?? $this->status;
    }

    public function typeLabel(): string
    {
        return match (true) {
            $this->type === self::TYPE_QUOTE => 'Devis',
            $this->type === self::TYPE_CREDIT => 'Avoir',
            $this->kind === self::KIND_DEPOSIT => 'Facture d’acompte',
            $this->kind === self::KIND_BALANCE => 'Facture de solde',
            default => 'Facture',
        };
    }

    public function publicUrl(): string
    {
        return url("/d/{$this->token}");
    }
}
