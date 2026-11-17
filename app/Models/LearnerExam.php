<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Examen d'un apprenant (code, conduite, certification…) : date, tentative, résultat. */
class LearnerExam extends Model
{
    public const RESULTS = [
        'scheduled' => 'Prévu',
        'passed' => 'Réussi',
        'failed' => 'Échoué',
        'absent' => 'Absent',
    ];

    protected $fillable = [
        'organization_id',
        'learner_training_id',
        'customer_id',
        'type',
        'exam_on',
        'exam_time',
        'attempt',
        'result',
        'score',
        'notes',
    ];

    protected function casts(): array
    {
        return ['attempt' => 'integer'];
    }

    public function learnerTraining(): BelongsTo
    {
        return $this->belongsTo(LearnerTraining::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function resultLabel(): string
    {
        return self::RESULTS[$this->result] ?? $this->result;
    }
}
