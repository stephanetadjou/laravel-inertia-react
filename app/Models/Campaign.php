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
        'template_language',
        'template_variables',
        'header_image_url',
        'text_body',
        'segment_filters',
        'status',
        'sent_count',
        'delivered_count',
        'read_count',
        'failed_count',
    ];

    protected function casts(): array
    {
        return [
            'segment_filters' => 'array',
            'template_variables' => 'array',
        ];
    }

    /** Champs du contact utilisables dans les variables du modèle. */
    public const VARIABLE_TOKENS = ['{prenom}', '{nom}', '{boutique}'];

    /**
     * Valeurs des variables {{1}}, {{2}}… pour un contact : {prenom}, {nom}
     * et {boutique} sont remplacés ; Meta refusant un paramètre vide, un champ
     * inconnu devient « Client ».
     *
     * @return array<int, string>
     */
    public function variablesFor(Contact $contact): array
    {
        $name = trim((string) $contact->name);
        $replacements = [
            '{prenom}' => $name !== '' ? strtok($name, ' ') : 'Client',
            '{nom}' => $name !== '' ? $name : 'Client',
            '{boutique}' => (string) $contact->store?->name,
        ];

        return array_map(
            fn ($value) => trim(strtr((string) $value, $replacements)) ?: 'Client',
            array_values($this->template_variables ?? []),
        );
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

    /**
     * Recalcule les compteurs depuis les messages (après un statut Meta) :
     * un message lu compte aussi comme remis, un échec signalé après coup
     * quitte les envoyés.
     */
    public function refreshDeliveryCounts(): void
    {
        $counts = $this->campaignMessages()
            ->selectRaw("sum(case when status = 'sent' then 1 else 0 end) as sent")
            ->selectRaw("sum(case when status = 'sent' and delivered_at is not null then 1 else 0 end) as delivered")
            ->selectRaw("sum(case when status = 'sent' and read_at is not null then 1 else 0 end) as read_total")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->toBase()
            ->first();

        $this->update([
            'sent_count' => (int) $counts->sent,
            'delivered_count' => (int) $counts->delivered,
            'read_count' => (int) $counts->read_total,
            'failed_count' => (int) $counts->failed,
        ]);
    }
}
