<?php

namespace App\Modules\Location\Models;

use App\Models\BookableResource;
use App\Models\Customer;
use App\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Acheteur suivi pour un véhicule à vendre : intéressé → essai → offre → réservé → vendu (ou perdu). */
class VehicleSale extends Model
{
    public const STATUSES = ['interested' => 'Intéressé', 'test_drive' => 'Essai programmé', 'offer' => 'Offre faite', 'reserved' => 'Réservé', 'sold' => 'Vendu', 'lost' => 'Perdu'];

    public const OPEN = ['interested', 'test_drive', 'offer', 'reserved'];

    protected $fillable = [
        'organization_id', 'store_id', 'resource_id', 'customer_id', 'status', 'test_drive_at', 'offer_amount', 'trade_in_label', 'trade_in_value',
        'installments_count', 'payment_schedule_id', 'purchase_id', 'lost_reason', 'notes', 'sold_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'test_drive_at' => 'datetime',
            'sold_at' => 'datetime',
            'offer_amount' => 'integer',
            'trade_in_value' => 'integer',
            'installments_count' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(BookableResource::class, 'resource_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }
}
