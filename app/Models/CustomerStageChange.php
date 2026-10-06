<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Passage d'une fiche client d'une étape du parcours à une autre. */
class CustomerStageChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'from_stage', 'to_stage', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
