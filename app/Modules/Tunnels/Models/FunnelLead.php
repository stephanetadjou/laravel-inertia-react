<?php

namespace App\Modules\Tunnels\Models;

use App\Models\Customer;
use App\Modules\Location\Models\VehicleRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande reçue par un tunnel, avec son canal et l'avancement de sa séquence WhatsApp. */
class FunnelLead extends Model
{
    protected $fillable = ['funnel_id', 'customer_id', 'vehicle_request_id', 'source', 'step', 'stopped_at', 'stop_reason'];

    protected function casts(): array
    {
        return ['stopped_at' => 'datetime', 'step' => 'integer'];
    }

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VehicleRequest::class, 'vehicle_request_id');
    }
}
