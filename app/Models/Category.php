<?php

namespace App\Models;

use App\Services\FamilyMatcher;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'keywords',
    ];

    /** Nom ou mots-clés modifiés : la reconnaissance des familles est recalculée. */
    protected static function booted(): void
    {
        $forget = fn (Category $category) => FamilyMatcher::forget($category->organization_id);
        static::saved($forget);
        static::deleted($forget);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CategoryRule::class);
    }

    /** @return list<string> mots-clés saisis, séparés par des virgules */
    public function keywordList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->keywords)), fn ($k) => $k !== ''));
    }
}
