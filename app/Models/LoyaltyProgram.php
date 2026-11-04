<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Programme de fidélité daté, sur une ou plusieurs enseignes (cf. LoyaltyEngine). */
class LoyaltyProgram extends Model
{
    protected $fillable = ['organization_id', 'name', 'starts_on', 'ends_on', 'same_rules'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'same_rules' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(LoyaltyProgramRule::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(LoyaltyCard::class);
    }
}
