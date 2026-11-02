<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\ClassSession;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GroupClass;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Role;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Services\GroupClasses;
use App\Services\Installments;
use App\Services\PhoneNormalizer;
use App\Services\Subscriptions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Club sportif fictif « Club Étoile Libreville » (démonstration des modules
 * cours de groupe, abonnements, échéances et personnes suivies), inspiré
 * d'une école de sport pour enfants de Libreville : judo, football, danse et
 * natation sur quatre lieux, saison à partir du 7 octobre 2026, ~120 enfants
 * dont des fratries, impayés, absences, deux annulations pour pluie et un
 * rattrapage. Données fictives, jamais en production.
 *
 * Mot de passe : variable DEMO_PASSWORD, sinon « Demo-Sport-2026 ».
 */
class SportDemoSeeder extends Seeder
{
    public const SLUG = 'demo-sport';

    public const DEFAULT_PASSWORD = 'Demo-Sport-2026';

    public const SEASON_START = '2026-10-07';

    private const FIRST_NAMES = ['Léo', 'Inès', 'Noah', 'Grâce', 'Ethan', 'Merveille', 'Yanis', 'Divine', 'Rayan', 'Précieuse', 'Kevin', 'Orphée', 'Junior', 'Exaucée', 'Dylan', 'Charlène', 'Brayan', 'Emmanuella', 'Joël', 'Pétronille',
        'Arnaud', 'Gloire', 'Ryan', 'Bénie', 'Samuel', 'Ornella', 'David', 'Christ', 'Maëlle', 'Hugo', 'Anaïs', 'Lionel', 'Chloé', 'Fabrice', 'Dorcas', 'Elie', 'Sarah', 'Ismaël', 'Ruth', 'Cédric'];

    private const LAST_NAMES = ['Mba', 'Obame', 'Nguema', 'Ondo', 'Mintsa', 'Ella', 'Moussavou', 'Bongo', 'Ndong', 'Mbadinga', 'Ovono', 'Essono', 'Makaya', 'Koumba', 'Nzé', 'Mabika', 'Boussougou', 'Mouketou', 'Ekomie', 'Biyoghe',
        'Engone', 'Allogho', 'Mengue', 'Nzamba', 'Assoumou', 'Mavoungou', 'Obiang', 'Ntoutoume', 'Ibinga', 'Mihindou'];

    private const PARENT_FIRST = ['Mireille', 'Paul', 'Sylvie', 'Jean-Marc', 'Nadège', 'Patrick', 'Clarisse', 'Hervé', 'Pélagie', 'Serge', 'Estelle', 'Rodrigue', 'Gisèle', 'Alain', 'Prisca', 'Thierry', 'Laure', 'Guy', 'Viviane', 'Franck'];

