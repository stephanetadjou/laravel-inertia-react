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
        'type',
        'company_name',
        'contact_name',
        'external_code',
    ];

    public const TYPE_PERSON = 'person';

    /** Client professionnel (grossiste, revendeur, restaurant…) : raison sociale et personne à contacter. */
    public const TYPE_BUSINESS = 'business';

    public const TYPES = [
        self::TYPE_PERSON => 'Particulier',
        self::TYPE_BUSINESS => 'Entreprise',
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

    /**
     * Numéros de la fiche (customer_phones) tenus à jour avec phone_e164 :
     * le numéro principal à la création, puis à chaque changement l'ancien
     * numéro est clos (valid_to) et le nouveau devient principal.
     */
    protected static function booted(): void
    {
        static::created(function (Customer $customer) {
            CustomerPhone::create(['customer_id' => $customer->id, 'phone_e164' => $customer->phone_e164, 'is_primary' => true, 'valid_from' => today()]);
        });

        static::updated(function (Customer $customer) {
            if (! $customer->wasChanged('phone_e164')) {
                return;
            }

            CustomerPhone::where('customer_id', $customer->id)->where('is_primary', true)
                ->update(['is_primary' => false, 'valid_to' => today()]);

            CustomerPhone::updateOrCreate(
                ['customer_id' => $customer->id, 'phone_e164' => $customer->phone_e164],
                ['is_primary' => true, 'valid_to' => null, 'valid_from' => today()],
            );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(CustomerConsent::class);
    }

    /** Numéro marqué partagé : une vente à un autre nom passe par « À vérifier ». */
    public function hasSharedPhone(): bool
    {
        return $this->phones()->where('phone_e164', $this->phone_e164)->where('is_shared', true)->exists();
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

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(CustomerFollowUp::class);
    }

    public function stageChanges(): HasMany
    {
        return $this->hasMany(CustomerStageChange::class);
    }

    public function isBusiness(): bool
    {
        return $this->type === self::TYPE_BUSINESS;
    }

    /** Nom affiché : la raison sociale d'une entreprise, sinon le nom. */
    public function displayName(): string
    {
        return $this->isBusiness() && filled($this->company_name) ? (string) $this->company_name : (string) $this->name;
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->lifecycle_stage] ?? $this->lifecycle_stage;
    }
}
