<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Modules\Depenses\Models\Expense;
use App\Modules\Facturation\Services\Invoicing;
use App\Modules\Stock\Models\StockItem;
use App\Modules\Stock\Services\Stock;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Support\SectorPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Opticien fictif « Optique Lumière » (Libreville) : examens de vue,
 * montures et verres en stock, devis avec acompte, factures, caisse du
 * jour et dépenses. Données de test uniquement ; rejouable.
 */
class OptiqueDemoSeeder extends Seeder
{
    public const SLUG = 'demo-optique-lumiere';

    public const DEFAULT_PASSWORD = 'Demo-Optique-2026';

    public function run(): void
    {
        mt_srand(2611);
        $password = env('DEMO_PASSWORD', self::DEFAULT_PASSWORD);
        $today = today();

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Optique Lumière', 'slug' => self::SLUG, 'sector' => 'optique', 'currency' => 'XAF', 'timezone' => 'Africa/Libreville',
            'whatsapp_api_enabled' => false, 'status' => Organization::STATUS_ACTIVE, 'plan' => 'equipe',
        ]);
        SectorPresets::apply($organization, true);
        $organization->setModules([...$organization->enabledModules(), 'caisse', 'depenses', 'tranches'], reviewed: true);
        $organization->save();

        Role::seedDefaultsFor($organization);
        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Optique Lumière']);
        $store = Store::create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Optique Lumière Louis', 'city' => 'Libreville']);
        $manager = User::create(['name' => 'Dr Sylvie Ndong (opticienne)', 'email' => 'gerante@demo-optique.test', 'password' => $password, 'organization_id' => $organization->id,
            'role_id' => Role::where('organization_id', $organization->id)->where('name', 'Super administrateur')->value('id')]);
        Auth::setUser($manager);

        $item = fn (string $type, string $name, int $price, ?int $return = null) => CatalogItem::create([
            'organization_id' => $organization->id, 'type' => $type, 'name' => $name, 'price' => $price, 'active' => true,
            'duration_minutes' => $type === 'service' ? 30 : null, 'bookable_online' => $type === 'service', 'return_after_days' => $return,
        ]);
        $exam = $item('service', 'Examen de vue', 15000, 365);
        $control = $item('service', 'Contrôle et ajustement', 5000);
        $lenses = $item('service', 'Adaptation lentilles', 10000);
        $products = [
            'frame' => [$item('product', 'Monture Ray-Ban', 65000), 6, 3, 32000],
            'frame_kid' => [$item('product', 'Monture enfant', 25000), 3, 3, 11000],
            'single' => [$item('product', 'Verres unifocaux (paire)', 40000), 14, 4, 15000],
            'prog' => [$item('product', 'Verres progressifs (paire)', 120000), 2, 2, 55000],
            'contact' => [$item('product', 'Lentilles mensuelles (boîte)', 18000), 0, 4, 8000],
            'case' => [$item('product', 'Étui et chiffon', 3000), 40, 10, 800],
        ];
        $stock = app(Stock::class);
        foreach ($products as [$product, $quantity, $min, $cost]) {
            $stockItem = StockItem::create(['organization_id' => $organization->id, 'catalog_item_id' => $product->id, 'min_quantity' => $min, 'cost_price' => $cost, 'supplier' => 'Optic Import Libreville']);
            $stock->count($stockItem, $quantity + 12, $manager);
        }

        $names = ['Grâce Mba', 'Paul Obame', 'Aline Moussavou', 'Jean-Claude Ella', 'Sandrine Ondo', 'Rodrigue Mbadinga', 'Laetitia Koumba', 'Fabrice Engone', 'Mireille Bouanga',
            'Serge Oyono', 'Patricia Mengue', 'Didier Ntoutoume', 'Estelle Nkoghe', 'Brice Mabiala', 'Nadège Ivanga', 'Arnaud Ekomie', 'Joëlle Ngoma', 'Yannick Bekale',
            'Carine Ondo Mba', 'Thierry Nzamba', 'Murielle Assele', 'Ghislain Mouele', 'Pélagie Mintsa', 'Alain Biyogo', 'Inès Mavoungou', 'Steeve Nang', 'Cabinet Mba & Associés', 'Lucie Abessolo'];
        $customers = [];
        CustomerLifecycle::batch(function () use ($names, $store, &$customers) {
            foreach ($names as $i => $name) {
                $customers[] = Contact::create(['store_id' => $store->id, 'name' => $name, 'phone_e164' => '2417'.str_pad((string) (6200000 + $i * 917), 7, '0', STR_PAD_LEFT),
                    'consent_status' => 'opt_in', 'consent_source' => 'manuel'])->customer;
            }
        });

        // Ventes des 3 derniers mois : examen, puis lunettes ou lentilles.
        $methods = ['cash', 'cash', 'airtel_money', 'moov_money', 'card'];
        $sale = function ($customer, $when, array $lines) use ($store, $methods) {
            $contact = $customer->contacts()->first();
            $total = array_sum(array_map(fn ($l) => $l->price, $lines));
            $purchase = Purchase::create(['contact_id' => $contact->id, 'store_id' => $store->id, 'amount' => $total, 'subtotal' => $total, 'discount_amount' => 0, 'quantity' => count($lines),
                'currency' => 'XAF', 'purchased_at' => $when, 'payment_method' => $methods[mt_rand(0, 4)], 'type' => Purchase::TYPE_SALE, 'source' => 'manual']);
            foreach ($lines as $line) {
                PurchaseItem::create(['purchase_id' => $purchase->id, 'catalog_item_id' => $line->id, 'type' => $line->type, 'name' => $line->name, 'quantity' => 1, 'unit_price' => $line->price, 'amount' => $line->price, 'discount_amount' => 0]);
            }
        };
        foreach ($customers as $i => $customer) {
            $when = $today->copy()->subDays(mt_rand(2, 90))->setTime(mt_rand(9, 17), mt_rand(0, 59));
            $lines = [$exam];
            if ($i % 3 === 0) {
                $lines = [...$lines, $products['frame'][0], $products['single'][0], $products['case'][0]];
            } elseif ($i % 3 === 1) {
                $lines[] = $products['contact'][0];
            }
            $sale($customer, $when, $lines);
        }
        // Aujourd'hui : quatre clients servis.
        foreach ([[2, [$control]], [5, [$exam, $products['case'][0]]], [9, [$lenses]], [14, [$products['frame_kid'][0], $products['single'][0]]]] as [$i, $lines]) {
            $sale($customers[$i], $today->copy()->setTime(9 + $i % 6, 15), $lines);
        }

        // Rendez-vous à venir.
        foreach ([[1, 1, 10], [3, 1, 15], [7, 2, 11], [11, 3, 9]] as [$i, $days, $hour]) {
            Appointment::create(['store_id' => $store->id, 'customer_id' => $customers[$i]->id, 'created_by' => $manager->id, 'starts_at' => $today->copy()->addDays($days)->setTime($hour, 0),
                'duration_minutes' => 30, 'service' => 'Examen de vue', 'status' => 'confirmed', 'source' => 'manual']);
        }

        // Devis et factures.
        $invoicing = app(Invoicing::class);
        $line = fn ($p, $q = 1) => ['label' => $p->name, 'quantity' => $q, 'unit_price' => $p->price, 'catalog_item_id' => $p->id];
        $q1 = $invoicing->create($manager, ['type' => 'quote', 'customer_id' => $customers[0]->id, 'store_id' => $store->id, 'title' => 'Lunettes progressives',
            'issued_on' => $today->copy()->subDays(6)->toDateString(), 'lines' => [$line($products['frame'][0]), $line($products['prog'][0])], 'discount_amount' => 10000,
            'notes' => 'Paiement possible en 2 fois. Délai de fabrication des verres : 7 jours.']);
        $invoicing->markSent($q1, $manager);
        $q2 = $invoicing->create($manager, ['type' => 'quote', 'customer_id' => $customers[26]->id, 'store_id' => $store->id, 'title' => 'Visite de dépistage pour 12 salariés',
            'lines' => [['label' => 'Examen de vue sur site (par salarié)', 'quantity' => 12, 'unit_price' => 12000], ['label' => 'Déplacement', 'quantity' => 1, 'unit_price' => 20000]]]);
        $invoicing->markSent($q2, $manager);
        $q3 = $invoicing->create($manager, ['type' => 'quote', 'customer_id' => $customers[4]->id, 'store_id' => $store->id, 'title' => 'Lunettes enfant',
            'issued_on' => $today->copy()->subDays(10)->toDateString(), 'lines' => [$line($products['frame_kid'][0]), $line($products['single'][0])]]);
        $invoicing->markSent($q3, $manager);
        $invoicing->accept($q3, 'Sandrine Ondo');
        $deposit = $invoicing->invoiceQuote($q3, $manager, 50);
        $invoicing->pay($deposit, $manager, $deposit->total, 'airtel_money', $today->copy()->subDays(9)->setTime(11, 0));
        $late = $invoicing->create($manager, ['type' => 'invoice', 'customer_id' => $customers[8]->id, 'store_id' => $store->id, 'title' => 'Lunettes de soleil correctrices',
            'issued_on' => $today->copy()->subDays(25)->toDateString(), 'due_on' => $today->copy()->subDays(10)->toDateString(), 'lines' => [$line($products['frame'][0]), $line($products['single'][0])]]);
        $invoicing->pay($late, $manager, 50000, 'cash', $today->copy()->subDays(20)->setTime(10, 0));

        // Dépenses du mois.
        foreach ([[1, 'loyer', 'Loyer de la boutique', 250000, 'transfer', 'SCI Louis'], [3, 'energie', 'Facture SEEG', 38000, 'cash', 'SEEG'],
            [5, 'fournitures', 'Réassort verres unifocaux', 150000, 'transfer', 'Optic Import Libreville'], [6, 'marketing', 'Publicité Facebook', 25000, 'card', 'Meta'],
            [8, 'transport', 'Livraison commande verres', 7000, 'cash', null], [10, 'telecom', 'Internet Airtel', 30000, 'airtel_money', 'Airtel']] as [$day, $category, $label, $amount, $method, $supplier]) {
            if ($today->copy()->startOfMonth()->addDays($day - 1)->lte($today)) {
                Expense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'spent_on' => $today->copy()->startOfMonth()->addDays($day - 1), 'amount' => $amount,
                    'category' => $category, 'label' => $label, 'supplier' => $supplier, 'method' => $method, 'user_id' => $manager->id]);
            }
        }
        Expense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'spent_on' => $today, 'amount' => 4000, 'category' => 'transport', 'label' => 'Taxi livraison client', 'method' => 'cash', 'user_id' => $manager->id]);

        app(CustomerScoring::class)->refreshOrganization($organization);
    }
}
