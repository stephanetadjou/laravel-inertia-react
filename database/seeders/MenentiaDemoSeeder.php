<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\ProspectStageChange;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\SalesTarget;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Services\Installments;
use App\Services\PhoneNormalizer;
use App\Services\ProspectPipeline;
use App\Services\Subscriptions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Entreprise fictive « Menentia Solutions digitales » (Libreville, services
 * professionnels) : démonstration du suivi des prospects et des commerciaux.
 * Un directeur, deux commerciaux, ~40 prospects (instituts, salles de sport,
 * clubs, cabinets, garages), 12 clients abonnés, primes. Données fictives.
 *
 * Mot de passe : variable DEMO_PASSWORD, sinon « Demo-Menentia-2026 ».
 */
class MenentiaDemoSeeder extends Seeder
{
    public const SLUG = 'demo-menentia';

    public const DEFAULT_PASSWORD = 'Demo-Menentia-2026';

    private const BUSINESSES = ['Institut Belle Peau', 'Salon Afro Chic', 'Onglerie Perle', 'Spa Akanda', 'Barbier du Port', 'Fitness Glass', 'Salle Énergie Owendo', 'Club Judo Lalala', 'École de danse Nzeng', 'Club Tennis Batterie IV',
        'Cabinet Dentaire Sourire', 'Clinique Ophta Plus', 'Kiné Mont-Bouët', 'Cabinet Avocats Ndong', 'Cabinet Comptable Essono', 'Garage Auto Express', 'Garage Mécanique Owendo', 'Lavage Auto Akébé', 'Agence Immobilière Océan', 'Auto-école Victoire',
        'Institut Glamour', 'Salle CrossFit PK8', 'Club Natation Plein Ciel', 'Centre Yoga Zen', 'Cabinet Médical Louis', 'Laboratoire Santé Plus', 'Pressing Royal', 'Garage Pneus Nzeng', 'Centre Formation Pro', 'Coiffure Élite',
        'Spa Océane', 'Salle Gym Akanda', 'Club Foot Junior', 'Cabinet Kiné Glass', 'Optique Vision', 'Garage Carrosserie Lalala', 'Onglerie Diamant', 'Barber Shop Libreville', 'Cabinet Notaire Mba', 'Studio Photo Lumière'];

    private const CONTACTS = ['Mireille', 'Paul', 'Sylvie', 'Hervé', 'Nadège', 'Patrick', 'Clarisse', 'Serge', 'Estelle', 'Rodrigue'];

