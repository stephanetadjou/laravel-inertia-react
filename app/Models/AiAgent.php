<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent IA d'une entreprise : répond aux messages WhatsApp entrants avec le
 * fournisseur choisi (cf. App\Services\AiAgents). La clé API de l'entreprise
 * est chiffrée et jamais renvoyée au navigateur.
 */
class AiAgent extends Model
{
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Claude (Anthropic)', 'models' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], 'key_hint' => 'sk-ant-…', 'console' => 'https://console.anthropic.com/settings/keys'],
        'openai' => ['label' => 'ChatGPT (OpenAI)', 'models' => ['gpt-4o', 'gpt-4o-mini'], 'key_hint' => 'sk-…', 'console' => 'https://platform.openai.com/api-keys'],
        'gemini' => ['label' => 'Gemini (Google)', 'models' => ['gemini-2.5-flash', 'gemini-2.5-pro'], 'key_hint' => 'AIza…', 'console' => 'https://aistudio.google.com/app/apikey'],
    ];

    public const TONES = [
        'chaleureux' => 'Chaleureux',
        'professionnel' => 'Professionnel',
        'decontracte' => 'Décontracté',
    ];

    public const SCHEDULES = [
        'always' => 'Tout le temps',
        'outside_hours' => 'Seulement hors horaires d’ouverture',
        'opening_hours' => 'Seulement pendant les horaires d’ouverture',
    ];

    protected $fillable = [
        'organization_id', 'name', 'role', 'tone', 'instructions',
        'provider', 'model', 'api_key',
        'use_business_info', 'knowledge',
        'forbidden_topics', 'handoff_message', 'max_replies_per_day',
        'store_ids', 'schedule', 'active',
        'replies_count', 'last_reply_at', 'last_error', 'last_error_at',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'use_business_info' => 'boolean',
            'store_ids' => 'array',
            'active' => 'boolean',
            'max_replies_per_day' => 'integer',
            'replies_count' => 'integer',
            'last_reply_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Sert cet établissement (aucun choisi = tous). */
    public function servesStore(int $storeId): bool
    {
        return empty($this->store_ids) || in_array($storeId, array_map('intval', $this->store_ids), true);
    }

    /** Clé masquée pour l'affichage (« sk-a…9f2c »). */
    public function maskedKey(): string
    {
        $key = (string) $this->api_key;

        return strlen($key) > 8 ? substr($key, 0, 4).'…'.substr($key, -4) : '••••';
    }
}
