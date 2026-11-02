<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Séance datée d'un cours : prévue, annulée (motif) ou rattrapage d'une
 * séance annulée (makeup_of_id). L'appel est dans attendances.
 */
class ClassSession extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    /** Motifs d'annulation ; « weather » prévient les inscrits et compte pour le rattrapage. */
    public const CANCEL_REASONS = [
        'weather' => 'Intempérie (pluie, orage)',
        'coach' => 'Coach absent',
        'venue' => 'Lieu indisponible',
        'other' => 'Autre',
    ];

    protected $fillable = [
        'group_class_id',
        'organization_id',
        'session_date',
        'start_time',
        'end_time',
        'status',
        'cancel_reason',
        'cancel_note',
        'cancelled_at',
        'makeup_of_id',
    ];

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime'];
    }

    public function groupClass(): BelongsTo
    {
        return $this->belongsTo(GroupClass::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function makeupOf(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'makeup_of_id');
    }

    public function makeups(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'makeup_of_id');
    }

    public function date(): Carbon
    {
        return Carbon::parse($this->session_date);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** « samedi 12 octobre, 10h30 ». */
    public function label(): string
    {
        return $this->date()->locale('fr')->translatedFormat('l j F').', '.str_replace(':', 'h', $this->start_time);
    }
}
