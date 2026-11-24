<?php

namespace App\Support;

use App\Models\CatalogItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lecture d'une demande écrite (ou dictée) en français, sans IA :
 * « shooting famille samedi 10 h », « je voudrais décaler mon rdv à jeudi
 * 15h30 », « annuler mon rendez-vous de demain ». Donne l'intention, la
 * prestation la plus proche, le jour et l'heure trouvés.
 */
class BookingRequest
{
    public const BOOK = 'book';

    public const CANCEL = 'cancel';

    public const RESCHEDULE = 'reschedule';

    private const WEEKDAYS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 0];

    private const MONTHS = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
        'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12];

    /** Mots trop courants pour reconnaître une prestation. */
    private const IGNORED = ['pour', 'avec', 'prendre', 'avoir', 'faire', 'voudrais', 'veux', 'souhaite', 'rendez', 'vous', 'samedi', 'dimanche',
        'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'demain', 'heure', 'heures', 'matin', 'midi', 'soir', 'reserver', 'reservation',
        'merci', 'bonjour', 'prochain', 'semaine', 'possible', 'disponible', 'decaler', 'annuler', 'reporter', 'mon', 'votre'];

    public function __construct(
        public readonly ?string $intent,
        public readonly ?CatalogItem $service,
        public readonly ?Carbon $day,
        public readonly ?string $time,
    ) {}

    /** @param  Collection<int, CatalogItem>  $services  prestations réservables */
    public static function parse(string $text, Collection $services, ?Carbon $now = null): self
    {
        $now ??= now();
        $t = Str::of($text)->ascii()->lower()->replace("'", ' ')->squish()->value();

        $service = self::service($t, $services);
        $day = self::day($t, $now);
        $time = self::time($t);

        $intent = match (true) {
            (bool) preg_match('/\b(annul\w*|supprim\w*)\b/', $t) && self::aboutAppointment($t) => self::CANCEL,
            (bool) preg_match('/\b(decal\w*|report\w*|deplac\w*|change\w*|modifi\w*|repouss\w*|avancer)\b/', $t) && self::aboutAppointment($t) => self::RESCHEDULE,
            (bool) preg_match('/\b(rdv|rendez|reserv\w*|creneau\w*|booker)\b/', $t) => self::BOOK,
            $service !== null && ($day !== null || $time !== null) => self::BOOK,
            default => null,
        };

        return new self($intent, $service, $day, $time);
    }

    /** Jour et heure réunis, s'ils sont tous deux connus. */
    public function start(): ?Carbon
    {
        return $this->day && $this->time ? $this->day->copy()->setTimeFromTimeString($this->time) : null;
    }

    private static function aboutAppointment(string $t): bool
    {
        return (bool) preg_match('/\b(rdv|rendez|reserv\w*|seance|creneau|visite|cours|lecon|shooting|consultation|heure)\b/', $t);
    }

    /** Prestation dont le plus de mots (4 lettres et plus) se retrouvent dans le message. */
    private static function service(string $t, Collection $services): ?CatalogItem
    {
        $words = collect(preg_split('/[^a-z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => strlen($w) >= 4 && ! in_array($w, self::IGNORED, true))
            ->map(fn ($w) => preg_replace('/(s|x)$/', '', $w))
            ->unique();

        if ($words->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($services as $s) {
            $name = collect(preg_split('/[^a-z0-9]+/', Str::of($s->name)->ascii()->lower()->value(), -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($w) => preg_replace('/(s|x)$/', '', $w));
            $score = $words->filter(fn ($w) => $name->contains(fn ($n) => strlen($n) >= 4 && (str_starts_with($n, $w) || str_starts_with($w, $n))))->count();
            if ($score > $bestScore) {
                [$best, $bestScore] = [$s, $score];
            }
        }

        return $best;
    }

    private static function day(string $t, Carbon $now): ?Carbon
    {
        $today = $now->copy()->startOfDay();

        if (preg_match('/\bapres[ -]?demain\b/', $t)) {
            return $today->addDays(2);
        }
        if (preg_match('/\bdemain\b/', $t)) {
            return $today->addDay();
        }
        if (preg_match('/\b(aujourd ?hui|ce matin|ce soir|ce midi|cet apres[ -]?midi)\b/', $t)) {
            return $today;
        }

        // 28/11, 28-11, 28/11/2026
        if (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b/', $t, $m)) {
            $year = isset($m[3]) ? (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0) : $today->year;
            if (checkdate((int) $m[2], (int) $m[1], $year)) {
                $date = Carbon::create($year, (int) $m[2], (int) $m[1])->startOfDay();

                return ! isset($m[3]) && $date->lt($today) ? $date->addYear() : $date;
            }
        }

        // 28 novembre, le 3 decembre
        if (preg_match('/\b(\d{1,2}) ('.implode('|', array_keys(self::MONTHS)).')\b/', $t, $m) && checkdate(self::MONTHS[$m[2]], (int) $m[1], $today->year)) {
            $date = Carbon::create($today->year, self::MONTHS[$m[2]], (int) $m[1])->startOfDay();

            return $date->lt($today) ? $date->addYear() : $date;
        }

        // samedi, samedi prochain : le prochain jour de ce nom (aujourd'hui compris, sauf « prochain »).
        if (preg_match('/\b('.implode('|', array_keys(self::WEEKDAYS)).')\b( prochain)?/', $t, $m)) {
            $diff = (self::WEEKDAYS[$m[1]] - $today->dayOfWeek + 7) % 7;
            if (! empty($m[2]) && $diff === 0) {
                $diff = 7;
            }

            return $today->addDays($diff);
        }

        // le 28 (ce mois-ci, ou le mois prochain s'il est passé)
        if (preg_match('/\ble (\d{1,2})\b(?! ?h)/', $t, $m) && (int) $m[1] >= 1 && (int) $m[1] <= $today->daysInMonth) {
            $date = $today->copy()->day((int) $m[1]);

            return $date->lt($today) ? $date->addMonthNoOverflow() : $date;
        }

        return null;
    }

    private static function time(string $t): ?string
    {
        if (preg_match('/\bmidi\b/', $t) && ! preg_match('/apres[ -]?midi/', $t)) {
            return '12:00';
        }

        if (preg_match('/\b(\d{1,2}) ?(?:h|heures?|:)(?: ?(\d{2}))?\b/', $t, $m) && (int) $m[1] <= 23 && (int) ($m[2] ?? 0) < 60) {
            $hour = (int) $m[1];
            // « 3 h de l'après-midi », « 5h du soir »
            if ($hour < 12 && preg_match('/\b(apres[ -]?midi|du soir|ce soir)\b/', $t)) {
                $hour += 12;
            }

            return sprintf('%02d:%02d', $hour, (int) ($m[2] ?? 0));
        }

        return null;
    }
}
