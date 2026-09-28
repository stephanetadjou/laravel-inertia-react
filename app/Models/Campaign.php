<?php

namespace App\Models;

use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'message_type',
        'template_name',
        'text_body',
        'segment_filters',
        'status',
        'sent_count',
        'failed_count',
    ];

    protected function casts(): array
    {
        return [
            'segment_filters' => 'array',
        ];
    }

    /**
     * Une campagne peut cibler une ou plusieurs boutiques. Chaque message est
     * néanmoins toujours envoyé via le numéro WhatsApp de la boutique du
     * contact destinataire (cf. SendCampaignJob).
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'campaign_store');
    }

    public function campaignMessages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }
}
