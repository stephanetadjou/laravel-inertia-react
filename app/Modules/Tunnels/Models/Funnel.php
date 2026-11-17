<?php

namespace App\Modules\Tunnels\Models;

use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tunnel de vente : page d'offre publique /t/{entreprise}/{slug}. */
class Funnel extends Model
{
    /** Champs facultatifs du formulaire (nom et WhatsApp sont toujours demandés). */
    public const FIELDS = ['date' => 'Date souhaitée', 'vehicles_count' => 'Nombre de véhicules', 'vehicle' => 'Véhicule', 'message' => 'Message'];

    public const SOURCES = ['facebook' => 'Facebook', 'statut' => 'Statut WhatsApp', 'whatsapp' => 'WhatsApp', 'qr' => 'QR code', 'carte' => 'Carte digitale', 'campagne' => 'Campagne', 'direct' => 'Direct'];

    protected $fillable = ['organization_id', 'store_id', 'slug', 'template', 'title', 'offer', 'benefits', 'reviews', 'fields', 'choice', 'ends_at', 'seats', 'image_path', 'message', 'budget', 'visits', 'is_active'];

    protected function casts(): array
    {
        return ['benefits' => 'array', 'reviews' => 'array', 'fields' => 'array', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'seats' => 'integer', 'budget' => 'integer', 'visits' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(FunnelLead::class);
    }

    /** Ouvert : actif et avant la date de fin. */
    public function isOpen(): bool
    {
        return $this->is_active && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function seatsLeft(): ?int
    {
        return $this->seats === null ? null : max(0, $this->seats - $this->leads()->count());
    }

    public function publicUrl(?string $source = null): string
    {
        return url("/t/{$this->organization->slug}/{$this->slug}").($source ? "?src={$source}" : '');
    }
}
