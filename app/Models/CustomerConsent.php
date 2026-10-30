<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consentement d'un client par canal (whatsapp, sms, email) et finalité
 * (marketing, service). Pour WhatsApp, un consentement par point de vente :
 * chaque point de vente a son propre numéro. Tenu à jour depuis le
 * consentement des contacts (contacts.consent_status reste la référence des
 * envois de campagne).
 */
class CustomerConsent extends Model
{
    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-mail'];

    public const PURPOSES = ['marketing' => 'Marketing', 'service' => 'Service'];

    protected $fillable = ['customer_id', 'store_id', 'channel', 'purpose', 'status', 'consent_date', 'source'];

    protected function casts(): array
    {
        return ['consent_date' => 'datetime'];
    }

    /** Reporte le consentement WhatsApp marketing d'un contact sur sa fiche. */
    public static function syncFromContact(Contact $contact): void
    {
        if ($contact->customer_id === null) {
            return;
        }

        if (($contact->consent_status ?? 'unknown') === 'unknown') {
            self::where('customer_id', $contact->customer_id)->where('store_id', $contact->store_id)
                ->where('channel', 'whatsapp')->where('purpose', 'marketing')->delete();

            return;
        }

        self::updateOrCreate(
            ['customer_id' => $contact->customer_id, 'store_id' => $contact->store_id, 'channel' => 'whatsapp', 'purpose' => 'marketing'],
            ['status' => $contact->consent_status, 'consent_date' => $contact->consent_at ?? now(), 'source' => $contact->consent_source],
        );
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
