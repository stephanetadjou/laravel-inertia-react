<?php

namespace App\Models;

use Database\Factories\MessageTemplateFactory;
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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public const DEFAULT_LANGUAGE = 'fr';

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
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->value('language') ?: self::DEFAULT_LANGUAGE;
    }
}
