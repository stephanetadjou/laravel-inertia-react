<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle de correspondance des colonnes d'un fichier de ventes (colonne du
 * fichier => champ de Menentia), réutilisable d'un import à l'autre.
 */
class ImportMapping extends Model
{
    protected $fillable = ['organization_id', 'brand_id', 'name', 'mapping', 'version'];

    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
