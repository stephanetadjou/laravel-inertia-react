<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cours régulier (module « seances_groupe ») : une activité, un lieu
 * (établissement), un coach (intervenant), un jour et une heure, des places.
 */
class GroupClass extends Model
{
    public const WEEKDAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    protected $fillable = [
        'organization_id',
        'store_id',
        'catalog_item_id',
        'staff_member_id',
        'name',
        'weekday',
        'start_time',
        'end_time',
        'capacity',
        'starts_on',
        'ends_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'weekday' => 'integer',
            'capacity' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class);
    }

    /** Inscriptions en cours (sans date de fin). */
    public function activeEnrollments(): HasMany
    {
        return $this->enrollments()->whereNull('ended_on');
    }

    /** « Samedi 10h30 – 11h30 ». */
    public function scheduleLabel(): string
    {
        return self::WEEKDAYS[$this->weekday].' '.str_replace(':', 'h', $this->start_time).' – '.str_replace(':', 'h', $this->end_time);
    }
}
