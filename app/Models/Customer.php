<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Fiche client unique d'une personne pour tout le Groupe (une par
 * organisation et par numéro). Les contacts sont ses relations avec chaque
 * boutique ; les compteurs et l'étape du parcours sont tenus à jour par
 * App\Services\CustomerLifecycle.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public const STAGE_CONTACT = 'contact';

    public const STAGE_PROSPECT = 'prospect';

    public const STAGE_NEW = 'new';

    public const STAGE_ACTIVE = 'active';

    public const STAGE_AT_RISK = 'at_risk';

    public const STAGE_CHURNED = 'churned';

    /**
     * Étapes du parcours du Groupe, dans l'ordre, avec leur libellé. Le
     * statut VIP n'en fait pas partie : il est propre à chaque boutique
     * (contacts.is_vip), un client pouvant être VIP chez Mango et pas ailleurs.
     */
    public const STAGES = [
        self::STAGE_CONTACT => 'Contact',
        self::STAGE_PROSPECT => 'Prospect',
        self::STAGE_NEW => 'Nouveau client',
        self::STAGE_ACTIVE => 'Client actif',
        self::STAGE_AT_RISK => 'À risque',
        self::STAGE_CHURNED => 'Inactif',
    ];

    protected $fillable = [
        'organization_id',
        'phone_e164',
        'previous_phones',
        'name',
        'identity_confirmed_at',
        'gender',
        'birthdate',
        'first_purchase_at',
        'last_purchase_at',
        'purchases_count',
        'total_spent',
        'lifecycle_stage',
        'lifecycle_changed_at',
        'rfm_r',
        'rfm_f',
        'rfm_m',
        'rfm_segment',
        'score',
        'avg_purchase_interval_days',
        'next_purchase_expected_at',
        'churn_risk',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_phones' => 'array',
            'identity_confirmed_at' => 'datetime',
            'birthdate' => 'date',
            'first_purchase_at' => 'date',
            'last_purchase_at' => 'date',
            'lifecycle_changed_at' => 'datetime',
            'next_purchase_expected_at' => 'date',
            'scored_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function purchases(): HasManyThrough
    {
        return $this->hasManyThrough(Purchase::class, Contact::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(CustomerFollowUp::class);
    }

    public function stageChanges(): HasMany
    {
        return $this->hasMany(CustomerStageChange::class);
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->lifecycle_stage] ?? $this->lifecycle_stage;
    }
}
