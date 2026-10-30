<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'un fichier importé : contenu brut, données lues, statut
 * (valid, rejected, duplicate, imported) et erreur éventuelle.
 */
class ImportRow extends Model
{
    public $timestamps = false;

    public const STATUS_VALID = 'valid';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_IMPORTED = 'imported';

    protected $fillable = ['import_batch_id', 'row_number', 'payload', 'data', 'status', 'error_code', 'error_message'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'data' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
