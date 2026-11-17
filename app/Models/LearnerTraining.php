<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parcours d'un apprenant dans une formation : heures prévues (plus les
 * heures achetées en plus), formateur référent, statut. Les heures faites
 * se calculent à partir des leçons honorées et des présences aux cours de la
 * formation (cf. App\Services\Learners), jamais saisies à la main.
 */
class LearnerTraining extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUSES = [
        'active' => 'En cours',
        'paused' => 'En pause',
        'completed' => 'Terminé',
        'dropped' => 'Abandon',
    ];

    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'training_id',
        'subscription_id',
        'staff_member_id',
        'started_on',
        'target_on',
        'hours_planned',
        'extra_hours',
        'sessions_planned',
        'status',
        'ended_on',
        'end_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hours_planned' => 'integer',
            'extra_hours' => 'integer',
            'sessions_planned' => 'integer',
        ];
    }

    /** @param  Builder<LearnerTraining>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(LearnerExam::class)->orderBy('exam_on')->orderBy('id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function totalHours(): int
    {
        return (int) $this->hours_planned + (int) $this->extra_hours;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
