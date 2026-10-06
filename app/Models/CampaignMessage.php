<?php

namespace App\Models;

use Database\Factories\CampaignMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CampaignMessage extends Model
{
    /** @use HasFactory<CampaignMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'status',
        'meta_message_id',
        'error_message',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Applique un statut reçu du webhook Meta. Idempotent et insensible à
     * l'ordre d'arrivée : une date déjà connue n'est jamais remplacée, et un
     * « lu » implique « remis ».
     *
     * @return bool true si le message a changé
     */
    public function applyMetaStatus(string $status, Carbon $at, ?string $error = null): bool
    {
        match ($status) {
            'delivered' => $this->delivered_at ??= $at,
            'read' => [$this->delivered_at ??= $at, $this->read_at ??= $at],
            'failed' => $this->fill(['status' => 'failed', 'error_message' => $error ?? 'Échec signalé par WhatsApp']),
            default => null,
        };

        return $this->isDirty() && $this->save();
    }

    /**
     * Statut lisible le plus avancé : read, delivered, sent ou failed.
     */
    public function deliveryState(): string
    {
        return match (true) {
            $this->status !== 'sent' => $this->status,
            $this->read_at !== null => 'read',
            $this->delivered_at !== null => 'delivered',
            default => 'sent',
        };
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
