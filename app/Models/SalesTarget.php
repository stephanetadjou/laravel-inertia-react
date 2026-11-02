<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Objectifs mensuels d'un commercial, saisis par le directeur. */
class SalesTarget extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'month', 'visits', 'demos', 'trials', 'customers', 'revenue'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
