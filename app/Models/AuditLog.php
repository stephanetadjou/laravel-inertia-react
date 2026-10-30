<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée du journal d'audit (cf. App\Services\Audit) : jamais modifiée ni
 * supprimée depuis l'application.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Libellés des actions journalisées (écran Paramètres › Journal). */
    public const ACTIONS = [
        'import.created' => 'Import de ventes déposé',
        'import.completed' => 'Import de ventes traité',
        'import.cancelled' => 'Import de ventes annulé',
        'export' => 'Export de données',
        'sale.cancelled' => 'Vente annulée',
        'sale.restored' => 'Vente rétablie',
        'customer.merged' => 'Fiches clients fusionnées',
        'customer.split' => 'Fiche client séparée',
        'customer.review' => 'Rapprochement client validé',
        'brand.created' => 'Marque créée',
        'brand.updated' => 'Marque modifiée',
        'brand.deleted' => 'Marque supprimée',
        'store.created' => 'Point de vente créé',
        'store.updated' => 'Point de vente modifié',
        'store.deleted' => 'Point de vente supprimé',
        'user.created' => 'Utilisateur créé',
        'user.updated' => 'Utilisateur modifié',
        'user.deleted' => 'Utilisateur supprimé',
        'settings.updated' => 'Paramètres modifiés',
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'payload',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
