<?php

namespace App\Models;

use App\Services\ActionLists;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'lifecycle_settings',
        'uses_shared_whatsapp_account',
        'legal_name',
        'privacy_email',
        'privacy_phone',
        'privacy_address',
        'privacy_extra',
        'whatsapp_api_enabled',
        'action_messages',
        'booking_settings',
        'report_settings',
    ];

    /**
     * Adresse publique de la politique de confidentialité : slug unique tiré
     * du nom, fixé à la création (il ne change pas si l'entreprise est
     * renommée, l'URL étant déjà déclarée chez Meta).
     */
    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            if (blank($organization->slug)) {
                $organization->slug = static::uniqueSlug((string) $organization->name);
            }
        });
    }

    /**
     * Slug libre tiré du nom (« retail-group », sinon « retail-group-2 »…).
     * À calculer avant d'ouvrir une transaction : avec SQLite, une transaction
     * qui lit avant d'écrire échoue au lieu d'attendre si un autre processus
     * écrit au même moment (cf. config/database.php).
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'entreprise';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function privacyPolicyUrl(): string
    {
        return route('privacy-policy', $this->slug);
    }

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
            // Envoi par l'API WhatsApp Cloud (campagnes, modèles, bienvenue, webhook).
            // Désactivé : le CRM fonctionne avec des relances manuelles par lien wa.me.
            'whatsapp_api_enabled' => 'boolean',
            'action_messages' => 'array',
            'booking_settings' => 'array',
            'report_settings' => 'array',
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

    /**
     * Envoi par l'API WhatsApp Cloud activé (par défaut pour les organisations
     * existantes). Sans API : listes d'actions et liens wa.me uniquement.
     */
    public function usesWhatsAppApi(): bool
    {
        return $this->whatsapp_api_enabled ?? true;
    }

    /**
     * Messages préremplis des listes d'actions, modifiables dans la page
     * Actions : défauts complétés par ceux enregistrés par l'entreprise.
     *
     * @return array<string, string>
     */
    public function actionMessages(): array
    {
        $saved = array_filter(
            array_intersect_key($this->action_messages ?? [], ActionLists::DEFAULT_MESSAGES),
            fn ($text) => is_string($text) && trim($text) !== '',
        );

        return [...ActionLists::DEFAULT_MESSAGES, ...$saved];
    }

    /**
     * Réglages de la réservation en ligne (Paramètres › Réservation en ligne).
     * Désactivée par défaut ; une réservation arrive « À confirmer » sauf si
     * la confirmation automatique est choisie.
     */
    public const BOOKING_DEFAULTS = [
        'enabled' => false,
        'auto_confirm' => false,
        'slot_step' => 30,
        'min_notice_minutes' => 120,
        'horizon_days' => 30,
        'welcome' => '',
    ];

    /** @return array{enabled: bool, auto_confirm: bool, slot_step: int, min_notice_minutes: int, horizon_days: int, welcome: string} */
    public function bookingSettings(): array
    {
        $saved = array_intersect_key($this->booking_settings ?? [], self::BOOKING_DEFAULTS);
        $settings = [...self::BOOKING_DEFAULTS, ...$saved];

        return [
            'enabled' => (bool) $settings['enabled'],
            'auto_confirm' => (bool) $settings['auto_confirm'],
            'slot_step' => (int) $settings['slot_step'],
            'min_notice_minutes' => (int) $settings['min_notice_minutes'],
            'horizon_days' => (int) $settings['horizon_days'],
            'welcome' => (string) $settings['welcome'],
        ];
    }

    /**
     * Rapport quotidien au dirigeant (Paramètres › Rapports) : désactivé par
     * défaut, envoyé chaque soir à l'heure choisie aux adresses indiquées.
     */
    public const REPORT_DEFAULTS = [
        'enabled' => false,
        'recipients' => [],
        'send_at' => '21:00',
        'monthly_target' => 0,
        'last_sent_on' => null,
    ];

    /** @return array{enabled: bool, recipients: list<string>, send_at: string, monthly_target: int, last_sent_on: string|null} */
    public function reportSettings(): array
    {
        $settings = [...self::REPORT_DEFAULTS, ...array_intersect_key($this->report_settings ?? [], self::REPORT_DEFAULTS)];

        return [
            'enabled' => (bool) $settings['enabled'],
            'recipients' => array_values(array_filter((array) $settings['recipients'], 'is_string')),
            'send_at' => (string) $settings['send_at'],
            'monthly_target' => (int) $settings['monthly_target'],
            'last_sent_on' => $settings['last_sent_on'] ?: null,
        ];
    }

    public function bookingUrl(): string
    {
        return route('booking.show', $this->slug);
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
