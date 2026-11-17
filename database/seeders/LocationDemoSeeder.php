<?php

namespace Database\Seeders;

use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RentalAccount;
use App\Modules\Location\Models\VehicleSale;
use App\Modules\Location\Services\RentalDesk;
use App\Modules\Location\Services\VehicleSales;
use App\Services\CustomerLifecycle;
use App\Support\SectorPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Loueur fictif « Libreville Location Auto » : 8 véhicules, clients
 * (particuliers, événements, diaspora, entreprises), demandes à toutes les
 * étapes, réservations (à venir, en cours, rendues), une vente.
 * Données de test uniquement ; rejouable.
 */
class LocationDemoSeeder extends Seeder
{
    public const SLUG = 'demo-location-vehicules';

    public const DEFAULT_PASSWORD = 'Demo-Location-2026';

    public function run(): void
    {
        mt_srand(1411);
        $password = env('DEMO_PASSWORD', self::DEFAULT_PASSWORD);
        $now = now()->startOfHour();

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Libreville Location Auto', 'slug' => self::SLUG, 'sector' => 'location', 'currency' => 'XAF', 'timezone' => 'Africa/Libreville',
            'whatsapp_api_enabled' => false, 'status' => Organization::STATUS_ACTIVE, 'plan' => 'equipe',
        ]);
        SectorPresets::apply($organization, true);
        $organization->save();

        Role::seedDefaultsFor($organization);
        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Libreville Location Auto']);
        $store = Store::create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Agence Glass', 'city' => 'Libreville']);
        $manager = User::create(['name' => 'Hervé Nzé (directeur)', 'email' => 'directeur@demo-location.test', 'password' => $password, 'organization_id' => $organization->id,
            'role_id' => Role::where('organization_id', $organization->id)->where('name', 'Super administrateur')->value('id')]);
        Auth::setUser($manager);

        $v = fn (string $name, string $plate, string $category, int $day, int $km, array $extra = []) => BookableResource::create([
            'organization_id' => $organization->id, 'store_id' => $store->id, 'name' => $name, 'reference' => $plate, 'kind' => 'vehicule', 'category' => $category,
            'active' => true, 'status' => 'available', 'mileage' => $km, 'price_day' => $day, 'price_week' => $day * 6, 'price_month' => $day * 22,
            'insurance_until' => today()->addMonths(mt_rand(2, 10)), 'inspection_until' => today()->addMonths(mt_rand(1, 12)), ...$extra,
        ]);
        $prado = $v('Toyota Prado', 'GA-412-AB', '4x4', 75000, 68000);
        $hilux = $v('Toyota Hilux', 'GA-118-CD', '4x4', 60000, 91000, ['service_due_km' => 91300]);
        $rav4 = $v('Toyota RAV4', 'GA-776-EF', 'suv', 45000, 42000);
        $tucson = $v('Hyundai Tucson', 'GA-305-GH', 'suv', 40000, 55000, ['insurance_until' => today()->addDays(9)]);
        $corolla = $v('Toyota Corolla', 'GA-920-IJ', 'berline', 30000, 73000);
        $yaris = $v('Toyota Yaris', 'GA-233-KL', 'citadine', 22000, 38000);
        $hiace = $v('Toyota Hiace (15 places)', 'GA-664-MN', 'minibus', 90000, 120000, ['status' => 'maintenance']);
        $fortuner = $v('Toyota Fortuner', 'GA-508-OP', '4x4', 70000, 104000, ['status' => 'for_sale', 'sale_price' => 16500000, 'for_sale_since' => today()->subDays(21)]);

        $names = ['Aline Moussavou', 'Paul Obame', 'Grâce Mba', 'Jean-Claude Ella', 'Sandrine Ondo', 'Rodrigue Mbadinga', 'Laetitia Koumba', 'Fabrice Engone',
            'Mireille Bouanga', 'Serge Oyono', 'Patricia Mengue (Paris)', 'Didier Ntoutoume (Montréal)', 'Estelle Nkoghe', 'Brice Mabiala', 'SARL Ogooué Mines', 'Total Services Gabon'];
        $customers = [];
        CustomerLifecycle::batch(function () use ($names, $store, &$customers) {
            foreach ($names as $i => $name) {
                $customers[] = Contact::create(['store_id' => $store->id, 'name' => $name, 'phone_e164' => '2417'.str_pad((string) (7400000 + $i * 731), 7, '0', STR_PAD_LEFT),
                    'consent_status' => 'opt_in', 'consent_source' => 'manuel'])->customer;
            }
        });
        foreach ([14, 15] as $i) {
            $customers[$i]->forceFill(['type' => 'business', 'company_name' => $customers[$i]->name])->save();
        }
        RentalAccount::create(['organization_id' => $organization->id, 'customer_id' => $customers[14]->id, 'discount_percent' => 15, 'notes' => 'Missions sur site, facturation fin de mois']);
        RentalAccount::create(['organization_id' => $organization->id, 'customer_id' => $customers[15]->id, 'day_rate' => 50000, 'notes' => 'Contrat cadre 2026']);

        $desk = app(RentalDesk::class);
        $request = fn (int $c, array $data, string $created) => tap($desk->createRequest($manager, ['store_id' => $store->id, 'customer_id' => $customers[$c]->id, 'need' => 'rent', ...$data]),
            fn ($r) => $r->forceFill(['created_at' => Carbon::parse($created)])->save());
        $at = fn (int $days, int $hour) => $now->copy()->addDays($days)->setTime($hour, 0);

        // Réservations rendues (historique : CA, cautions clôturées, remerciements).
        $past = [[1, $corolla, -40, 3, 'personal'], [2, $rav4, -33, 4, 'event'], [14, $hilux, -28, 7, 'business'], [10, $prado, -20, 10, 'diaspora'],
            [4, $yaris, -15, 2, 'personal'], [15, $prado, -9, 3, 'business'], [5, $tucson, -6, 2, 'event'], [6, $corolla, -4, 3, 'personal']];
        foreach ($past as $n => [$c, $vehicle, $start, $days, $purpose]) {
            $this->travel($at($start - 2, 10));
            $r = $request($c, ['purpose' => $purpose, 'resource_id' => $vehicle->id, 'starts_at' => $at($start, 9), 'ends_at' => $at($start + $days, 9), 'source' => ['whatsapp', 'facebook', 'phone', 'agency'][$n % 4]], $at($start - 2, 10));
            $desk->quote($r, $manager, ['deposit' => 150000]);
            $booking = $desk->book($r->fresh(), $manager, ['resource_id' => $vehicle->id, 'deposit' => 150000, 'deposit_percent' => 50, 'pickup_place' => 'Agence Glass']);
            $this->travel($at($start, 9));
            $desk->depart($booking, $manager, $vehicle->fresh()->mileage, 100);
            $this->travel($at($start + $days, 10));
            $desk->giveBack($booking->fresh(), $manager, $vehicle->fresh()->mileage + $days * 120, $n === 3 ? 50 : 100, [], $n === 3 ? 'Rayure pare-choc arrière' : null);
            if ($start + $days < -1) {
                $desk->closeDeposit($booking->fresh(), $manager, $n === 3 ? 40000 : 0, $n === 3 ? 'damage' : null, $n === 3 ? 'Rayure pare-choc arrière' : null);
                Booking::whereKey($booking->id)->update(['thanked_at' => $at($start + $days, 12)]);
            }
            $this->paySale($booking, $manager);
        }
        $this->travel($now);

        // En cours : un véhicule sorti, un retour en retard.
        foreach ([[3, $hilux, -2, 4, 'business'], [12, $rav4, -5, 4, 'event']] as [$c, $vehicle, $start, $days, $purpose]) {
            $this->travel($at($start - 1, 10));
            $r = $request($c, ['purpose' => $purpose, 'resource_id' => $vehicle->id, 'starts_at' => $at($start, 8), 'ends_at' => $at($start + $days, 8), 'with_driver' => $c === 3], $at($start - 1, 10));
            $desk->quote($r, $manager, ['deposit' => 200000, 'driver_rate' => $c === 3 ? 15000 : null]);
            $booking = $desk->book($r->fresh(), $manager, ['resource_id' => $vehicle->id, 'deposit' => 200000, 'deposit_percent' => 30, 'pickup_place' => 'Aéroport Léon-Mba', 'driver_name' => $c === 3 ? 'Rodrigue (chauffeur)' : null]);
            $this->travel($at($start, 8));
            $desk->depart($booking, $manager, $vehicle->fresh()->mileage, 100);
        }
        $this->travel($now);

        // À venir : demain (rappel de la veille) et la semaine prochaine.
        foreach ([[7, $prado, 1, 3, 'event', 'Mariage à Owendo'], [11, $corolla, 4, 14, 'diaspora', 'Hôtel Radisson'], [15, $tucson, 6, 5, 'business', 'Siège Total, Port-Gentil']] as [$c, $vehicle, $start, $days, $purpose, $place]) {
            $r = $request($c, ['purpose' => $purpose, 'resource_id' => $vehicle->id, 'starts_at' => $at($start, 9), 'ends_at' => $at($start + $days, 9), 'with_driver' => $purpose === 'event'], $now->copy()->subDays(3));
            $desk->quote($r, $manager, ['deposit' => 150000, 'driver_rate' => $purpose === 'event' ? 15000 : null]);
            $desk->book($r->fresh(), $manager, ['resource_id' => $vehicle->id, 'deposit' => 150000, 'deposit_percent' => 30, 'pickup_place' => $place, 'driver_name' => $purpose === 'event' ? 'Jean (chauffeur)' : null]);
        }

        // Demandes ouvertes : nouvelles et devis en attente (relances 24 h / 72 h).
        $request(8, ['purpose' => 'event', 'category' => 'suv', 'starts_at' => $at(10, 8), 'ends_at' => $at(12, 20), 'with_driver' => true, 'budget' => 150000, 'source' => 'facebook', 'notes' => '3 SUV pour un cortège'], $now->copy()->subHours(3));
        $request(13, ['purpose' => 'personal', 'category' => 'citadine', 'starts_at' => $at(2, 9), 'ends_at' => $at(5, 9), 'source' => 'whatsapp'], $now->copy()->subHours(1));
        foreach ([[9, $yaris, 30], [0, $rav4, 80]] as [$c, $vehicle, $hours]) {
            $this->travel($now->copy()->subHours($hours));
            $r = $request($c, ['purpose' => 'personal', 'resource_id' => $vehicle->id, 'starts_at' => $at(8, 9), 'ends_at' => $at(11, 9)], $now->copy()->subHours($hours));
            $desk->quote($r, $manager, ['deposit' => 100000]);
            if ($hours > 72) {
                $r->update(['reminded_24_at' => $now->copy()->subHours($hours - 24)]);
            }
        }
        $this->travel($now);

        // Demandes perdues (raisons pour le tableau de bord).
        foreach ([[1, 'price'], [5, 'unavailable'], [6, 'no_answer']] as [$c, $reason]) {
            $r = $request($c, ['purpose' => 'personal', 'category' => 'berline', 'starts_at' => $at(-10, 9), 'ends_at' => $at(-8, 9)], $now->copy()->subDays(12));
            $desk->lose($r, $manager, $reason);
        }

        // Vente : Fortuner, deux acheteurs (dont un essai programmé) ; une vente conclue en 3 fois.
        $sales = app(VehicleSales::class);
        $sale = fn (int $c, BookableResource $vehicle) => VehicleSale::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'resource_id' => $vehicle->id, 'customer_id' => $customers[$c]->id, 'status' => 'interested', 'created_by' => $manager->id]);
        $fortuner->forceFill(['sale_token' => Str::random(32), 'sale_description' => 'Toyota Fortuner 2019, diesel, boîte automatique, 7 places. Entretien suivi à l’agence, pneus neufs.'])->save();
        $s1 = $sale(2, $fortuner);
        $sales->move($s1, $manager, 'test_drive', ['test_drive_at' => $at(2, 11)]);
        $s2 = $sale(13, $fortuner);
        $sales->move($s2, $manager, 'offer', ['offer_amount' => 15500000, 'trade_in_label' => 'Toyota Corolla 2011', 'trade_in_value' => 2500000]);
        $old = $v('Mitsubishi Pajero', 'GA-090-QR', '4x4', 55000, 160000, ['status' => 'for_sale', 'sale_price' => 9000000, 'for_sale_since' => today()->subDays(45)]);
        $s3 = $sale(14, $old);
        $sales->sell($s3, $manager, 8500000, 3, 'transfer');
    }

    private function travel(Carbon $when): void
    {
        Carbon::setTestNow($when);
    }

    /** Solde payé pour les locations passées : la facture de solde est réglée. */
    private function paySale(Booking $booking, User $manager): void
    {
        $invoicing = app(Invoicing::class);
        $quote = $booking->quote()->first();
        foreach ($quote->invoices()->whereIn('status', ['open', 'partial'])->get() as $invoice) {
            $invoicing->pay($invoice, $manager, $invoice->balance(), 'cash');
        }
        if ($quote->fresh()->status === 'invoiced' && ! $quote->invoices()->whereIn('kind', ['balance', 'full'])->exists()) {
            $balance = $invoicing->invoiceQuote($quote->fresh(), $manager);
            $invoicing->pay($balance, $manager, $balance->balance(), 'airtel_money');
        }
    }
}
