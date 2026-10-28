<?php

namespace App\Models;

use App\Services\WhatsAppClient;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'webhook_key',
        'webhook_verify_token',
        'booking_hours',
        'booking_capacity',
        'booking_phone',
        'booking_break',
    ];

    /**
     * Horaires par défaut de la réservation en ligne (jour ISO 1 = lundi) :
     * du lundi au samedi de 9 h à 18 h, fermé le dimanche.
     */
    public const DEFAULT_BOOKING_HOURS = [
        1 => ['open' => '09:00', 'close' => '18:00'],
        2 => ['open' => '09:00', 'close' => '18:00'],
        3 => ['open' => '09:00', 'close' => '18:00'],
        4 => ['open' => '09:00', 'close' => '18:00'],
        5 => ['open' => '09:00', 'close' => '18:00'],
        6 => ['open' => '09:00', 'close' => '18:00'],
        7 => null,
    ];

    /**
     * Les secrets Meta ne quittent jamais le serveur (ni JSON, ni Inertia).
     */
    protected $hidden = ['whatsapp_access_token', 'whatsapp_app_secret', 'webhook_verify_token'];

    protected function casts(): array
    {
        return [
            'whatsapp_access_token' => 'encrypted',
            'whatsapp_app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'whatsapp_checked_at' => 'datetime',
            'booking_hours' => 'array',
            'booking_capacity' => 'integer',
            'booking_break' => 'array',
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
     * Crée au besoin la clé de l'URL du webhook et le jeton de vérification
     * que l'administrateur recopie dans la configuration de son app Meta.
     */
    public function ensureWebhookCredentials(): void
    {
        if (filled($this->webhook_key) && filled($this->webhook_verify_token)) {
            return;
        }

        $this->forceFill([
            'webhook_key' => $this->webhook_key ?: Str::random(40),
            'webhook_verify_token' => $this->webhook_verify_token ?: Str::random(32),
        ])->save();
    }

    public function webhookUrl(): ?string
    {
        return $this->webhook_key ? route('webhooks.whatsapp', $this->webhook_key) : null;
    }

    /**
     * Vérifie l'en-tête X-Hub-Signature-256 envoyé par Meta : HMAC SHA-256
     * du corps brut avec l'App Secret de l'établissement. Sans App Secret
     * enregistré, aucun appel n'est accepté.
     */
    public function hasValidWebhookSignature(string $rawBody, ?string $header): bool
    {
        if (blank($this->whatsapp_app_secret) || ! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $this->whatsapp_app_secret);

        return hash_equals($expected, $header);
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

    /**
     * Horaires d'ouverture pour la réservation en ligne, jour par jour
     * (1 = lundi … 7 = dimanche) ; null = fermé.
     *
     * @return array<int, array{open: string, close: string}|null>
     */
    public function bookingHours(): array
    {
        $saved = $this->booking_hours ?? [];
        $hours = [];

        foreach (self::DEFAULT_BOOKING_HOURS as $day => $default) {
            // Clés JSON « 1 »…« 7 » : entiers une fois décodées.
            $value = array_key_exists($day, $saved) ? $saved[$day] : $default;
            $hours[$day] = is_array($value) && isset($value['open'], $value['close']) && $value['open'] < $value['close']
                ? ['open' => (string) $value['open'], 'close' => (string) $value['close']]
                : null;
        }

        return $hours;
    }

    /** Pause quotidienne (ex. déjeuner 12:00 – 14:00), ou null. */
    public function bookingBreak(): ?array
    {
        $break = $this->booking_break;

        return is_array($break) && isset($break['start'], $break['end']) && $break['start'] < $break['end']
            ? ['start' => (string) $break['start'], 'end' => (string) $break['end']]
            : null;
    }

    public function staffMembers(): HasMany
    {
        return $this->hasMany(StaffMember::class);
    }

    /** Numéro WhatsApp à afficher au client (récapitulatif wa.me). */
    public function bookingPhone(): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) ($this->booking_phone ?: $this->whatsapp_display_phone_number));

        return $phone !== '' ? $phone : null;
    }
}
