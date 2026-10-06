<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInboundMessage extends Model
{
    protected $table = 'whatsapp_inbound_messages';

    protected $fillable = [
        'store_id',
        'contact_id',
        'wa_message_id',
        'from_phone',
        'type',
        'body',
        'received_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
