<?php

namespace App\Modules\Caisse\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Clôture de caisse d'un établissement pour une journée. */
class CashClosing extends Model
{
    protected $fillable = ['organization_id', 'store_id', 'day', 'opening_float', 'cash_expected', 'cash_counted', 'difference', 'total_in', 'total_out', 'note', 'closed_by', 'closed_at'];

    protected function casts(): array
    {
        return ['day' => 'date', 'closed_at' => 'datetime'];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