    public function run(): void
    {
        mt_srand(2026);
        $password = env('DEMO_PASSWORD', self::DEFAULT_PASSWORD);

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Club Étoile Libreville',
            'slug' => self::SLUG,
            'sector' => 'sport',
            'currency' => 'XAF',
            'timezone' => 'Africa/Libreville',
            'whatsapp_api_enabled' => false,
            'status' => Organization::STATUS_ACTIVE,
            'plan' => 'equipe',
            'loyalty_settings' => ['enabled' => true, 'fcfa_per_point' => 1000, 'starts_on' => self::SEASON_START, 'tiers' => Organization::LOYALTY_DEFAULTS['tiers']],
            'report_settings' => ['enabled' => true, 'recipients' => ['directeur@demo-sport.test'], 'send_at' => '20:00', 'monthly_target' => 2500000],
        ]);
        $organization->setModules(['seances_groupe', 'abonnements', 'tranches', 'beneficiaires', 'lieux', 'catalogue', 'fidelite'], [
            'seances_groupe' => ['capacity' => 25, 'makeup_after' => 2, 'makeup_weekday' => 6],
            'abonnements' => ['period' => 'month', 'season_start' => self::SEASON_START, 'season_end' => '2027-06-30'],
            'tranches' => ['due_day' => 5, 'reminders' => [-2, 0, 7]],
            'beneficiaires' => ['label' => 'adherent', 'minor_needs_responsible' => true],
            'fidelite' => ['points_per_attendance' => 2],
        ], reviewed: true);
        $organization->save();

        Role::seedDefaultsFor($organization);
        $role = fn (string $name) => Role::where('organization_id', $organization->id)->where('name', $name)->value('id');
        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Club Étoile']);
        $stores = collect(['Sacré-Cœur', 'Akaé Beach', 'INJS', 'Oloumi'])->mapWithKeys(fn ($name) => [$name => Store::create([
            'organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => $name, 'city' => 'Libreville',
        ])]);

        $director = User::create(['name' => 'Rodrigue Ndong (directeur)', 'email' => 'directeur@demo-sport.test', 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $role('Super administrateur')]);
        Auth::setUser($director);

        // Activités et équipement (catalogue), coachs.
        $activities = Category::create(['organization_id' => $organization->id, 'name' => 'Activités']);
        $equipment = Category::create(['organization_id' => $organization->id, 'name' => 'Équipement']);
        $catalog = collect(['Judo' => 15000, 'Football' => 13000, 'Danse' => 15000, 'Natation' => 20000])->mapWithKeys(fn ($price, $name) => [$name => CatalogItem::create([
            'organization_id' => $organization->id, 'type' => CatalogItem::TYPE_SERVICE, 'name' => $name, 'category_id' => $activities->id, 'price' => $price, 'duration_minutes' => 60, 'active' => true,
        ])]);
        CatalogItem::create(['organization_id' => $organization->id, 'type' => CatalogItem::TYPE_PRODUCT, 'name' => 'Kimono', 'category_id' => $equipment->id, 'price' => 30000, 'active' => true]);

        $coaches = [];
        foreach ([['Serge Mintsa', 'Sacré-Cœur', 'judo'], ['Aline Koumba', 'Akaé Beach', 'natation'], ['Patrick Ovono', 'INJS', 'football'], ['Nadège Ella', 'Oloumi', 'danse']] as [$name, $place, $slug]) {
            $user = User::create(['name' => "Coach {$name}", 'email' => "coach-{$slug}@demo-sport.test", 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $role('Responsable de boutique'), 'store_id' => $stores[$place]->id]);
            $coaches[$slug] = StaffMember::create(['organization_id' => $organization->id, 'store_id' => $stores[$place]->id, 'user_id' => $user->id, 'name' => $name, 'active' => true, 'bookable_online' => false]);
        }

        // Formules : mensualité par activité, inscription et assurance, options transport et kimono.
        $fees = [['label' => 'Inscription', 'amount' => 10000], ['label' => 'Assurance', 'amount' => 17000]];
        $transport = ['label' => 'Transport', 'amount' => 15000, 'recurring' => true];
        $plans = [];
        foreach (['Judo' => 15000, 'Football' => 13000, 'Danse' => 15000, 'Natation' => 20000] as $activity => $price) {
            $plans[$activity] = Plan::create([
                'organization_id' => $organization->id, 'name' => "{$activity} enfants — mensuel", 'catalog_item_id' => $catalog[$activity]->id,
                'price' => $price, 'period' => 'month', 'fees' => $fees,
                'options' => $activity === 'Judo' ? [$transport, ['label' => 'Kimono', 'amount' => 30000, 'recurring' => false]] : [$transport],
            ]);
        }

        // Cours de la saison.
        $classes = [
            'Danse' => [$this->class($organization, $stores['Oloumi'], $catalog['Danse'], $coaches['danse'], 'Danse enfants', 1, '14:00', '15:00', 25)],
            'Natation' => [$this->class($organization, $stores['Akaé Beach'], $catalog['Natation'], $coaches['natation'], 'Natation enfants', 3, '15:00', '16:00', 25)],
            'Football' => [$this->class($organization, $stores['INJS'], $catalog['Football'], $coaches['football'], 'Football enfants', 5, '15:00', '16:30', 30)],
            'Judo' => [
                $this->class($organization, $stores['Sacré-Cœur'], $catalog['Judo'], $coaches['judo'], 'Judo 5-7 ans', 6, '10:30', '11:30', 25),
                $this->class($organization, $stores['Sacré-Cœur'], $catalog['Judo'], $coaches['judo'], 'Judo 8-12 ans', 6, '11:30', '12:30', 25),
            ],
        ];

        $groupClasses = app(GroupClasses::class);
        $subscriptions = app(Subscriptions::class);
        $installments = app(Installments::class);
        $children = [];

        CustomerLifecycle::batch(function () use ($organization, $classes, $plans, $groupClasses, $subscriptions, $director, &$children) {
            $kid = 0;
            $families = 0;

            while ($kid < 120) {
                $families++;
                $last = self::LAST_NAMES[($families * 7) % count(self::LAST_NAMES)];
                $parentName = self::PARENT_FIRST[$families % count(self::PARENT_FIRST)].' '.$last;
                $phone = PhoneNormalizer::normalize('0'.(mt_rand(0, 1) ? '77' : '66').sprintf('%06d', 100000 + $families * 37));
                $activity = array_keys($plans)[mt_rand(0, 3)];
                $store = $classes[$activity][0]->store;
                $parent = Contact::create(['store_id' => $store->id, 'name' => $parentName, 'phone_e164' => $phone, 'consent_status' => mt_rand(1, 10) <= 8 ? 'opt_in' : 'unknown', 'consent_source' => 'manuel'])->customer;

                // Fratries : un, deux ou trois enfants.
                $siblings = min(120 - $kid, [1, 1, 1, 2, 2, 3][mt_rand(0, 5)]);
                for ($s = 0; $s < $siblings; $s++, $kid++) {
                    $activity = $s === 0 ? $activity : array_keys($plans)[mt_rand(0, 3)];
                    $age = mt_rand(5, 12);
                    $class = $activity === 'Judo' ? $classes['Judo'][$age <= 7 ? 0 : 1] : $classes[$activity][0];
                    // Cours complet : la première activité qui a encore de la place.
                    if ($class->activeEnrollments()->count() >= $class->capacity) {
                        foreach ($classes as $other => $list) {
                            $free = collect($list)->first(fn (GroupClass $c) => $c->activeEnrollments()->count() < $c->capacity);
                            if ($free) {
                                [$class, $activity] = [$free, $other];
                                break;
                            }
                        }
                    }

                    $name = self::FIRST_NAMES[($kid * 11 + $s) % count(self::FIRST_NAMES)].' '.$last;
                    $birth = Carbon::parse(self::SEASON_START)->subYears($age)->subDays(mt_rand(0, 360));
                    $child = Customer::create([
                        'organization_id' => $organization->id, 'phone_e164' => null, 'name' => $name, 'birthdate' => $birth, 'gender' => mt_rand(0, 1) ? 'F' : 'H',
                        'responsible_id' => $parent->id, 'responsible_relation' => 'parent', 'identity_confirmed_at' => now(),
                        'health_note' => $kid % 17 === 3 ? ['Asthme : ventoline dans le sac', 'Allergie à l’arachide', 'Ne sait pas encore nager seul'][$kid % 3] : null,
                    ]);
                    Contact::create(['store_id' => $class->store_id, 'customer_id' => $child->id, 'name' => $name, 'phone_e164' => null, 'birthdate' => $birth]);

                    $options = [];
                    if (mt_rand(1, 10) <= 3) {
                        $options[] = 0; // transport
                    }
                    if ($activity === 'Judo' && mt_rand(0, 1)) {
                        $options[] = 1; // kimono
                    }
                    $subscription = $subscriptions->create($director, [
                        'customer_id' => $child->id, 'plan_id' => $plans[$activity]->id, 'store_id' => $class->store_id,
                        'starts_on' => self::SEASON_START, 'options' => $options,
                    ]);
                    $groupClasses->enroll($class, $child, $subscription, Carbon::parse(self::SEASON_START));
                    $children[] = ['customer' => $child, 'family' => $families];
                }
            }
        });

        // Paiements : la plupart des familles ont réglé l'inscription et octobre ; certaines sont en retard.
        $today = today();
        CustomerLifecycle::batch(function () use ($organization, $installments, $director, $today) {
            Installment::whereHas('schedule', fn ($q) => $q->where('organization_id', $organization->id))
                ->where('due_on', '<', $today->toDateString())
                ->with('schedule')
                ->orderBy('due_on')
                ->get()
                ->each(function (Installment $installment) use ($installments, $director) {
                    $family = $installment->schedule->customer_id;
                    // Environ une famille sur sept ne paie pas encore ses mensualités.
                    $late = $family % 7 === 0 && str_starts_with((string) $installment->label, 'Mensualité');
                    if ($late) {
                        return;
                    }
                    $paidAt = $installment->due_on->copy()->addDays(mt_rand(0, 4))->setTime(mt_rand(8, 18), mt_rand(0, 59));
                    $installments->pay($installment, $director, ['cash', 'airtel_money', 'moov_money'][mt_rand(0, 2)], $paidAt->min(now()), refreshScores: false);
                });
        });

        // Séances de la saison et appel : présence ~85 %, quelques enfants qui décrochent.
        $groupClasses->ensureSessions($organization->id, Carbon::parse(self::SEASON_START), $today->copy()->addWeeks(4));
        $dropping = collect($children)->pluck('customer.id')->filter(fn ($id) => $id % 13 === 0)->flip();

        // Deux annulations pour pluie (football, vendredis 23 et 30 octobre) et un rattrapage samedi 7 novembre.
        $football = $classes['Football'][0];
        foreach (['2026-10-23', '2026-10-30'] as $date) {
            $session = ClassSession::where('group_class_id', $football->id)->where('session_date', $date)->first();
            $session?->update(['status' => ClassSession::STATUS_CANCELLED, 'cancel_reason' => 'weather', 'cancel_note' => 'Terrain de l’INJS inondé', 'cancelled_at' => Carbon::parse($date)->setTime(11, 0)]);
        }
        if (ClassSession::where('group_class_id', $football->id)->where('status', ClassSession::STATUS_CANCELLED)->exists()) {
            $groupClasses->scheduleMakeup($football, Carbon::parse('2026-11-07'), $director);
        }

        ClassSession::where('organization_id', $organization->id)
            ->where('session_date', '<', $today->toDateString())
            ->where('status', ClassSession::STATUS_SCHEDULED)
            ->with('groupClass')
            ->get()
            ->each(function (ClassSession $session) use ($groupClasses, $dropping, $today, $director) {
                $recent = $session->session_date >= $today->copy()->subDays(30)->toDateString();
                $marks = $groupClasses->participants($session)->mapWithKeys(fn (Customer $c) => [$c->id => match (true) {
                    isset($dropping[$c->id]) && $recent => mt_rand(1, 10) <= 8 ? 'absent' : 'present',
                    mt_rand(1, 100) <= 85 => 'present',
                    mt_rand(0, 1) === 1 => 'excused',
                    default => 'absent',
                }])->all();
                $groupClasses->mark($session, $marks, $director);
            });

        app(CustomerLifecycle::class)->refreshOrganization($organization);
        app(CustomerScoring::class)->refreshOrganization($organization);
        Auth::forgetUser();
    }

    private function class(Organization $organization, Store $store, CatalogItem $activity, StaffMember $coach, string $name, int $weekday, string $start, string $end, int $capacity): GroupClass
    {
        return GroupClass::create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'catalog_item_id' => $activity->id, 'staff_member_id' => $coach->id,
            'name' => $name, 'weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'capacity' => $capacity, 'starts_on' => self::SEASON_START, 'active' => true,
        ]);
    }
}
