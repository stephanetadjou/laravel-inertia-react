<?php

namespace Database\Seeders;

use App\Http\Controllers\ScheduleSettingsController;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Closure;
use App\Models\Contact;
use App\Models\CustomerFollowUp;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTransaction;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Entreprise pilote fictive « Institut Élégance » (beauté et soins, 2
 * instituts : Libreville Louis et Owendo), pour les tests, les
 * démonstrations et le guide d'utilisation. Rejouable : l'entreprise
 * précédente du même nom est supprimée puis recréée. Données fictives,
 * générées de façon déterministe autour de la date du jour.
 *
 * Comptes : dg@institut-elegance.test (direction, accès complet) et
 * owendo@institut-elegance.test (responsable de l'institut d'Owendo).
 * Mot de passe : variable DEMO_PASSWORD, sinon « Pilote-Elegance-2026 ».
 *
 *   php artisan demo:pilot
 */
class PilotCompanySeeder extends Seeder
{
    public const SLUG = 'institut-elegance';

    public const DEFAULT_PASSWORD = 'Pilote-Elegance-2026';

    /** Prénoms et noms fictifs courants au Gabon. */
    private const FIRST_NAMES_F = ['Estelle', 'Patricia', 'Laure', 'Sylvie', 'Diane', 'Nadège', 'Carine', 'Joëlle', 'Aïcha', 'Prisca', 'Murielle', 'Vanessa', 'Gisèle', 'Tatiana', 'Aurélie', 'Nathalie', 'Sandrine', 'Christelle', 'Ornella', 'Larissa', 'Mélanie', 'Grâce', 'Rosine', 'Yolande', 'Cynthia', 'Jessica', 'Linda', 'Ruth', 'Pamela', 'Mireille', 'Clarisse', 'Edwige'];

    private const FIRST_NAMES_M = ['Brice', 'Fabrice', 'Davy', 'Yannick', 'Romuald', 'Loïc', 'Franck', 'Olivier', 'Wilfried', 'Jordan', 'Arsène', 'Edgard', 'Hervé', 'Serge'];

    private const LAST_NAMES = ['Ndong', 'Mboumba', 'Bivigou', 'Engone', 'Koumba', 'Obame', 'Mintsa', 'Nzé', 'Moussavou', 'Ondo', 'Makaya', 'Ovono', 'Mba', 'Ella', 'Nguema', 'Akendengué', 'Bongo', 'Boussougou', 'Ibinga', 'Essono', 'Nkoghe', 'Mengue', 'Owono', 'Nzamba', 'Ekomi', 'Mabika', 'Ndjembi', 'Oyane'];

    /** Profils de clients : rythme habituel (jours), dernière visite, panier. */
    private const PROFILES = [
        // [part, rythme min, rythme max, dernière visite il y a (min, max), visites max, produit acheté (proba)]
        'champion' => [0.14, 12, 20, 1, 10, 26, 0.6],
        'regular' => [0.30, 20, 35, 2, 25, 14, 0.35],
        'at_risk' => [0.12, 18, 30, 45, 75, 10, 0.3],
        'occasional' => [0.14, 55, 90, 10, 80, 5, 0.2],
        'churned' => [0.12, 30, 60, 160, 260, 6, 0.2],
        'new' => [0.10, 0, 0, 2, 25, 1, 0.4],
        'prospect' => [0.08, 0, 0, 0, 0, 0, 0],
    ];

    private int $phoneSeq = 0;

