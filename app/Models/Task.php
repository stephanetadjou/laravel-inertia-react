<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tâche de « À faire » créée par NENTIX (client qui veut parler à quelqu'un,
 * demande de rappel, rendez-vous à recaser, relance de devis…). Elle quitte
 * la liste quand elle est faite (done_at).
 */
class Task extends Model
{
    public const KINDS = [
        'talk_to_human' => 'Parler à quelqu’un',
        'callback' => 'Être rappelé',
        'reschedule' => 'Rendez-vous à recaser',
        'other' => 'Autre',
    ];

    protected $fillable = [
        'organization_id', 'store_id', 'customer_id', 'contact_id', 'appointment_id', 'assigned_user_id',
        'kind', 'title', 'details', 'due_on', 'done_at',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    /** Ouvre une tâche, sauf si la même (type, contact, rendez-vous) est déjà ouverte. */
    public static function open(array $attributes): self
    {
        return self::whereNull('done_at')->firstOrCreate(
            array_intersect_key($attributes, array_flip(['organization_id', 'kind', 'customer_id', 'contact_id', 'appointment_id'])),
            $attributes + ['due_on' => today()],
        );
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
