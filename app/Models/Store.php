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
        'whatsapp_access_token',
    ];

    protected function casts(): array
    {
        return [
            'whatsapp_access_token' => 'encrypted',
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

    public function usesSharedWhatsAppAccount(): bool
    {
        return $this->mayUseSharedAccount()
            && blank($this->whatsapp_access_token) && blank($this->whatsapp_phone_number_id);
    }
}
