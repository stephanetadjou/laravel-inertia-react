<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Inscription d'une personne (client ou personne suivie) à un cours. */
class ClassEnrollment extends Model
{
    protected $fillable = [
        'group_class_id',
        'customer_id',
        'subscription_id',
        'enrolled_on',
        'ended_on',
    ];

    public function groupClass(): BelongsTo
    {
        return $this->belongsTo(GroupClass::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
