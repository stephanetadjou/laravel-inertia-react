<?php

namespace App\Models;

use App\Services\WhatsAppClient;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'brand_id',
        'name',
        'whatsapp_phone_number_id',
        'whatsapp_business_account_id',
        'whatsapp_app_id',
        'whatsapp_access_token',
        'whatsapp_app_secret',
        'whatsapp_verified_name',
        'whatsapp_display_phone_number',
        'whatsapp_quality_rating',
        'whatsapp_messaging_limit_tier',
        'whatsapp_checked_at',
        'whatsapp_registered_at',
    ];

    /**
     * Les secrets Meta ne quittent jamais le serveur (ni JSON, ni Inertia).
     */
    protected $hidden = ['whatsapp_access_token', 'whatsapp_app_secret'];

    protected function casts(): array
    {
        return [
            'whatsapp_access_token' => 'encrypted',
            'whatsapp_app_secret' => 'encrypted',
            'whatsapp_checked_at' => 'datetime',
            'whatsapp_registered_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Client WhatsApp de la boutique. Pour une organisation historique
     * (uses_shared_whatsapp_account), chaque identifiant non renseigné sur la
     * boutique retombe sur le compte Meta partagé (META_WHATSAPP_TOKEN /
     * META_PHONE_NUMBER_ID dans .env). Une entreprise inscrite n'a jamais ce
     * repli : sans identifiants propres, le client lève une exception.
     */
    public function whatsappClient(): WhatsAppClient
    {
        $fallback = $this->mayUseSharedAccount() ? null : '';

        return new WhatsAppClient(
            $this->whatsapp_access_token ?: $fallback,
            $this->whatsapp_phone_number_id ?: $fallback,
        );
    }

    private function mayUseSharedAccount(): bool
    {
        return (bool) $this->organization?->uses_shared_whatsapp_account;
    }

    /**
     * La boutique peut-elle envoyer des messages, avec ses propres
     * identifiants ou ceux du compte partagé ?
     */
    public function canSendWhatsApp(): bool
    {
        // En simulation (WHATSAPP_DRIVER=log), toutes les boutiques « envoient ».
        if (WhatsAppClient::simulated()) {
            return true;
        }

        $shared = $this->mayUseSharedAccount();
        $token = $this->whatsapp_access_token ?: ($shared ? config('services.meta_whatsapp.token') : null);
        $phoneNumberId = $this->whatsapp_phone_number_id ?: ($shared ? config('services.meta_whatsapp.phone_number_id') : null);

        return filled($token) && filled($phoneNumberId);
    }

    /**
     * Nombre de destinataires uniques autorisés par 24 h d'après le dernier
     * test de connexion (TIER_250, TIER_1K…). Null si inconnu ou illimité.
     */
    public function dailyMessagingLimit(): ?int
    {
        return match ($this->whatsapp_messaging_limit_tier) {
            'TIER_50' => 50,
            'TIER_250' => 250,
            'TIER_1K' => 1_000,
            'TIER_2K' => 2_000,
            'TIER_10K' => 10_000,
            'TIER_100K' => 100_000,
            default => null,
        };
    }

    public function usesSharedWhatsAppAccount(): bool
    {
        return $this->mayUseSharedAccount()
            && blank($this->whatsapp_access_token) && blank($this->whatsapp_phone_number_id);
    }
}
