<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Budget publicitaire saisi par l'entreprise, par source et par mois (YYYY-MM). */
class AdBudget extends Model
{
    protected $fillable = ['organization_id', 'source', 'month', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
