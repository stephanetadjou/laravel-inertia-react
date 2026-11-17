<?php

namespace App\Modules\Location\Models;

use App\Models\BookableResource;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use App\Modules\Facturation\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'un client (location ou achat) : Nouvelle → Devis envoyé →
 * Réservée / Vendue, ou Perdue avec une raison obligatoire.
 */
class VehicleRequest extends Model
{
    public const STATUSES = ['new' => 'Nouvelle', 'quoted' => 'Devis envoyé', 'won' => 'Réservée / vendue', 'lost' => 'Perdue'];

    public const NEEDS = ['rent' => 'Location', 'ride' => 'Course / transfert', 'buy' => 'Achat'];

    public const SOURCES = ['whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'instagram' => 'Instagram', 'phone' => 'Téléphone', 'agency' => 'Agence', 'referral' => 'Recommandation', 'online' => 'Lien en ligne', 'other' => 'Autre'];

    /** Pour qui ou pour quoi : sert aux segments de relance (campagnes). */
    public const PURPOSES = ['personal' => 'Usage personnel', 'event' => 'Événement (mariage, deuil, fête)', 'business' => 'Entreprise / mission', 'diaspora' => 'Diaspora en séjour', 'tourism' => 'Tourisme'];

    public const LOST_REASONS = [
        'price' => 'Trop cher',
        'unavailable' => 'Pas de véhicule disponible',
        'no_answer' => 'Sans réponse',
        'competitor' => 'Parti chez un concurrent',
        'cancelled' => 'Projet annulé',
        'other' => 'Autre raison',
    ];

    protected $fillable = [
        'organization_id', 'store_id', 'customer_id', 'need', 'purpose', 'category', 'resource_id', 'starts_at', 'ends_at', 'with_driver', 'budget',
        'source', 'status', 'lost_reason', 'lost_note', 'quote_id', 'rental_id', 'vehicle_sale_id', 'quoted_at', 'reminded_24_at', 'reminded_72_at',
        'closed_at', 'notes', 'created_by', 'ride_from', 'ride_to', 'ride_passengers', 'ride_luggage', 'flight_number',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'quoted_at' => 'datetime',
            'reminded_24_at' => 'datetime',
            'reminded_72_at' => 'datetime',
            'closed_at' => 'datetime',
            'with_driver' => 'boolean',
            'budget' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(BookableResource::class, 'resource_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'quote_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'rental_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(VehicleSale::class, 'vehicle_sale_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['new', 'quoted'], true);
    }

    /** Résumé du besoin en une ligne (« Location SUV du 12 au 15 déc., avec chauffeur »). */
    public function summary(): string
    {
        if ($this->need === 'ride') {
            return trim('Course '.($this->ride_from ?: 'Aéroport').' → '.($this->ride_to ?: '?')
                .($this->starts_at ? ' le '.$this->starts_at->locale('fr')->translatedFormat('j M à H\hi') : '')
                .($this->flight_number ? " (vol {$this->flight_number})" : ''));
        }

        $what = self::NEEDS[$this->need] ?? 'Demande';
        $vehicle = $this->vehicle?->name ?? (BookableResource::CATEGORIES[$this->category] ?? null);
        $dates = $this->starts_at ? ' du '.$this->starts_at->locale('fr')->translatedFormat('j M').($this->ends_at ? ' au '.$this->ends_at->locale('fr')->translatedFormat('j M') : '') : '';

        return trim($what.($vehicle ? " {$vehicle}" : '').$dates.($this->need === 'rent' && $this->with_driver ? ', avec chauffeur' : ''));
    }
}
