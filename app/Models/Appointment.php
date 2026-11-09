<?php

namespace App\Models;

use App\Services\ProspectPipeline;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rendez-vous d'un client dans un établissement.
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    public const STATUSES = [
        // Réservation en ligne en attente de validation par l'équipe.
        'requested' => 'À confirmer',
        'planned' => 'Prévu',
        'confirmed' => 'Confirmé',
        'done' => 'Honoré',
        'no_show' => 'Absent',
        'cancelled' => 'Annulé',
    ];

    /** Statuts d'un rendez-vous encore à venir (à rappeler ou confirmer). */
    public const OPEN_STATUSES = ['requested', 'planned', 'confirmed'];

    /** Statuts qui occupent un créneau (capacité de l'établissement). */
    public const BLOCKING_STATUSES = ['requested', 'planned', 'confirmed', 'done'];

    public const SOURCE_STAFF = 'staff';

    public const SOURCE_ONLINE = 'online';

    protected $fillable = [
        'store_id',
        'customer_id',
        'staff_user_id',
        'created_by',
        'starts_at',
        'duration_minutes',
        'service',
        'status',
        'notes',
        'purchase_id',
        'source',
        'cancel_token',
        'staff_member_id',
        'kind',
        'latitude',
        'longitude',
        'subscription_id',
        'reminder_sent_at',
        'client_response',
        'client_response_at',
    ];

    /** Types de rendez-vous ; visite et démo servent au suivi des prospects et des commerciaux. */
    public const KINDS = [
        'rdv' => 'Rendez-vous',
        'visite' => 'Visite',
        'demo' => 'Démo',
        'essai' => 'Séance d’essai',
    ];

    protected static function booted(): void
    {
        // Visite ou démo honorée : le prospect avance d'étape (cf. ProspectPipeline).
        static::updated(function (Appointment $appointment) {
            if ($appointment->wasChanged('status') && in_array($appointment->status, ['done', 'confirmed'], true)) {
                $organization = $appointment->store?->organization;
                if ($organization?->hasModule('prospects')) {
                    ProspectPipeline::for($organization)->onAppointmentEvent($appointment, $appointment->status);
                }
            }
        });

        // Rendez-vous pris : le prospect passe à « RDV réservé » / « Essai planifié » (si l'entreprise a ces étapes).
        static::created(function (Appointment $appointment) {
            $organization = $appointment->store?->organization;
            if ($organization?->hasModule('prospects') && in_array($appointment->status, Appointment::OPEN_STATUSES, true)) {
                ProspectPipeline::for($organization)->onAppointmentEvent($appointment, $appointment->status === 'confirmed' ? 'confirmed' : 'booked');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'client_response_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /** Intervenant du rendez-vous. */
    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    /** Vente créée à l'encaissement. */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
