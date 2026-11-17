<?php

namespace App\Modules\CarteDigitale\Models;

use App\Models\Organization;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Carte de visite digitale d'un collaborateur (page publique /c/{slug}). */
class DigitalCard extends Model
{
    /** Événements suivis : visite, scan du QR, clics, contact reçu. */
    public const EVENTS = ['view', 'scan', 'whatsapp', 'call', 'vcard', 'booking', 'contact', 'social'];

    /** Réseaux sociaux : clé => [libellé, adresse d'un @pseudo]. « link » = lien libre (URL seulement). */
    public const SOCIALS = [
        'facebook' => ['Facebook', 'https://www.facebook.com/'],
        'instagram' => ['Instagram', 'https://www.instagram.com/'],
        'tiktok' => ['TikTok', 'https://www.tiktok.com/@'],
        'linkedin' => ['LinkedIn', 'https://www.linkedin.com/in/'],
        'youtube' => ['YouTube', 'https://www.youtube.com/@'],
        'x' => ['X', 'https://x.com/'],
        'link' => ['Lien', null],
    ];

    /** Couleurs proposées (orange ClientlyX par défaut). */
    public const COLORS = ['#E8590C', '#2B1A3D', '#1D4ED8', '#15803D', '#9F1239', '#1C1917'];

    protected $fillable = [
        'organization_id', 'store_id', 'user_id', 'staff_member_id', 'slug', 'name', 'job_title', 'company',
        'phone_e164', 'whatsapp_e164', 'email', 'website', 'socials', 'address', 'bio', 'photo_path', 'color',
        'booking_enabled', 'is_active',
    ];

    protected function casts(): array
    {
        return ['booking_enabled' => 'boolean', 'is_active' => 'boolean', 'socials' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DigitalCardEvent::class);
    }

    public function record(string $type): void
    {
        if (in_array($type, self::EVENTS, true)) {
            $this->events()->create(['type' => $type]);
        }
    }

    /** Lien public court, unique sur toute la plateforme. */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'carte', 40, '');
        do {
            $slug = $base.'-'.Str::lower(Str::random(4));
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * Normalise un réseau saisi : URL complète, ou @pseudo converti en lien.
     * Retourne null si vide ; lève false si la saisie n'est pas valable.
     */
    public static function normalizeSocial(string $key, ?string $value): string|false|null
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : false;
        }
        // Adresse sans https:// (« facebook.com/page », « www.site.ga ») ; « jean.ndong » reste un pseudo.
        if (str_starts_with(strtolower($value), 'www.') || preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+/#i', $value) || ($key === 'link' && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+$#i', $value))) {
            return filter_var('https://'.$value, FILTER_VALIDATE_URL) ? 'https://'.$value : false;
        }
        $base = self::SOCIALS[$key][1] ?? null;
        $handle = ltrim($value, '@');

        return $base !== null && preg_match('/^[A-Za-z0-9._-]{1,60}$/', $handle) ? $base.$handle : false;
    }

    /** Réseaux renseignés, dans l'ordre d'affichage. @return list<array{key: string, label: string, url: string}> */
    public function socialLinks(): array
    {
        return collect(self::SOCIALS)->map(fn ($s, $key) => ['key' => $key, 'label' => $s[0], 'url' => $this->socials[$key] ?? null])
            ->filter(fn ($s) => filled($s['url']))->values()->all();
    }

    public function publicUrl(): string
    {
        return url("/c/{$this->slug}");
    }

    /** Carte au format vCard 3.0 (« Enregistrer le contact »). */
    public function vcard(): string
    {
        $esc = fn (?string $v) => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], (string) $v);
        $parts = preg_split('/\s+/', trim($this->name), 2);
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'N:'.$esc($parts[1] ?? '').';'.$esc($parts[0]).';;;',
            'FN:'.$esc($this->name),
        ];
        if ($this->company) {
            $lines[] = 'ORG:'.$esc($this->company);
        }
        if ($this->job_title) {
            $lines[] = 'TITLE:'.$esc($this->job_title);
        }
        if ($this->phone_e164) {
            $lines[] = 'TEL;TYPE=CELL:+'.$this->phone_e164;
        }
        if ($this->whatsapp_e164 && $this->whatsapp_e164 !== $this->phone_e164) {
            $lines[] = 'TEL;TYPE=CELL:+'.$this->whatsapp_e164;
        }
        if ($this->email) {
            $lines[] = 'EMAIL;TYPE=INTERNET:'.$esc($this->email);
        }
        if ($this->website) {
            $lines[] = 'URL:'.$esc($this->website);
        }
        foreach ($this->socialLinks() as $social) {
            $lines[] = 'X-SOCIALPROFILE;TYPE='.$social['key'].':'.$esc($social['url']);
            $lines[] = 'URL;TYPE='.$social['label'].':'.$esc($social['url']);
        }
        $lines[] = 'NOTE:'.$esc('Carte digitale : '.$this->publicUrl());
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines)."\r\n";
    }
}