    public function run(): void
    {
        mt_srand(2026);
        $today = today();
        $password = env('DEMO_PASSWORD', self::DEFAULT_PASSWORD);

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Institut Élégance',
            'slug' => self::SLUG,
            'whatsapp_api_enabled' => false,
            'legal_name' => 'Institut Élégance SARL (fictive)',
            'privacy_email' => 'contact@institut-elegance.test',
            'privacy_phone' => '+241 77 00 01 01',
            'privacy_address' => 'Quartier Louis, Libreville',
            'booking_settings' => ['enabled' => true, 'auto_confirm' => false, 'slot_step' => 30, 'min_notice_minutes' => 120, 'horizon_days' => 30,
                'welcome' => 'Bienvenue chez Institut Élégance : réservez votre coiffure, votre soin ou votre manucure en une minute.'],
            'loyalty_settings' => ['enabled' => true, 'fcfa_per_point' => 1000, 'starts_on' => $today->copy()->subDays(200)->toDateString(),
                'tiers' => [['name' => 'Bronze', 'min_points' => 0], ['name' => 'Argent', 'min_points' => 250], ['name' => 'Or', 'min_points' => 600]]],
            'report_settings' => ['enabled' => true, 'recipients' => ['dg@institut-elegance.test'], 'send_at' => '21:00', 'monthly_target' => 3500000],
        ]);
        Role::seedDefaultsFor($organization);

        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Institut Élégance']);
        $louis = Store::create([
            'organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Libreville, Louis',
            'booking_hours' => [1 => ['open' => '08:30', 'close' => '19:00'], 2 => ['open' => '08:30', 'close' => '19:00'], 3 => ['open' => '08:30', 'close' => '19:00'],
                4 => ['open' => '08:30', 'close' => '19:00'], 5 => ['open' => '08:30', 'close' => '19:00'], 6 => ['open' => '08:00', 'close' => '19:00'], 7 => null],
            'booking_capacity' => 3, 'booking_phone' => '24177000101',
        ]);
        $owendo = Store::create([
            'organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Owendo',
            'booking_hours' => [1 => null, 2 => ['open' => '09:00', 'close' => '18:00'], 3 => ['open' => '09:00', 'close' => '18:00'], 4 => ['open' => '09:00', 'close' => '18:00'],
                5 => ['open' => '09:00', 'close' => '18:00'], 6 => ['open' => '09:00', 'close' => '18:00'], 7 => null],
            'booking_break' => ['start' => '12:30', 'end' => '13:30'],
            'booking_capacity' => 2, 'booking_phone' => '24166000202',
        ]);

        $role = fn (string $name) => Role::where('organization_id', $organization->id)->where('name', $name)->value('id');
        User::create(['name' => 'Nadia Mboumba (DG)', 'email' => 'dg@institut-elegance.test', 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $role('Super administrateur')]);
        User::create(['name' => 'Sandrine Engone', 'email' => 'owendo@institut-elegance.test', 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $role('Responsable de boutique'), 'store_id' => $owendo->id]);

        // Catalogue.
        $categories = collect(['Coiffure', 'Soins du visage', 'Ongles', 'Bien-être', 'Produits'])
            ->mapWithKeys(fn ($name) => [$name => Category::create(['organization_id' => $organization->id, 'name' => $name])->id]);
        $service = fn (string $name, int $price, int $minutes, string $category) => CatalogItem::create([
            'organization_id' => $organization->id, 'type' => 'service', 'name' => $name, 'price' => $price, 'duration_minutes' => $minutes, 'category_id' => $categories[$category],
        ]);
        $product = fn (string $name, int $price) => CatalogItem::create([
            'organization_id' => $organization->id, 'type' => 'product', 'name' => $name, 'price' => $price, 'category_id' => $categories['Produits'], 'bookable_online' => false,
        ]);
        $services = [
            'tresses' => $service('Tresses (box braids)', 21000, 180, 'Coiffure'),
            'coupe' => $service('Coupe et brushing', 12000, 60, 'Coiffure'),
            'defrisage' => $service('Défrisage', 15000, 90, 'Coiffure'),
            'homme' => $service('Coupe homme', 6000, 30, 'Coiffure'),
            'visage' => $service('Soin du visage', 25000, 60, 'Soins du visage'),
            'massage' => $service('Massage 60 min', 30000, 60, 'Bien-être'),
            'manucure' => $service('Manucure gel', 12000, 45, 'Ongles'),
            'pedicure' => $service('Pédicure', 15000, 45, 'Ongles'),
        ];
        $products = [
            $product('Huile capillaire', 6500),
            $product('Crème hydratante', 9500),
            $product('Sérum visage', 18000),
            $product('Kit manucure', 8000),
        ];

        // Intervenants.
        $staff = collect([
            ['Grâce M.', $louis, '#0E7BD6', ['tresses', 'coupe', 'defrisage', 'homme']],
            ['Mireille O.', $louis, '#00C896', ['visage', 'massage']],
            ['Pamela N.', $louis, '#7C3AED', ['manucure', 'pedicure']],
            ['Sandrine E.', $owendo, '#F59E0B', ['tresses', 'coupe', 'defrisage', 'homme', 'visage']],
            ['Ruth A.', $owendo, '#EC4899', ['manucure', 'pedicure', 'massage']],
        ])->map(function ($s) use ($organization, $services) {
            $member = StaffMember::create(['organization_id' => $organization->id, 'store_id' => $s[1]->id, 'name' => $s[0], 'color' => $s[2]]);
            $member->services()->sync(collect($s[3])->map(fn ($k) => $services[$k]->id)->all());

            return ['model' => $member, 'services' => $s[3]];
        });

        // Fermetures : jours fériés du Gabon (année en cours et suivante), congés d'Owendo.
        foreach ([$today->year, $today->year + 1] as $year) {
            foreach (ScheduleSettingsController::gabonHolidays($year) as $date => $label) {
                Closure::create(['organization_id' => $organization->id, 'store_id' => null, 'label' => $label, 'starts_on' => $date, 'ends_on' => $date]);
            }
        }
        Closure::create(['organization_id' => $organization->id, 'store_id' => $owendo->id, 'label' => 'Congés de fin d’année', 'starts_on' => "{$today->year}-12-26", 'ends_on' => "{$today->year}-12-31"]);

        // Récompenses.
        $rewards = collect([['Manucure gel offerte', 150], ['-10 % sur la prochaine visite', 250], ['Soin du visage offert', 400]])
            ->map(fn ($r) => LoyaltyReward::create(['organization_id' => $organization->id, 'name' => $r[0], 'points_cost' => $r[1]]));

        $customers = CustomerLifecycle::batch(fn () => $this->customers($organization, [$louis, $owendo], $services, $products, $staff, $today));

        // Hier : une journée pleine (rapport du dirigeant) ; puis rendez-vous à venir.
        CustomerLifecycle::batch(fn () => $this->yesterday($organization, $customers, [$louis, $owendo], $services, $products, $staff, $today));
        $this->upcoming($customers, [$louis, $owendo], $services, $staff, $today);

        // Échanges de récompenses et relances.
        $this->loyaltyAndFollowUps($organization, $customers, $rewards, $today);

        app(CustomerLifecycle::class)->refreshOrganization($organization);
        app(CustomerScoring::class)->refreshOrganization($organization);
    }

    /**
     * Clients, contacts, historique de visites (rendez-vous honorés et ventes à lignes).
     *
     * @return Collection<int, array{contact: Contact, profile: string, store: Store}>
     */
    private function customers(Organization $organization, array $stores, array $services, array $products, Collection $staff, Carbon $today): Collection
    {
        $people = collect();
        $total = 150;

        foreach (self::PROFILES as $profile => $def) {
            $count = (int) round($total * $def[0]);

            for ($i = 0; $i < $count; $i++) {
                $female = mt_rand(1, 100) <= 82;
                $first = $female ? self::FIRST_NAMES_F[mt_rand(0, count(self::FIRST_NAMES_F) - 1)] : self::FIRST_NAMES_M[mt_rand(0, count(self::FIRST_NAMES_M) - 1)];
                $name = $first.' '.self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];
                $store = mt_rand(1, 100) <= 62 ? $stores[0] : $stores[1];
                $consent = match (true) {
                    mt_rand(1, 100) <= 64 => 'opt_in',
                    mt_rand(1, 100) <= 92 => 'unknown',
                    default => 'opt_out',
                };
                $birthdate = mt_rand(1, 100) <= 55 ? $today->copy()->subYears(mt_rand(19, 58))->addDays(mt_rand(-180, 180)) : null;

                // Quelques anniversaires cette semaine.
                if ($people->count() % 37 === 5) {
                    $birthdate = $today->copy()->subYears(mt_rand(22, 45))->addDays(mt_rand(1, 5));
                }

                $contact = Contact::create([
                    'store_id' => $store->id,
                    'phone_e164' => $this->phone(),
                    'name' => $name,
                    'gender' => $female ? 'F' : 'H',
                    'birthdate' => $birthdate?->toDateString(),
                    'consent_status' => $consent,
                    'consent_at' => $consent === 'unknown' ? null : $today->copy()->subDays(mt_rand(30, 300)),
                    'consent_source' => $consent === 'unknown' ? null : 'import',
                ]);
                $contact->forceFill(['created_at' => $today->copy()->subDays($profile === 'new' ? mt_rand(1, 28) : mt_rand(60, 400))])->saveQuietly();
                $contact->customer?->forceFill(['created_at' => $contact->created_at])->saveQuietly();

                $people->push(['contact' => $contact, 'profile' => $profile, 'store' => $store]);
                $this->history($contact, $store, $profile, $def, $services, $products, $staff, $today);
            }
        }

        return $people;
    }

    /** Visites passées d'un client selon son profil. */
    private function history(Contact $contact, Store $store, string $profile, array $def, array $services, array $products, Collection $staff, Carbon $today): void
    {
        [, $minRhythm, $maxRhythm, $lastMin, $lastMax, $maxVisits, $productRate] = $def;

        if ($profile === 'prospect') {
            return;
        }

        $favorite = $this->favoriteServices($contact);
        $rhythm = max(7, mt_rand($minRhythm, max($minRhythm, $maxRhythm)));
        $day = $today->copy()->subDays(mt_rand($lastMin, $lastMax));
        $visits = $profile === 'new' ? 1 : mt_rand(max(2, (int) ($maxVisits / 2)), max(2, $maxVisits));

        for ($v = 0; $v < $visits && $day->gte($today->copy()->subDays(365)); $v++) {
            // Pas de visite un jour de fermeture de l'institut.
            while (($store->bookingHours()[$day->dayOfWeekIso] ?? null) === null) {
                $day->subDay();
            }

            $this->visit($contact, $store, $day->copy(), $services[$favorite[mt_rand(0, count($favorite) - 1)]], $productRate, $products, $staff, $today);
            $day->subDays($rhythm + mt_rand(-3, 4));
        }
    }

    /** Une visite : rendez-vous honoré (60 derniers jours) et vente à lignes. */
    private function visit(Contact $contact, Store $store, Carbon $day, CatalogItem $service, float $productRate, array $products, Collection $staff, Carbon $today, ?Carbon $at = null, ?StaffMember $member = null): void
    {
        $member ??= $staff->first(fn ($s) => $s['model']->store_id === $store->id && in_array($this->serviceKey($service), $s['services'], true))['model'] ?? null;
        $start = $at ?? $this->slot($store, $day, $service->duration_minutes);
        $lines = [['catalog_item_id' => $service->id, 'type' => 'service', 'name' => $service->name, 'quantity' => 1, 'unit_price' => $service->price, 'amount' => $service->price]];

        if (mt_rand(1, 100) <= $productRate * 100) {
            $p = $products[mt_rand(0, count($products) - 1)];
            $lines[] = ['catalog_item_id' => $p->id, 'type' => 'product', 'name' => $p->name, 'quantity' => 1, 'unit_price' => $p->price, 'amount' => $p->price];
        }

        $amount = array_sum(array_column($lines, 'amount'));
        $method = $this->paymentMethod();
        $purchase = Purchase::create([
            'contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => $amount, 'quantity' => count($lines),
            'product_category' => $service->category?->name ?? 'Prestations', 'purchased_at' => $day->toDateString(), 'payment_method' => $method,
        ]);
        $purchase->items()->createMany($lines);

        if ($day->gte($today->copy()->subDays(60))) {
            Appointment::create([
                'store_id' => $store->id, 'customer_id' => $contact->customer_id, 'staff_member_id' => $member?->id,
                'starts_at' => $start, 'duration_minutes' => $service->duration_minutes, 'service' => $service->name, 'status' => 'done',
                'purchase_id' => $purchase->id, 'source' => mt_rand(1, 100) <= 30 ? Appointment::SOURCE_ONLINE : Appointment::SOURCE_STAFF,
            ])->items()->create(['catalog_item_id' => $service->id, 'name' => $service->name, 'price' => $service->price, 'duration_minutes' => $service->duration_minutes]);
        }
    }

    /**
     * Hier, journée pleine : chaque intervenant enchaîne ses rendez-vous
     * honorés et encaissés ; deux absences, une annulation ; trois clients
     * relancés la semaine dernière sont revenus.
     */
    private function yesterday(Organization $organization, Collection $people, array $stores, array $services, array $products, Collection $staff, Carbon $today): void
    {
        $day = $today->copy()->subDay();
        while (collect($stores)->every(fn (Store $s) => ($s->bookingHours()[$day->dayOfWeekIso] ?? null) === null)) {
            $day->subDay();
        }

        $returning = $people->where('profile', 'at_risk')->slice(8, 3)->values();
        foreach ($returning as $p) {
            CustomerFollowUp::create(['customer_id' => $p['contact']->customer_id, 'store_id' => $p['store']->id, 'action' => 'at_risk',
                'channel' => 'whatsapp_manual', 'done_at' => $day->copy()->subDays(4)->setTime(9, 30)]);
        }

        $pool = $people->whereIn('profile', ['champion', 'regular', 'occasional'])->values();
        $i = 0;
        $served = [];

        foreach ($staff as $s) {
            $member = $s['model'];
            $store = collect($stores)->firstWhere('id', $member->store_id);
            $hours = $store->bookingHours()[$day->dayOfWeekIso] ?? null;

            if ($hours === null) {
                continue;
            }

            $t = $day->copy()->setTimeFromTimeString($hours['open']);
            $close = $day->copy()->setTimeFromTimeString($hours['close']);
            $break = $store->bookingBreak();

            for ($n = 0; $n < 5; $n++) {
                $service = $services[$s['services'][$n % count($s['services'])]];
                $end = $t->copy()->addMinutes($service->duration_minutes);

                if ($break && $t->lt($day->copy()->setTimeFromTimeString($break['end'])) && $end->gt($day->copy()->setTimeFromTimeString($break['start']))) {
                    $t = $day->copy()->setTimeFromTimeString($break['end']);
                    $end = $t->copy()->addMinutes($service->duration_minutes);
                }

                if ($end->gt($close)) {
                    break;
                }

                // Client de cet institut, en priorité les clients relancés qui reviennent.
                $back = $returning->first(fn ($p) => $p['store']->id === $store->id && ! in_array($p['contact']->id, $served, true));
                $candidates = $pool->filter(fn ($p) => $p['store']->id === $store->id)->values();
                $person = $back ?? $candidates[($i++ * 7) % max(1, $candidates->count())];
                $served[] = $person['contact']->id;
                $this->visit($person['contact'], $store, $day->copy(), $service, 0.4, $products, $staff, $today, $t->copy(), $member);
                $t = $end->copy()->addMinutes(30);
            }
        }

        // Deux absences et une annulation (un intervenant libre est cherché parmi plusieurs clients).
        $absent = $people->whereIn('profile', ['regular', 'occasional'])->values();
        foreach (['no_show', 'no_show', 'cancelled'] as $k => $status) {
            foreach (range(0, 15) as $try) {
                $before = Appointment::count();
                $this->appointment($absent[($k * 11 + $try * 3) % $absent->count()], $services, $staff, $day->copy(), $status);
                if (Appointment::count() > $before) {
                    break;
                }
            }
        }
    }

    /** Rendez-vous des 14 prochains jours, absences et annulations récentes. */
    private function upcoming(Collection $people, array $stores, array $services, Collection $staff, Carbon $today): void
    {
        $active = $people->whereIn('profile', ['champion', 'regular', 'new', 'occasional', 'prospect'])->values();
        $absentees = $people->whereIn('profile', ['regular', 'occasional', 'at_risk'])->values();

        // Absences et annulations des 30 derniers jours (dont un client absent 3 fois).
        foreach (range(1, 14) as $i) {
            $p = $absentees[($i * 7) % $absentees->count()];
            $this->appointment($p, $services, $staff, $today->copy()->subDays(mt_rand(1, 30)), $i % 4 === 0 ? 'cancelled' : 'no_show');
        }
        $repeat = $absentees[3];
        foreach ([2, 12, 25] as $ago) {
            $this->appointment($repeat, $services, $staff, $today->copy()->subDays($ago), 'no_show');
        }

        // Aujourd'hui et les 14 prochains jours.
        for ($d = 0; $d <= 14; $d++) {
            $day = $today->copy()->addDays($d);
            $count = $d === 0 ? 10 : ($day->dayOfWeekIso === 6 ? 12 : 7);

            for ($k = 0; $k < $count; $k++) {
                $p = $active[($d * 13 + $k * 5) % $active->count()];
                $status = match (true) {
                    $d === 0 => ['confirmed', 'confirmed', 'planned', 'confirmed'][$k % 4],
                    $d === 1 => $k % 3 === 0 ? 'requested' : ($k % 3 === 1 ? 'planned' : 'confirmed'),
                    default => $k % 5 === 0 ? 'requested' : 'planned',
                };
                $this->appointment($p, $services, $staff, $day, $status, $status === 'requested');
            }
        }
    }

    private function appointment(array $person, array $services, Collection $staff, Carbon $day, string $status, bool $online = false): void
    {
        $store = $person['store'];
        $hours = $store->bookingHours()[$day->dayOfWeekIso] ?? null;

        if ($hours === null || Closure::affecting($store, $day, $day)->exists()) {
            return;
        }

        $favorite = $this->favoriteServices($person['contact']);
        $service = $services[$favorite[mt_rand(0, count($favorite) - 1)]];
        $key = $this->serviceKey($service);
        $members = $staff->filter(fn ($s) => $s['model']->store_id === $store->id && in_array($key, $s['services'], true))->pluck('model')->shuffle(mt_rand())->values();
        $start = $this->slot($store, $day, $service->duration_minutes);
        $end = $start->copy()->addMinutes($service->duration_minutes);

        // Un intervenant libre sur ce créneau (une seule prestation à la fois) ; sinon pas de rendez-vous.
        $member = $members->first(fn (StaffMember $m) => ! Appointment::where('staff_member_id', $m->id)->whereDate('starts_at', $day->toDateString())->get()
            ->contains(fn (Appointment $a) => $a->starts_at->lt($end) && $a->starts_at->copy()->addMinutes($a->duration_minutes)->gt($start)));

        if ($member === null) {
            return;
        }

        Appointment::create([
            'store_id' => $store->id, 'customer_id' => $person['contact']->customer_id, 'staff_member_id' => $member->id,
            'starts_at' => $start, 'duration_minutes' => $service->duration_minutes, 'service' => $service->name, 'status' => $status,
            'source' => $online ? Appointment::SOURCE_ONLINE : Appointment::SOURCE_STAFF,
            'cancel_token' => $online ? Str::random(40) : null,
        ])->items()->create(['catalog_item_id' => $service->id, 'name' => $service->name, 'price' => $service->price, 'duration_minutes' => $service->duration_minutes]);
    }

    private function loyaltyAndFollowUps(Organization $organization, Collection $people, Collection $rewards, Carbon $today): void
    {
        // Quelques fidèles ont déjà échangé une récompense.
        foreach ($people->where('profile', 'champion')->take(6) as $i => $p) {
            $reward = $rewards[$i % 2];
            $balance = (int) LoyaltyTransaction::where('customer_id', $p['contact']->customer_id)->sum('points');

            if ($balance >= $reward->points_cost) {
                LoyaltyTransaction::create(['organization_id' => $organization->id, 'customer_id' => $p['contact']->customer_id, 'type' => 'redeem',
                    'points' => -$reward->points_cost, 'reward_id' => $reward->id, 'note' => $reward->name]);
            }
        }

        // Relances WhatsApp des 10 derniers jours ; deux clients relancés sont revenus.
        foreach ($people->where('profile', 'at_risk')->take(8)->values() as $i => $p) {
            CustomerFollowUp::create(['customer_id' => $p['contact']->customer_id, 'store_id' => $p['store']->id, 'action' => 'at_risk',
                'channel' => 'whatsapp_manual', 'done_at' => $today->copy()->subDays(mt_rand(2, 10))->setTime(10, 0)]);
        }
    }

    /** @return list<string> */
    /** Heure de début (demi-heure) dans les horaires du jour, sans toucher la pause, fin avant la fermeture. */
    private function slot(Store $store, Carbon $day, int $duration): Carbon
    {
        $hours = $store->bookingHours()[$day->dayOfWeekIso];
        $open = $day->copy()->setTimeFromTimeString($hours['open']);
        $close = $day->copy()->setTimeFromTimeString($hours['close']);
        $break = $store->bookingBreak();
        $steps = max(0, intdiv((int) $open->diffInMinutes($close) - $duration, 30));

        for ($try = 0; $try < 12; $try++) {
            $start = $open->copy()->addMinutes(30 * mt_rand(0, $steps));
            $end = $start->copy()->addMinutes($duration);

            if ($break === null || ! ($start->lt($day->copy()->setTimeFromTimeString($break['end'])) && $end->gt($day->copy()->setTimeFromTimeString($break['start'])))) {
                return $start;
            }
        }

        return $open->copy();
    }

    /** Prestations habituelles d'un client (toujours les mêmes pour une même personne). */
    private function favoriteServices(Contact $contact): array
    {
        $sets = [['tresses', 'coupe'], ['visage', 'massage'], ['manucure', 'pedicure'], ['coupe', 'manucure'], ['defrisage', 'coupe'], ['tresses', 'manucure']];

        return $contact->gender === 'H' ? ['homme', 'massage'] : $sets[$contact->id % count($sets)];
    }

    private function serviceKey(CatalogItem $service): string
    {
        return [
            'Tresses (box braids)' => 'tresses', 'Coupe et brushing' => 'coupe', 'Défrisage' => 'defrisage', 'Coupe homme' => 'homme',
            'Soin du visage' => 'visage', 'Massage 60 min' => 'massage', 'Manucure gel' => 'manucure', 'Pédicure' => 'pedicure',
        ][$service->name] ?? '';
    }

    private function paymentMethod(): string
    {
        $r = mt_rand(1, 100);

        return match (true) {
            $r <= 40 => 'cash',
            $r <= 76 => 'airtel_money',
            $r <= 92 => 'moov_money',
            $r <= 98 => 'card',
            default => 'transfer',
        };
    }

    /** Numéros fictifs uniques (+241 77 99x xxx, +241 66 99x xxx). */
    private function phone(): string
    {
        $this->phoneSeq++;

        return ($this->phoneSeq % 2 ? '2417799' : '2416699').str_pad((string) $this->phoneSeq, 4, '0', STR_PAD_LEFT);
    }
}
