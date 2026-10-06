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
     * Client WhatsApp de la boutique. Chaque identifiant non renseigné sur la
     * boutique retombe sur le compte Meta partagé de l'organisation
     * (META_WHATSAPP_TOKEN / META_PHONE_NUMBER_ID dans .env) : une boutique
     * sans configuration propre envoie donc depuis le numéro commun, et une
     * boutique qui n'a saisi que son phone_number_id utilise le token partagé.
     */
    public function whatsappClient(): WhatsAppClient
    {
        return new WhatsAppClient(
            $this->whatsapp_access_token ?: null,
            $this->whatsapp_phone_number_id ?: null,
        );
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

        $token = $this->whatsapp_access_token ?: config('services.meta_whatsapp.token');
        $phoneNumberId = $this->whatsapp_phone_number_id ?: config('services.meta_whatsapp.phone_number_id');

        return filled($token) && filled($phoneNumberId);
    }

    public function usesSharedWhatsAppAccount(): bool
    {
        return blank($this->whatsapp_access_token) && blank($this->whatsapp_phone_number_id);
    }
}
