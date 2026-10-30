<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Demande de démonstration reçue depuis le site vitrine.
 */
class Lead extends Model
{
    // Valeurs du champ « Activité » du formulaire de démo (lib/families.ts du site, plus « autre »).
    public const ACTIVITIES = ['beaute', 'sante', 'sport', 'technique', 'pro', 'commerce', 'autre'];

    public const STATUSES = [
        'new' => 'Nouveau',
        'contacted' => 'Contacté',
        'converted' => 'Converti',
        'lost' => 'Perdu',
    ];

    protected $fillable = [
        'name', 'company', 'whatsapp_raw', 'whatsapp', 'city', 'activity',
        'message', 'consent_at', 'source', 'status', 'ip_address', 'note', 'contacted_at',
    ];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime', 'contacted_at' => 'datetime'];
    }
}
