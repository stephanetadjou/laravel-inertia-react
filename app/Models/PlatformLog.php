<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal du back-office plateforme : actions des super-admins Menentia sur
 * les entreprises (essai, offre, suspension) et les demandes de démo.
 */
class PlatformLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'organization.trial_extended' => 'Essai prolongé',
        'organization.plan_changed' => 'Offre modifiée',
        'organization.activated' => 'Abonnement activé',
        'organization.suspended' => 'Entreprise suspendue',
        'organization.reactivated' => 'Entreprise réactivée',
        'lead.updated' => 'Demande de démo mise à jour',
        'leads.exported' => 'Demandes de démo exportées',
        'store.whatsapp_managed' => 'WhatsApp géré par Menentia',
    ];

    protected $fillable = ['user_id', 'organization_id', 'lead_id', 'action', 'description', 'payload', 'ip_address'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $action, ?string $description = null, array $payload = [], ?Organization $organization = null, ?Lead $lead = null): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'organization_id' => $organization?->id,
            'lead_id' => $lead?->id,
            'action' => $action,
            'description' => $description,
            'payload' => $payload ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
