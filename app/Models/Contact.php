<?php

namespace App\Models;

use App\Services\CustomerLifecycle;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id',
        'customer_id',
        'is_vip',
        'vip_since',
        'name',
        'phone_e164',
        'consent_status',
        'welcome_message_sent_at',
        'birthdate',
        'gender',
        'custom_attributes',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'welcome_message_sent_at' => 'datetime',
            'is_vip' => 'boolean',
            'vip_since' => 'datetime',
            'custom_attributes' => 'array',
        ];
    }

    /**
     * Tout contact est rattaché à la fiche client unique de la personne
     * (cf. App\Services\CustomerLifecycle), quel que soit le chemin de
     * création : formulaire, import de contacts ou d'achats, campagne.
     */
    protected static function booted(): void
    {
        static::creating(function (Contact $contact) {
            if ($contact->customer_id === null) {
                app(CustomerLifecycle::class)->linkContact($contact);
            }
        });

        // Nouveau numéro : soit la personne change de numéro (la fiche et ses
        // autres contacts suivent), soit le numéro est celui d'une autre fiche
        // et le contact la rejoint. L'ancien numéro est toujours conservé.
        static::updating(function (Contact $contact) {
            if ($contact->isDirty('phone_e164') && $contact->customer) {
                app(CustomerLifecycle::class)->contactPhoneChanging($contact);
            }
        });

        static::updated(function (Contact $contact) {
            $lifecycle = app(CustomerLifecycle::class);

            if ($contact->wasChanged('customer_id')) {
                $lifecycle->contactMoved($contact->getOriginal('customer_id'), $contact->customer_id);
            }

            if ($contact->wasChanged(['name', 'gender', 'birthdate']) && $contact->customer_id) {
                $lifecycle->syncIdentity(Customer::findOrFail($contact->customer_id), $contact);
            }
        });

        // Le consentement fait passer de Contact à Prospect.
        static::saved(function (Contact $contact) {
            if ($contact->wasRecentlyCreated || $contact->wasChanged('consent_status')) {
                app(CustomerLifecycle::class)->touch($contact->customer_id);
            }
        });

        // Une fiche sans plus aucun contact disparaît avec son dernier contact.
        static::deleted(function (Contact $contact) {
            if ($contact->customer_id === null) {
                return;
            }

            if (Contact::where('customer_id', $contact->customer_id)->exists()) {
                app(CustomerLifecycle::class)->touch($contact->customer_id);
            } else {
                Customer::whereKey($contact->customer_id)->delete();
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function campaignMessages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }
}
