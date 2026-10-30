<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Correspondance « libellé du fichier de ventes → famille », créée depuis
 * l'écran « À classer » et réutilisée à chaque import (cf. FamilyMatcher).
 * `label` est le libellé normalisé, `raw_label` celui affiché.
 */
class CategoryRule extends Model
{
    protected $fillable = [
        'organization_id',
        'category_id',
        'label',
        'raw_label',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
