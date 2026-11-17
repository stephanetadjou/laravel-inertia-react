<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formation proposée par un centre (module « formation ») : heures de leçons
 * individuelles et séances collectives prévues, durée conseillée, examens à
 * réussir (dans l'ordre), formule de paiement associée.
 */
class Training extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'hours_planned',
        'sessions_planned',
        'duration_weeks',
        'exams',
        'plan_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'exams' => 'array',
            'active' => 'boolean',
            'hours_planned' => 'integer',
            'sessions_planned' => 'integer',
            'duration_weeks' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function learners(): HasMany
    {
        return $this->hasMany(LearnerTraining::class);
    }

    public function groupClasses(): HasMany
    {
        return $this->hasMany(GroupClass::class);
    }

    /** @return list<string> */
    public function examTypes(): array
    {
        return array_values(array_filter(array_map('trim', $this->exams ?? []), fn ($t) => $t !== ''));
    }
}
