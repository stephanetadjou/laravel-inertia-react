<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot d'import de ventes (cf. App\Services\SalesImport) : un fichier déposé,
 * son mapping de colonnes, ses lignes et son bilan.
 *
 * uploaded → mapping_required → validated → processing →
 * completed | completed_with_errors | failed ; cancelled à tout moment
 * (un lot traité annulé voit ses ventes supprimées, action journalisée).
 */
class ImportBatch extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_MAPPING_REQUIRED = 'mapping_required';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_UPLOADED => 'Déposé',
        self::STATUS_MAPPING_REQUIRED => 'Colonnes à associer',
        self::STATUS_VALIDATED => 'Vérifié, à confirmer',
        self::STATUS_PROCESSING => 'En cours',
        self::STATUS_COMPLETED => 'Terminé',
        self::STATUS_COMPLETED_WITH_ERRORS => 'Terminé avec rejets',
        self::STATUS_FAILED => 'Échec',
        self::STATUS_CANCELLED => 'Annulé',
    ];

    protected $fillable = [
        'organization_id',
        'brand_id',
        'store_id',
        'user_id',
        'original_filename',
        'file_path',
        'file_hash',
        'mapping_id',
        'mapping',
        'headers',
        'default_category_id',
        'status',
        'total_rows',
        'accepted_rows',
        'rejected_rows',
        'duplicate_rows',
        'tickets_count',
        'lines_count',
        'anonymous_tickets',
        'unclassified_lines',
        'customers_created',
        'error_message',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'headers' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS], true);
    }

    /** Un lot encore modifiable : mapping, vérification, confirmation. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_UPLOADED, self::STATUS_MAPPING_REQUIRED, self::STATUS_VALIDATED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'default_category_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class)->withoutGlobalScope('valid');
    }
}
