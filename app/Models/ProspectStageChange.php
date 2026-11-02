<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Passage d'un prospect d'une étape à une autre (taux de passage par commercial). */
class ProspectStageChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'user_id', 'owner_user_id', 'from_stage', 'to_stage', 'to_kind', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
