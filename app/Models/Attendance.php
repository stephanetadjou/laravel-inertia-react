<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Présence d'une personne à une séance : présent, absent ou excusé. */
class Attendance extends Model
{
    public const STATUSES = [
        'present' => 'Présent',
        'absent' => 'Absent',
        'excused' => 'Excusé',
    ];

    protected $fillable = [
        'class_session_id',
        'customer_id',
        'status',
        'marked_by',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
