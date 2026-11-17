<?php

namespace Database\Seeders;

use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Modules\Depenses\Models\Expense;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RentalAccount;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Models\VehicleSale;
use App\Modules\Location\Services\RentalDesk;
use App\Modules\Location\Services\RideDesk;
use App\Modules\Location\Services\VehicleSales;
use App\Modules\Tunnels\Models\FunnelLead;
use App\Modules\Tunnels\Services\Funnels;
use App\Services\CustomerLifecycle;
use App\Support\OnlinePage;
use App\Support\SectorPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Loueur fictif « Yamoo Drive » (Cameroun) : 3 agences (Douala, Yaoundé,
 * Bafoussam), une dizaine de véhicules, clients fictifs (particuliers,
 * entreprises, diaspora, organisateurs de mariages), demandes venues de
 * Facebook, WhatsApp et TikTok, locations avec chauffeur (événements,
 * transferts aéroport), une caution à clôturer, une vente en 3 fois.
 * Données de test uniquement ; rejouable sans toucher aux autres démos.
 */
class YamooDriveDemoSeeder extends Seeder
{
    public const SLUG = 'demo-yamoo-drive';

    public const DEFAULT_PASSWORD = 'Demo-Yamoo-2026';

    public function run(): void
    {
        mt_srand(2371);
        $password = env('DEMO_YAMOO_PASSWORD', self::DEFAULT_PASSWORD);
        $now = now()->startOfHour();

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Yamoo Drive', 'slug' => self::SLUG, 'sector' => 'location', 'currency' => 'XAF', 'timezone' => 'Africa/Douala',
            'whatsapp_api_enabled' => false, 'status' => Organization::STATUS_ACTIVE, 'plan' => 'equipe',
        ]);
        SectorPresets::apply($organization, true);
        $organization->save();

        Role::seedDefaultsFor($organization);
        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Yamoo Drive']);
        $stores = [];
        foreach ([['Douala', 'Yamoo Drive Douala (Bonapriso)', '237690000101'], ['Yaoundé', 'Yamoo Drive Yaoundé (Bastos)', '237690000202'], ['Bafoussam', 'Yamoo Drive Bafoussam', '237690000303']] as [$city, $name, $phone]) {
            $stores[$city] = Store::create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => $name, 'city' => $city, 'booking_phone' => $phone]);
        }
        $manager = User::create(['name' => 'Directeur Yamoo Drive', 'email' => 'directeur@yamoo-drive.test', 'password' => $password, 'organization_id' => $organization->id,
            'role_id' => Role::where('organization_id', $organization->id)->where('name', 'Super administrateur')->value('id')]);
        Auth::setUser($manager);

        $v = fn (string $city, string $name, string $plate, string $category, int $day, int $km, array $extra = []) => BookableResource::create([
            'organization_id' => $organization->id, 'store_id' => $stores[$city]->id, 'name' => $name, 'reference' => $plate, 'kind' => 'vehicule', 'category' => $category,
            'active' => true, 'status' => 'available', 'mileage' => $km, 'price_day' => $day, 'price_week' => $day * 6, 'price_month' => $day * 22,
            'insurance_until' => today()->addMonths(mt_rand(2, 10)), 'inspection_until' => today()->addMonths(mt_rand(1, 12)), ...$extra,
        ]);
        $vip = $v('Douala', 'Mercedes Classe E (VIP)', 'LT-245-AA', 'berline', 120000, 48000);
        $vip2 = $v('Yaoundé', 'Toyota Camry (VIP)', 'CE-118-BB', 'berline', 70000, 61000);
        $prado = $v('Douala', 'Toyota Land Cruiser Prado', 'LT-772-CC', '4x4', 95000, 83000);
        $hilux = $v('Bafoussam', 'Toyota Hilux', 'OU-310-DD', '4x4', 65000, 112000, ['service_due_km' => 112300]);
        $rav4 = $v('Yaoundé', 'Toyota RAV4', 'CE-604-EE', 'suv', 50000, 39000);
        $tucson = $v('Douala', 'Hyundai Tucson', 'LT-415-FF', 'suv', 45000, 57000, ['insurance_until' => today()->addDays(12)]);
        $corolla = $v('Bafoussam', 'Toyota Corolla', 'OU-127-GG', 'berline', 30000, 94000);
        $coaster = $v('Douala', 'Toyota Coaster (30 places, événements)', 'LT-901-HH', 'minibus', 180000, 145000);
        $hiace = $v('Yaoundé', 'Toyota Hiace (15 places)', 'CE-556-JJ', 'minibus', 110000, 131000, ['status' => 'maintenance']);
        $fortuner = $v('Douala', 'Toyota Fortuner', 'LT-330-KK', '4x4', 80000, 99000, ['status' => 'for_sale', 'sale_price' => 19500000, 'for_sale_since' => today()->subDays(18),
            'sale_token' => Str::random(32), 'sale_description' => 'Toyota Fortuner 2019, diesel, boîte automatique, 7 places. Entretien suivi chez Yamoo Drive.']);
        $sold = $v('Yaoundé', 'Mitsubishi Pajero', 'CE-090-LL', '4x4', 55000, 168000, ['status' => 'for_sale', 'sale_price' => 9500000, 'for_sale_since' => today()->subDays(40)]);

        // Clients fictifs : [nom, ville, type].
        $people = [
            ['Client Particulier A', 'Douala', null], ['Client Particulier B', 'Douala', null], ['Client Particulier C', 'Yaoundé', null], ['Client Particulier D', 'Yaoundé', null],
            ['Client Particulier E', 'Bafoussam', null], ['Client Particulier F', 'Bafoussam', null],
            ['Organisatrice de mariages (démo)', 'Douala', null], ['Agence événementielle (démo)', 'Yaoundé', null],
            ['Client diaspora (Paris, démo)', 'Douala', null], ['Client diaspora (Bruxelles, démo)', 'Yaoundé', null],
            ['Entreprise Logistique Démo SARL', 'Douala', 'business'], ['Société Minière Démo SA', 'Bafoussam', 'business'],
        ];
        $customers = [];
        CustomerLifecycle::batch(function () use ($people, $stores, &$customers) {
            foreach ($people as $i => [$name, $city]) {
                $customers[] = Contact::create(['store_id' => $stores[$city]->id, 'name' => $name, 'phone_e164' => '2376'.str_pad((string) (70000000 + $i * 1373), 8, '0', STR_PAD_LEFT),
                    'consent_status' => 'opt_in', 'consent_source' => 'manuel'])->customer;
            }
        });
        foreach ([10, 11] as $i) {
            $customers[$i]->forceFill(['type' => 'business', 'company_name' => $customers[$i]->name])->save();
        }
        RentalAccount::create(['organization_id' => $organization->id, 'customer_id' => $customers[10]->id, 'discount_percent' => 15, 'notes' => 'Véhicules pour les missions, facturation mensuelle']);
        RentalAccount::create(['organization_id' => $organization->id, 'customer_id' => $customers[11]->id, 'day_rate' => 55000, 'notes' => 'Contrat cadre 4x4']);

        $desk = app(RentalDesk::class);
        $at = fn (int $days, int $hour) => $now->copy()->addDays($days)->setTime($hour, 0);
        $request = fn (int $c, BookableResource|string $where, array $data, Carbon $created) => tap($desk->createRequest($manager, [
            'store_id' => is_string($where) ? $stores[$where]->id : $where->store_id, 'customer_id' => $customers[$c]->id, 'need' => 'rent', ...$data,
        ]), fn ($r) => $r->forceFill(['created_at' => $created])->save());

        // Locations terminées (historique, CA, avis).
        $past = [
            [0, $tucson, -38, 3, 'personal', 'whatsapp', false, 'Agence Bonapriso'],
            [6, $coaster, -30, 1, 'event', 'facebook', true, 'Salle des fêtes, Bonamoussadi'],
            [8, $vip, -24, 12, 'diaspora', 'whatsapp', true, 'Aéroport de Douala'],
            [10, $prado, -19, 7, 'business', 'phone', false, 'Siège client, Akwa'],
            [2, $rav4, -14, 4, 'personal', 'tiktok', false, 'Agence Bastos'],
            [11, $hilux, -10, 5, 'business', 'phone', true, 'Agence Bafoussam'],
            [7, $vip2, -6, 2, 'event', 'facebook', true, 'Hôtel Mont Fébé'],
            [4, $corolla, -4, 3, 'personal', 'whatsapp', false, 'Agence Bafoussam'],
        ];
        foreach ($past as $n => [$c, $vehicle, $start, $days, $purpose, $source, $driver, $place]) {
            Carbon::setTestNow($at($start - 2, 10));
            $r = $request($c, $vehicle, ['purpose' => $purpose, 'source' => $source, 'resource_id' => $vehicle->id, 'with_driver' => $driver, 'starts_at' => $at($start, 8), 'ends_at' => $at($start + $days, 8)], $at($start - 2, 10));
            $desk->quote($r, $manager, ['deposit' => 200000, 'driver_rate' => $driver ? 20000 : null]);
            $booking = $desk->book($r->fresh(), $manager, ['resource_id' => $vehicle->id, 'deposit' => 200000, 'deposit_percent' => 50, 'pickup_place' => $place, 'driver_name' => $driver ? 'Chauffeur '.($n + 1) : null]);
            Carbon::setTestNow($at($start, 8));
            $desk->depart($booking, $manager, $vehicle->fresh()->mileage, 100);
            Carbon::setTestNow($at($start + $days, 9));
            $desk->giveBack($booking->fresh(), $manager, $vehicle->fresh()->mileage + $days * 150, $n === 2 ? 40 : 100, [], $n === 2 ? 'Jante avant gauche rayée' : null);
            // La dernière (Corolla, rendue hier) garde sa caution à clôturer.
            if ($n !== 7) {
                $desk->closeDeposit($booking->fresh(), $manager, $n === 2 ? 50000 : 0, $n === 2 ? 'damage' : null, $n === 2 ? 'Jante avant gauche rayée' : null);
                Booking::whereKey($booking->id)->update(['thanked_at' => $at($start + $days, 12)]);
            }
            $this->payAll($booking, $manager);
        }

        // En cours : transfert diaspora avec chauffeur, mission entreprise (retour en retard).
        foreach ([[9, $rav4, -1, 6, 'diaspora', true, 'Aéroport de Yaoundé-Nsimalen'], [10, $tucson, -5, 4, 'business', false, 'Siège client, Akwa']] as [$c, $vehicle, $start, $days, $purpose, $driver, $place]) {
            Carbon::setTestNow($at($start - 1, 10));
            $r = $request($c, $vehicle, ['purpose' => $purpose, 'source' => 'whatsapp', 'resource_id' => $vehicle->id, 'with_driver' => $driver, 'starts_at' => $at($start, 7), 'ends_at' => $at($start + $days, 7)], $at($start - 1, 10));
            $desk->quote($r, $manager, ['deposit' => 200000, 'driver_rate' => $driver ? 20000 : null]);
            $booking = $desk->book($r->fresh(), $manager, ['resource_id' => $vehicle->id, 'deposit' => 200000, 'deposit_percent' => 30, 'pickup_place' => $place, 'driver_name' => $driver ? 'Chauffeur Yaoundé' : null]);
            Carbon::setTestNow($at($start, 7));
            $desk->depart($booking, $manager, $vehicle->fresh()->mileage, 100);
        }
        Carbon::setTestNow($now);

        // À venir : mariage demain (minibus + VIP avec chauffeurs), transfert aéroport, mission 4x4.
        $upcoming = [
            [6, $coaster, 1, 1, 'event', 'facebook', true, 'Église puis salle de réception, Bonapriso'],
            [6, $vip, 1, 1, 'event', 'facebook', true, 'Domicile de la mariée, Bonanjo'],
            [8, $prado, 3, 10, 'diaspora', 'whatsapp', true, 'Aéroport de Douala'],
            [11, $hilux, 5, 14, 'business', 'phone', false, 'Agence Bafoussam'],
        ];
        foreach ($upcoming as [$c, $vehicle, $start, $days, $purpose, $source, $driver, $place]) {
            $r = $request($c, $vehicle, ['purpose' => $purpose, 'source' => $source, 'resource_id' => $vehicle->id, 'with_driver' => $driver, 'starts_at' => $at($start, 8), 'ends_at' => $at($start + $days, 8)], $now->copy()->subDays(4));
            $desk->quote($r, $manager, ['deposit' => 250000, 'driver_rate' => $driver ? 20000 : null]);
            $desk->book($r->fresh(), $manager, ['resource_id' => $vehicle->id, 'deposit' => 250000, 'deposit_percent' => 30, 'pickup_place' => $place, 'driver_name' => $driver ? 'Chauffeur Douala' : null]);
        }

        // Demandes ouvertes : nouvelles (Facebook, TikTok, WhatsApp) et devis en attente.
        $request(7, 'Yaoundé', ['purpose' => 'event', 'category' => 'berline', 'starts_at' => $at(12, 8), 'ends_at' => $at(13, 22), 'with_driver' => true, 'budget' => 400000, 'source' => 'facebook', 'notes' => '4 berlines pour un cortège de mariage'], $now->copy()->subHours(2));
        $request(3, 'Yaoundé', ['purpose' => 'personal', 'category' => 'suv', 'starts_at' => $at(2, 9), 'ends_at' => $at(4, 9), 'source' => 'tiktok'], $now->copy()->subHours(5));
        $request(5, 'Bafoussam', ['purpose' => 'personal', 'category' => 'citadine', 'starts_at' => $at(6, 9), 'ends_at' => $at(9, 9), 'source' => 'whatsapp'], $now->copy()->subHour());
        foreach ([[1, $tucson, 30, 'facebook'], [2, $vip2, 80, 'tiktok']] as [$c, $vehicle, $hours, $source]) {
            Carbon::setTestNow($now->copy()->subHours($hours));
            $r = $request($c, $vehicle, ['purpose' => 'personal', 'source' => $source, 'resource_id' => $vehicle->id, 'starts_at' => $at(20, 9), 'ends_at' => $at(23, 9)], $now->copy()->subHours($hours));
            $desk->quote($r, $manager, ['deposit' => 150000]);
            if ($hours > 72) {
                $r->update(['reminded_24_at' => $now->copy()->subHours($hours - 24)]);
            }
        }
        Carbon::setTestNow($now);

        // Perdues.
        foreach ([[0, 'price', 'Douala'], [4, 'unavailable', 'Bafoussam'], [3, 'no_answer', 'Yaoundé']] as [$c, $reason, $city]) {
            $r = $request($c, $city, ['purpose' => 'personal', 'category' => 'suv', 'source' => 'facebook', 'starts_at' => $at(-9, 9), 'ends_at' => $at(-7, 9)], $now->copy()->subDays(12));
            $desk->lose($r, $manager, $reason);
        }

        // Ventes : Fortuner suivi (essai demain, offre avec reprise), Pajero vendu en 3 fois.
        $sales = app(VehicleSales::class);
        $buyer = fn (int $c, BookableResource $vehicle) => VehicleSale::create(['organization_id' => $organization->id, 'store_id' => $vehicle->store_id, 'resource_id' => $vehicle->id,
            'customer_id' => $customers[$c]->id, 'status' => 'interested', 'created_by' => $manager->id]);
        $sales->move($buyer(0, $fortuner), $manager, 'test_drive', ['test_drive_at' => $at(1, 11)]);
        $sales->move($buyer(9, $fortuner), $manager, 'offer', ['offer_amount' => 18500000, 'trade_in_label' => 'Toyota Corolla 2012', 'trade_in_value' => 2500000]);
        $sales->sell($buyer(10, $sold), $manager, 9000000, 3, 'transfer');

        // Courses et transferts : forfaits, une course terminée hier, une en cours, deux à venir, une demande en ligne.
        $fares = collect([['Aéroport ↔ Centre-ville Douala', 15000, 60], ['Aéroport ↔ Bastos Yaoundé', 20000, 75], ['Gare ↔ Hôtel Bafoussam', 8000, 30]])
            ->map(fn ($f) => RideFare::create(['organization_id' => $organization->id, 'label' => $f[0], 'price' => $f[1], 'minutes' => $f[2]]));
        $rideDesk = app(RideDesk::class);
        $ride = fn (int $c, BookableResource $vehicle, Carbon $when, string $to, int $fare, ?string $flight = null) => $rideDesk->book($manager, [
            'store_id' => $vehicle->store_id, 'customer_id' => $customers[$c]->id, 'resource_id' => $vehicle->id, 'starts_at' => $when->toDateTimeString(),
            'ride_from' => 'Aéroport', 'ride_to' => $to, 'ride_passengers' => 2, 'ride_luggage' => 3, 'flight_number' => $flight, 'fare_id' => $fares[$fare]->id, 'driver_name' => 'Chauffeur Yaoundé',
        ]);
        Carbon::setTestNow($at(-2, 9));
        $done = $ride(9, $vip2, $at(-1, 10), 'Bastos, Yaoundé', 1, 'AF946');
        Carbon::setTestNow($at(-1, 11));
        foreach (['en_route', 'on_board', 'done'] as $step) {
            $rideDesk->move($done->fresh(), $manager, $step, 'orange_money');
        }
        Carbon::setTestNow($now);
        $live = $ride(3, $vip2, $now->copy()->subMinutes(20), 'Hôtel Hilton, Yaoundé', 1);
        $rideDesk->move($live, $manager, 'en_route');
        Carbon::setTestNow($now->copy()->subDays(2));
        $ride(8, $vip2, $at(1, 15), 'Bonapriso, Douala', 0, 'SN831');
        Carbon::setTestNow($now);
        $ride(2, $corolla, $now->copy()->addHours(3), 'Gare de Bafoussam', 2);
        $desk->createRequest($manager, ['store_id' => $stores['Douala']->id, 'customer_id' => $customers[5]->id, 'need' => 'ride', 'source' => 'online',
            'starts_at' => $now->copy()->addHour()->toDateTimeString(), 'ride_from' => 'Aéroport', 'ride_to' => 'Akwa, Douala', 'ride_passengers' => 1, 'ride_luggage' => 2, 'flight_number' => 'ET905']);

        // Lien public « Que souhaitez-vous ? » : demandes reçues en ligne et dépenses rattachées aux véhicules.
        $organization->forceFill(['online_page' => ['enabled' => true, 'choices' => OnlinePage::SECTOR_DEFAULTS['location']]])->save();
        foreach ([[4, 'rent', $rav4, 5, 'Visite de famille'], [7, 'event', null, 12, 'Cortège de 4 berlines, église puis salle'], [1, 'test_drive', $fortuner, 3, null]] as [$c, $type, $vehicle, $inDays, $note]) {
            $online = $desk->createRequest($manager, ['store_id' => ($vehicle ?? $vip)->store_id, 'customer_id' => $customers[$c]->id, 'source' => 'online',
                'need' => $type === 'test_drive' ? 'buy' : 'rent', 'purpose' => $type === 'event' ? 'event' : null, 'resource_id' => $vehicle?->id,
                'starts_at' => $at($inDays, 9)->toDateTimeString(), 'ends_at' => $type === 'rent' ? $at($inDays + 3, 9)->toDateTimeString() : null,
                'notes' => 'Lien en ligne · '.OnlinePage::CHOICES[$type]['label'].($note ? " : {$note}" : '')]);
            $online->forceFill(['created_at' => $now->copy()->subHours(3)])->save();
        }
        if (Schema::hasColumn('expenses', 'resource_id')) {
            foreach ([[$vip, 'Vidange et filtres', 85000], [$prado, 'Pneus avant', 240000], [$hiace, 'Réparation boîte de vitesses', 450000], [$rav4, 'Carburant transferts', 60000]] as [$vehicle, $label, $amount]) {
                Expense::create(['organization_id' => $organization->id, 'store_id' => $vehicle->store_id, 'resource_id' => $vehicle->id,
                    'spent_on' => today()->subDays(mt_rand(1, 10)), 'amount' => $amount, 'category' => 'entretien', 'label' => $label, 'user_id' => $manager->id]);
            }
        }

        // Tunnel de vente « Voitures de mariage » : en ligne, quelques visites et demandes par canal.
        $wedding = Funnels::createFromTemplate($organization, 'mariage', ['store_id' => $stores['Douala']->id, 'visits' => 184, 'budget' => 50000, 'is_active' => true]);
        foreach ([[6, 'facebook', 'Mariage le 13 décembre, 4 berlines'], [1, 'statut', 'Mariage civil, 2 véhicules'], [2, 'facebook', null], [5, 'qr', 'Cortège + minibus']] as $n => [$c, $src, $msg]) {
            $wr = $desk->createRequest($manager, ['store_id' => $stores['Douala']->id, 'customer_id' => $customers[$c]->id, 'source' => 'online', 'need' => 'rent', 'purpose' => 'event',
                'starts_at' => $at(20 + $n * 7, 9)->toDateTimeString(), 'notes' => 'Tunnel « Voitures de mariage » · étape Intéressé'.($msg ? " : {$msg}" : '')]);
            FunnelLead::create(['funnel_id' => $wedding->id, 'customer_id' => $customers[$c]->id, 'vehicle_request_id' => $wr->id, 'source' => $src, 'step' => min(2, $n)])
                ->forceFill(['created_at' => $now->copy()->subDays(4 - $n)])->save();
        }

        // Pipeline commercial : commerciaux, objectifs, cartes digitales, prospects et clients.
        Carbon::setTestNow();
        $this->pipeline = (new YamooDrivePipelineSeeder)->run($organization->fresh(), $stores, $manager, $password);
        // Le directeur n'était connecté que pour les services de la démo : on le déconnecte
        // (sinon un test du même processus garderait une fiche entreprise périmée).
        Auth::forgetUser();
    }

    /** Chiffres du pipeline créé (affichés par demo:yamoo-drive). @var array<string, int> */
    public array $pipeline = [];

    /** Locations passées : acompte et solde payés. */
    private function payAll(Booking $booking, User $manager): void
    {
        $invoicing = app(Invoicing::class);
        $quote = $booking->quote()->first();
        foreach ($quote->invoices()->whereIn('status', ['open', 'partial'])->get() as $invoice) {
            $invoicing->pay($invoice, $manager, $invoice->balance(), 'orange_money');
        }
        if (! $quote->invoices()->whereIn('kind', ['balance', 'full'])->exists()) {
            $balance = $invoicing->invoiceQuote($quote->fresh(), $manager);
            $invoicing->pay($balance, $manager, $balance->balance(), 'mtn_momo');
        }
    }
}
