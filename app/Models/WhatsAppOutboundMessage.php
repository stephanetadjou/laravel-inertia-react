<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Message de session ou réponse automatique envoyé depuis NENTIX (hors campagnes). */
class WhatsAppOutboundMessage extends Model
{
    protected $table = 'whatsapp_outbound_messages';

    protected $fillable = ['store_id', 'contact_id', 'user_id', 'type', 'origin', 'body', 'payload', 'wa_message_id', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
