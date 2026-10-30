<?php

namespace App\Models;

use App\Services\FamilyMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article vu dans les imports de ventes : un libellé (et un code s'il est
 * fourni), normalisé et dédoublonné, rattaché à une famille. Ce n'est pas un
 * catalogue : ni prix, ni stock, ni variante. Il sert à analyser les ventes
 * au niveau « article » et à ranger les articles dans les familles.
 */
class Article extends Model
{
    protected $fillable = [
        'organization_id',
        'match_key',
        'code',
        'label',
        'normalized_label',
        'category_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Clé de dédoublonnage : le code article s'il existe, sinon le libellé normalisé. */
    public static function matchKey(?string $code, ?string $label): ?string
    {
        $code = trim((string) $code);

        if ($code !== '') {
            return 'c:'.mb_strtoupper(mb_substr($code, 0, 100));
        }

        $normalized = FamilyMatcher::normalize($label);

        return $normalized !== '' ? 'l:'.mb_substr($normalized, 0, 180) : null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