    public function run(): void
    {
        mt_srand(2027);
        $password = env('DEMO_PASSWORD', self::DEFAULT_PASSWORD);

        if ($previous = Organization::where('slug', self::SLUG)->first()) {
            User::where('organization_id', $previous->id)->delete();
            $previous->delete();
        }

        $organization = Organization::create([
            'name' => 'Menentia Solutions digitales', 'slug' => self::SLUG, 'sector' => 'pro', 'currency' => 'XAF', 'timezone' => 'Africa/Libreville',
            'whatsapp_api_enabled' => false, 'status' => Organization::STATUS_ACTIVE, 'plan' => 'premium',
            'report_settings' => ['enabled' => true, 'recipients' => ['directeur@demo-menentia.test'], 'send_at' => '19:00', 'monthly_target' => 3000000],
        ]);
        $organization->setModules(['rdv', 'catalogue', 'prospects', 'abonnements', 'tranches'], [
            'abonnements' => ['period' => 'month', 'season_start' => today()->subYear()->toDateString(), 'season_end' => today()->addYear()->toDateString()],
        ], reviewed: true);
        $organization->save();

        Role::seedDefaultsFor($organization);
        $sales = Role::ensureSalesRole($organization);
        $brand = Brand::create(['organization_id' => $organization->id, 'name' => 'Menentia']);
        $store = Store::create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => 'Menentia Libreville', 'city' => 'Libreville']);

        $director = User::create(['name' => 'Stéphane Fotsing (directeur)', 'email' => 'directeur@demo-menentia.test', 'password' => $password, 'organization_id' => $organization->id,
            'role_id' => Role::where('organization_id', $organization->id)->where('name', 'Super administrateur')->value('id')]);
        $reps = [
            User::create(['name' => 'Patricia Mintsa', 'email' => 'commercial1@demo-menentia.test', 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $sales->id, 'referral_code' => 'PATRI1']),
            User::create(['name' => 'Junior Obiang', 'email' => 'commercial2@demo-menentia.test', 'password' => $password, 'organization_id' => $organization->id, 'role_id' => $sales->id, 'referral_code' => 'JUNIO2']),
        ];
        Auth::setUser($director);

        foreach ($reps as $rep) {
            SalesTarget::create(['organization_id' => $organization->id, 'user_id' => $rep->id, 'month' => today()->format('Y-m'), 'visits' => 80, 'demos' => 60, 'trials' => 24, 'customers' => 7, 'revenue' => 300000]);
        }

        // Catalogue par catégories et formules SaaS.
        $catalog = [
            'Abonnements SaaS' => [['Formule Solo', 9900], ['Formule Équipe', 25000], ['Formule Premium', 50000], ['Mise en route', 25000]],
            'Développement' => [['Site vitrine', 350000], ['Application mobile', 1500000], ['Boutique en ligne', 600000]],
            'Marketing digital' => [['Gestion des réseaux sociaux (mois)', 150000], ['Campagne WhatsApp ciblée', 75000]],
            'Analyse de données' => [['Audit des données clients', 200000], ['Tableau de bord sur mesure', 350000]],
            'Formations' => [['Formation CRM (demi-journée)', 50000], ['Formation marketing WhatsApp', 75000]],
        ];
        foreach ($catalog as $category => $items) {
            $cat = Category::create(['organization_id' => $organization->id, 'name' => $category]);
            foreach ($items as [$name, $price]) {
                CatalogItem::create(['organization_id' => $organization->id, 'type' => CatalogItem::TYPE_SERVICE, 'name' => $name, 'category_id' => $cat->id, 'price' => $price, 'duration_minutes' => 60, 'active' => true]);
            }
        }
        $saas = CatalogItem::where('organization_id', $organization->id)->where('name', 'Formule Équipe')->value('id');
        $plans = collect(['Solo' => 9900, 'Équipe' => 25000, 'Premium' => 50000])->map(fn ($price, $name) => Plan::create([
            'organization_id' => $organization->id, 'name' => $name, 'catalog_item_id' => $saas, 'price' => $price, 'period' => 'month',
            'fees' => [['label' => 'Mise en route', 'amount' => 25000]],
        ]));

        $pipeline = ProspectPipeline::for($organization);
        $subscriptions = app(Subscriptions::class);
        $installments = app(Installments::class);
        // Étape et ancienneté dans l'étape (jours) : certains en retard de relance.
        $plan = [
            ...array_fill(0, 8, 'prospect'), ...array_fill(0, 6, 'visite'), ...array_fill(0, 5, 'demo'), ...array_fill(0, 5, 'essai'),
            ...array_fill(0, 12, 'client'),
        ];
        $lost = ['Prix trop élevé pour le moment', 'Utilise déjà un autre outil', 'Pas le bon moment, à recontacter en janvier', 'Pas de smartphone dans l’équipe'];

        CustomerLifecycle::batch(function () use ($store, $reps, $director, $pipeline, $plan, $plans, $subscriptions, $installments, $lost) {
            foreach (self::BUSINESSES as $i => $business) {
                $rep = $reps[$i % 2];
                $stage = $plan[$i] ?? 'perdu';
                $phone = PhoneNormalizer::normalize('0'.($i % 2 ? '77' : '66').sprintf('%06d', 210000 + $i * 53));
                $customer = Contact::create(['store_id' => $store->id, 'name' => self::CONTACTS[$i % 10].' ('.$business.')', 'phone_e164' => $phone, 'consent_status' => 'opt_in', 'consent_source' => 'manuel'])->customer;
                $customer->forceFill(['company_name' => $business, 'owner_user_id' => $rep->id, 'type' => Customer::TYPE_BUSINESS, 'contact_name' => self::CONTACTS[$i % 10]])->save();
                $age = mt_rand(1, 9);

                // Parcours jusqu'à l'étape : passages datés, visites et démos honorées dans l'agenda.
                $path = match ($stage) {
                    'prospect' => ['prospect'],
                    'visite' => ['prospect', 'visite'],
                    'demo' => ['prospect', 'visite', 'demo'],
                    'essai', 'client' => ['prospect', 'visite', 'demo', 'essai'],
                    default => ['prospect', 'visite', 'demo', 'perdu'],
                };
                foreach ($path as $step => $key) {
                    $pipeline->move($customer, $key, $rep, $key === 'perdu' ? $lost[$i % 4] : null);
                    $at = now()->subDays($age + (count($path) - 1 - $step) * 6)->setTime(10, 0);
                    $customer->forceFill(['prospect_stage_at' => $at])->save();
                    ProspectStageChange::where('customer_id', $customer->id)->where('to_stage', $key)->update(['changed_at' => $at]);
                    if (in_array($key, ['visite', 'demo'], true)) {
                        Appointment::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'starts_at' => $at, 'duration_minutes' => $key === 'demo' ? 45 : 30,
                            'service' => $key === 'demo' ? 'Démo Menentia' : 'Visite commerciale', 'status' => 'done', 'kind' => $key, 'created_by' => $rep->id, 'staff_user_id' => $rep->id]);
                    }
                }

                // Prochaines visites et démos de la semaine.
                if (in_array($stage, ['prospect', 'visite'], true) && $i % 3 === 0) {
                    Appointment::create(['store_id' => $store->id, 'customer_id' => $customer->id, 'starts_at' => now()->addDays(1 + $i % 4)->setTime(9 + $i % 8, 0), 'duration_minutes' => 45,
                        'service' => $stage === 'prospect' ? 'Visite commerciale' : 'Démo Menentia', 'status' => 'planned', 'kind' => $stage === 'prospect' ? 'visite' : 'demo', 'created_by' => $rep->id, 'staff_user_id' => $rep->id]);
                }

                if ($stage !== 'client') {
                    continue;
                }

                // Client : abonnement depuis 1 à 3 mois ; paiements, retards, renouvellements proches.
                $months = 1 + $i % 3;
                $subscription = $subscriptions->create($director, [
                    'customer_id' => $customer->id, 'plan_id' => $plans->values()[$i % 3]->id, 'store_id' => $store->id,
                    'starts_on' => today()->subMonths($months)->subDays($i % 5)->toDateString(),
                    'ends_on' => $i % 4 === 0 ? today()->addDays(18)->toDateString() : today()->addMonths(10)->toDateString(),
                ]);
                $late = $i % 5 === 1;
                foreach (Installment::where('payment_schedule_id', $subscription->payment_schedule_id)->where('due_on', '<', today()->toDateString())->orderBy('due_on')->get() as $n => $due) {
                    if ($late && $n > 1) {
                        break;
                    }
                    $installments->pay($due, $director, ['airtel_money', 'moov_money', 'cash'][$i % 3], $due->due_on->copy()->addDays($i % 3)->setTime(11, 0)->min(now()), refreshScores: false);
                }

                // Quelques prestations vendues en plus.
                if ($i % 3 === 0) {
                    $purchase = Purchase::create(['contact_id' => $customer->contacts()->first()->id, 'store_id' => $store->id, 'amount' => 50000, 'quantity' => 1, 'purchased_at' => now()->subDays(10 + $i),
                        'payment_method' => 'airtel_money', 'type' => Purchase::TYPE_SALE, 'source' => 'manual']);
                    PurchaseItem::create(['purchase_id' => $purchase->id, 'type' => CatalogItem::TYPE_SERVICE, 'name' => 'Formation CRM (demi-journée)', 'quantity' => 1, 'unit_price' => 50000, 'amount' => 50000]);
                }
            }
        });

        app(CustomerLifecycle::class)->refreshOrganization($organization);
        app(CustomerScoring::class)->refreshOrganization($organization);
        Auth::forgetUser();
    }
}
