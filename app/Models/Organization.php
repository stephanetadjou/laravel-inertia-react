<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'lifecycle_settings', 'uses_shared_whatsapp_account'];

    /**
     * Seuils par défaut du parcours client (modifiables dans Organisation).
     * Durées en jours ; VIP = top X % des acheteurs de chaque boutique (CA dans
     * la boutique sur la période), un client pouvant être VIP dans une seule.
     */
    public const LIFECYCLE_DEFAULTS = [
        'new_customer_days' => 90,
        'at_risk_days' => 90,
        'churn_days' => 180,
        'vip_top_percent' => 10,
        'vip_period_days' => 365,
    ];

    protected function casts(): array
    {
        return [
            'lifecycle_settings' => 'array',
            // Repli autorisé sur le compte Meta partagé (.env) : organisations
            // historiques uniquement, jamais une entreprise inscrite.
            'uses_shared_whatsapp_account' => 'boolean',
        ];
    }

    /**
     * @return array{new_customer_days: int, at_risk_days: int, churn_days: int, vip_top_percent: int, vip_period_days: int}
     */
    public function lifecycleSettings(): array
    {
        $saved = array_intersect_key($this->lifecycle_settings ?? [], self::LIFECYCLE_DEFAULTS);

        return array_map('intval', [...self::LIFECYCLE_DEFAULTS, ...$saved]);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
