<?php

namespace App\Modules\Depenses\Models;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    /** Catégories proposées aux entreprises de services. */
    public const CATEGORIES = [
        'loyer' => 'Loyer',
        'salaires' => 'Salaires et primes',
        'fournitures' => 'Produits et fournitures',
        'energie' => 'Électricité et eau',
        'telecom' => 'Internet et téléphone',
        'transport' => 'Transport et carburant',
        'marketing' => 'Publicité et marketing',
        'entretien' => 'Entretien et réparations',
        'materiel' => 'Matériel',
        'impots' => 'Impôts et taxes',
        'banque' => 'Frais bancaires et Mobile Money',
        'autre' => 'Autre',
    ];

    protected $fillable = ['organization_id', 'store_id', 'spent_on', 'amount', 'category', 'label', 'supplier', 'method', 'receipt_path', 'user_id', 'resource_id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount' => 'integer'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
