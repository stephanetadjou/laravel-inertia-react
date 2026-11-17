<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ressource réservée avec un rendez-vous (module « ressources ») : véhicule
 * d'auto-école, salle, matériel. Une ressource ne peut pas servir à deux
 * rendez-vous qui se chevauchent.
 *
 * Module « vehicules » (vente et location) : un véhicule de la flotte, avec
 * sa catégorie, ses tarifs, son statut, son prix de vente et ses échéances ;
 * il est occupé par un contrat de location (Rental).
 */
class BookableResource extends Model
{
    public const KINDS = [
        'vehicule' => 'Véhicule',
        'salle' => 'Salle',
        'materiel' => 'Matériel',
    ];

    public const CATEGORIES = [
        'citadine' => 'Citadine',
        'berline' => 'Berline',
        'suv' => 'SUV',
        '4x4' => '4x4 / pick-up',
        'utilitaire' => 'Utilitaire',
        'minibus' => 'Minibus',
    ];

    /** Disponible à la location, en entretien, à vendre ou vendu. */
    public const STATUSES = [
        'available' => 'En location',
        'maintenance' => 'En entretien',
        'for_sale' => 'À vendre',
        'sold' => 'Vendu',
    ];

    protected $fillable = [
        'organization_id',
        'store_id',
        'name',
        'kind',
        'reference',
        'active',
        'category',
        'mileage',
        'price_day',
        'price_week',
        'price_month',
        'status',
        'sale_price',
        'for_sale_since',
        'sold_on',
        'service_due_km',
        'service_due_on',
        'insurance_until',
        'inspection_until',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'mileage' => 'integer',
            'price_day' => 'integer',
            'price_week' => 'integer',
            'price_month' => 'integer',
            'sale_price' => 'integer',
            'service_due_km' => 'integer',
            'for_sale_since' => 'date',
            'sold_on' => 'date',
            'service_due_on' => 'date',
            'insurance_until' => 'date',
            'inspection_until' => 'date',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class, 'resource_id');
    }

    /** Véhicules de la flotte de location (ni vendus, ni désactivés). */
    public function scopeFleet(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId)
            ->where('kind', 'vehicule')
            ->where('active', true)
            ->where('status', '!=', 'sold');
    }

    public function label(): string
    {
        return $this->name.($this->reference ? " ({$this->reference})" : '');
    }

    /**
     * Échéances proches ou dépassées : entretien (date ou kilométrage),
     * assurance, visite technique.
     *
     * @return list<array{label: string, due: string, late: bool}>
     */
    public function deadlines(int $withinDays = 30): array
    {
        $deadlines = [];
        $limit = today()->addDays($withinDays);

        foreach (['service_due_on' => 'Entretien', 'insurance_until' => 'Assurance', 'inspection_until' => 'Visite technique'] as $field => $label) {
            if ($this->{$field} !== null && $this->{$field}->lte($limit)) {
                $deadlines[] = ['label' => $label, 'due' => $this->{$field}->toDateString(), 'late' => $this->{$field}->lt(today())];
            }
        }

        if ($this->service_due_km !== null && $this->mileage !== null && $this->mileage >= $this->service_due_km - 500) {
            $deadlines[] = ['label' => 'Entretien', 'due' => number_format($this->service_due_km, 0, ',', ' ').' km', 'late' => $this->mileage >= $this->service_due_km];
        }

        return $deadlines;
    }
}
