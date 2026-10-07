<?php

namespace App\Models;

use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'language',
        'category',
        'description',
        'is_active',
        'status',
        'meta_template_id',
        'body_text',
        'body_variables',
        'header_format',
        'components',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'body_variables' => 'integer',
            'components' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public const DEFAULT_LANGUAGE = 'fr';

    public const STATUS_APPROVED = 'APPROVED';

    /**
     * Modèles proposables à l'envoi : actifs, et approuvés par Meta quand ils
     * viennent de la synchronisation (statut nul = saisi à la main).
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', self::STATUS_APPROVED));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Code langue à transmettre à Meta pour un template de l'organisation :
     * Meta rejette l'envoi si la langue ne correspond pas à celle approuvée.
     * Repli sur le français si le template n'est pas (ou plus) référencé.
     */
    public static function languageFor(int $organizationId, string $name): string
    {
        return self::where('organization_id', $organizationId)
            ->where('name', $name)
            // La langue d'une version approuvée par Meta avant toute autre.
            ->orderByRaw("case when status = 'APPROVED' then 0 when status is null then 1 else 2 end")
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->value('language') ?: self::DEFAULT_LANGUAGE;
    }
}
