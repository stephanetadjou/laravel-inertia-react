<?php

namespace Database\Seeders;

use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Role;
use App\Models\StaffMember;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Services\Fleet;
use App\Services\Installments;
use App\Support\SectorPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Loueur fictif « Ogooué Auto » (secteur Vente et location de véhicules) :
 * 12 véhicules, 2 chauffeurs, ~40 clients (particuliers et entreprises),
 * locations passées, en cours (dont une en retard), à venir, un contrat
 * longue durée, deux véhicules à vendre et des échéances d'entretien.
 * Données de test uniquement ; rejouable.
 */
class VehiculesDemoSeeder extends Seeder
{
    public const SLUG = 'demo-ogooue-auto';

    public const DEFAULT_PASSWORD = 'Demo-Vehicules-2026';

    public function run(): void
    {
        mt_srand(2026);
        $password = env('DEMO_PASSWORD', self::DEFAULT_PASSWORD);
        $today = today();

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Ogooué Auto',
            'slug' => self::SLUG,
            'sector' => 'vehicules',
            'currency' => 'XAF',
            'timezone' => 'Africa/Libreville',
            'whatsapp_api_enabled' => false,
            'status' => Organization::STATUS_ACTIVE,
            'plan' => 'equipe',
        ]);
        SectorPresets::apply($organization, true);

        Role::seedDefaultsFor($organization);
        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Ogooué Auto']);
        $store = Store::create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Agence Glass', 'city' => 'Libreville']);
        $manager = User::create(['name' => 'Marc Essono (gérant)', 'email' => 'gerant@demo-ogooue-auto.test', 'password' => $password, 'organization_id' => $organization->id,
            'role_id' => Role::where('organization_id', $organization->id)->where('name', 'Super administrateur')->value('id')]);
        Auth::setUser($manager);

        $drivers = collect(['Serge Mintsa', 'Hervé Nzé'])->map(fn ($name) => StaffMember::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'name' => $name, 'active' => true]));

        $fleet = [
            ['Toyota Hilux', 'GA-118-HX', '4x4', 45000, 270000, 900000, 82000],
            ['Toyota Hilux', 'GA-347-HX', '4x4', 45000, 270000, 900000, 64000],
            ['Toyota Land Cruiser', 'GA-552-LC', '4x4', 70000, 420000, 1400000, 91000],
            ['Hyundai Tucson', 'GA-271-TS', 'suv', 35000, 210000, 700000, 38000],
            ['Kia Sportage', 'GA-639-SP', 'suv', 35000, 210000, 700000, 45000],
            ['Toyota Yaris', 'GA-104-YR', 'citadine', 20000, 120000, 400000, 51000],
            ['Suzuki Swift', 'GA-388-SW', 'citadine', 18000, 108000, 360000, 27000],
            ['Toyota Corolla', 'GA-716-CR', 'berline', 25000, 150000, 500000, 73000],
            ['Toyota Hiace', 'GA-905-HC', 'minibus', 60000, 360000, 1200000, 120000],
            ['Mitsubishi L200', 'GA-421-LD', '4x4', 40000, 240000, 800000, 99000],
        ];
        $vehicles = collect($fleet)->map(fn ($v, $i) => BookableResource::create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'name' => $v[0], 'reference' => $v[1], 'kind' => 'vehicule', 'category' => $v[2],
            'price_day' => $v[3], 'price_week' => $v[4], 'price_month' => $v[5], 'mileage' => $v[6], 'active' => true, 'status' => 'available',
            'service_due_km' => $v[6] + ($i === 9 ? 300 : 8000), 'insurance_until' => $today->copy()->addDays($i === 7 ? 12 : 200)->toDateString(),
            'inspection_until' => $today->copy()->addDays(150 + $i * 10)->toDateString(),
            'created_at' => $today->copy()->subMonths(10),
        ]));
        BookableResource::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'name' => 'Peugeot 3008', 'reference' => 'GA-830-PG', 'kind' => 'vehicule', 'category' => 'suv',
            'mileage' => 112000, 'active' => true, 'status' => 'for_sale', 'sale_price' => 8500000, 'for_sale_since' => $today->copy()->subDays(74)->toDateString()]);
        BookableResource::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'name' => 'Nissan Navara', 'reference' => 'GA-264-NV', 'kind' => 'vehicule', 'category' => '4x4',
            'mileage' => 145000, 'active' => true, 'status' => 'for_sale', 'sale_price' => 11000000, 'for_sale_since' => $today->copy()->subDays(18)->toDateString()]);

        $names = ['SARL Ogooué Mines', 'Comilog Services', 'Ets Nguema BTP', 'ONG Santé Plus', 'Cabinet Mba & Associés', 'Gabon Events', 'Hôtel Le Rio', 'Paul Obame', 'Aline Moussavou',
            'Jean-Claude Ella', 'Sandrine Ondo', 'Rodrigue Mbadinga', 'Christian Nzigou', 'Laetitia Koumba', 'Fabrice Engone', 'Mireille Bouanga', 'Serge Oyono', 'Patricia Mengue',
            'Didier Ntoutoume', 'Estelle Nkoghe', 'Brice Mabiala', 'Nadège Ivanga', 'Arnaud Ekomie', 'Joëlle Ngoma', 'Yannick Bekale', 'Carine Ondo Mba', 'Thierry Nzamba',
            'Murielle Assele', 'Ghislain Mouele', 'Pélagie Mintsa', 'Alain Biyogo', 'Inès Mavoungou', 'Steeve Nang', 'Lucie Abessolo', 'Olivier Mezui', 'Prisca Ndong'];
        $customers = [];
        CustomerLifecycle::batch(function () use ($names, $store, &$customers) {
            foreach ($names as $i => $name) {
                $customers[] = Contact::create(['store_id' => $store->id, 'name' => $name, 'phone_e164' => '2417'.str_pad((string) (7100000 + $i * 731), 7, '0', STR_PAD_LEFT),
                    'consent_status' => $i % 7 === 6 ? 'unknown' : 'opt_in', 'consent_source' => 'manuel'])->customer;
            }
        });

        $fleetService = app(Fleet::class);
        $add = function (int $vehicle, int $customer, Carbon $from, Carbon $to, string $status, array $extra = []) use ($organization, $store, $vehicles, $customers, $manager) {
            $v = $vehicles[$vehicle];

            return Rental::create([
                'organization_id' => $organization->id, 'store_id' => $store->id, 'resource_id' => $v->id, 'customer_id' => $customers[$customer]->id,
                'kind' => 'short', 'status' => $status, 'starts_at' => $from, 'ends_at' => $to, 'rate' => $v->price_day, 'rate_period' => 'day',
                'amount' => Fleet::amount($v->price_day, 'day', $from, $to), 'deposit' => $v->price_day * 3, 'created_by' => $manager->id, ...$extra,
            ]);
        };

        // Historique : 6 mois de locations rendues et encaissées. Le L200 (indice 9) et la Swift (6) ne sortent plus depuis 2 semaines.
        for ($week = 26; $week >= 1; $week--) {
            foreach ([0, 1, 3, 4, 5, 7, 8] as $j => $vehicle) {
                if (mt_rand(1, 10) > 6) {
                    continue;
                }
                $from = $today->copy()->subWeeks($week)->addDays($j % 5)->setTime(8, 0);
                $rental = $add($vehicle, mt_rand(0, count($customers) - 1), $from, $from->copy()->addDays(mt_rand(1, 4)), 'ongoing', ['departed_at' => $from]);
                $this->travel($rental->ends_at, fn () => $fleetService->giveBack($rental, $manager, null, null, 'cash'));
            }
        }
        foreach ([6 => 21, 9 => 16] as $vehicle => $days) {
            $from = $today->copy()->subDays($days + 3)->setTime(8, 0);
            $rental = $add($vehicle, 7 + $vehicle, $from, $from->copy()->addDays(3), 'ongoing', ['departed_at' => $from]);
            $this->travel($rental->ends_at, fn () => $fleetService->giveBack($rental, $manager, null, null, 'cash'));
        }

        // En cours : deux sorties normales, une en retard (avec chauffeur).
        $add(0, 0, $today->copy()->subDays(2)->setTime(8, 0), $today->copy()->addDays(3)->setTime(18, 0), 'ongoing', ['departed_at' => $today->copy()->subDays(2), 'driver_id' => $drivers[0]->id]);
        $add(3, 9, $today->copy()->subDay()->setTime(9, 0), $today->copy()->addDay()->setTime(9, 0), 'ongoing', ['departed_at' => $today->copy()->subDay()]);
        $add(8, 5, $today->copy()->subDays(4)->setTime(7, 0), $today->copy()->subDay()->setTime(19, 0), 'ongoing', ['departed_at' => $today->copy()->subDays(4), 'driver_id' => $drivers[1]->id]);
        // À venir : réservations des prochains jours (la flotte est presque pleine demain).
        $add(1, 2, $today->copy()->addDay()->setTime(7, 0), $today->copy()->addDays(4)->setTime(18, 0), 'reserved');
        $add(4, 12, $today->copy()->addDay()->setTime(8, 0), $today->copy()->addDays(2)->setTime(18, 0), 'reserved');
        $add(5, 14, $today->copy()->addDay()->setTime(10, 0), $today->copy()->addDays(3)->setTime(10, 0), 'reserved');
        $add(7, 18, $today->copy()->addDays(1)->setTime(8, 0), $today->copy()->addDays(6)->setTime(8, 0), 'reserved');

        // Longue durée : le Land Cruiser loué à une société minière, fin dans 3 semaines.
        $from = $today->copy()->subMonths(5)->addDays(21)->setTime(8, 0);
        $long = Rental::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'resource_id' => $vehicles[2]->id, 'customer_id' => $customers[1]->id, 'kind' => 'long',
            'status' => 'ongoing', 'starts_at' => $from, 'ends_at' => $from->copy()->addMonths(6), 'departed_at' => $from, 'rate' => 1400000, 'rate_period' => 'month',
            'amount' => 8400000, 'deposit' => 2000000, 'created_by' => $manager->id]);
        $schedule = app(Installments::class)->createSchedule($manager, ['customer_id' => $long->customer_id, 'store_id' => $store->id, 'label' => 'Location longue durée '.$vehicles[2]->label(),
            'installments' => Installments::split(8400000, 6, $from->copy()->startOfDay())]);
        $long->update(['payment_schedule_id' => $schedule->id]);
        foreach ($schedule->installments()->where('due_on', '<', $today->copy()->subDays(10)->toDateString())->get() as $installment) {
            app(Installments::class)->pay($installment, $manager, 'transfer', Carbon::parse($installment->due_on), false);
        }

        app(CustomerLifecycle::class)->refreshOrganization($organization);
        app(CustomerScoring::class)->refreshOrganization($organization);
    }

    /** Exécute une action à une date passée (retour encaissé à sa date). */
    private function travel(Carbon $at, callable $action): void
    {
        Carbon::setTestNow($at);
        try {
            $action();
        } finally {
            Carbon::setTestNow();
        }
    }
}
