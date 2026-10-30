<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\Group;
use App\Models\ImportBatch;
use App\Models\LoyaltyReward;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit;
use App\Services\BrandMetrics;
use App\Services\CustomerIdentity;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Services\CustomerSearch;
use App\Services\Loyalty;
use App\Services\SalesImport;
use Database\Seeders\RetailDemoProfiles;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Charge une entreprise de démonstration Menentia Retail (données fictives),
 * en passant par le vrai moteur d'import des ventes : fichiers de caisse
 * trimestriels (dont un avec des lignes en erreur, un au format Excel),
 * un import annulé, retours, tickets annulés, ventes anonymes, clients
 * mono et multimarques, numéros partagés envoyés dans « À vérifier »,
 * consentements variés, fidélité, groupes, RFM et métriques par marque.
 *
 * --type=mode (défaut) : groupe de mode à Libreville (Adidas Glass, Grand
 * Store Glass, Mango Centre-ville, Carré Centre-ville) ; restaurant : chaîne
 * de restaurants (tickets de totaux) ; distribution : distributeur de
 * produits de grande consommation (clients grossistes, codes clients).
 *
 * Idempotent : une entreprise déjà chargée n'est pas dupliquée ; --fresh la
 * supprime (elle seule, jamais d'autre donnée) puis la recrée.
 */
class SeedRetailDemo extends Command
{
    protected $signature = 'demo:retail
        {--type=mode : mode, restaurant ou distribution}
        {--fresh : Supprimer puis recréer cette entreprise de démonstration (et elle seule)}
        {--tickets= : Nombre de tickets à générer (défaut selon le type)}
        {--force : Autoriser l’exécution en production}';

    protected $description = 'Charge une entreprise de démonstration Menentia Retail (données fictives, via le moteur d’import)';

    private array $profile;

    /** @var array<string, Store> code => point de vente */
    private array $stores = [];

    /** @var array<string, Brand> */
    private array $brands = [];

    /** @var array<string, User> */
    private array $users = [];

    private string $password;

    public function handle(SalesImport $import): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        $type = (string) $this->option('type');
        if (! in_array($type, RetailDemoProfiles::TYPES, true)) {
            $this->error('Type inconnu : choisissez mode, restaurant ou distribution.');

            return self::FAILURE;
        }

        // Fichiers de démonstration volumineux (Excel de plusieurs milliers de lignes).
        ini_set('memory_limit', '1024M');
        $this->profile = RetailDemoProfiles::get($type);
        $this->stores = $this->brands = $this->users = [];
        $this->password = (string) env('DEMO_PASSWORD', 'Demo-Retail-2026');
        mt_srand(20261030 + array_search($type, RetailDemoProfiles::TYPES, true));

        $existing = Organization::where('slug', $this->profile['slug'])->first();

        if ($existing && ! $this->option('fresh')) {
            $this->warn("« {$existing->name} » est déjà chargée : rien n’a été modifié (ajoutez --fresh pour la recréer).");
            $this->summary($existing);

            return self::SUCCESS;
        }

        if ($existing) {
            $this->line("Suppression de l’entreprise de démonstration « {$existing->name} » (et d’elle seule)…");
            Storage::disk('local')->deleteDirectory("imports/{$existing->id}");
            // Campagnes : rattachées aux points de vente, pas à l'entreprise.
            Campaign::whereHas('stores', fn ($q) => $q->where('organization_id', $existing->id))->delete();
            $existing->delete();
        }

        $this->info("Création de « {$this->profile['name']} »…");
        $organization = $this->createOrganization();
        $categories = $this->createCategories($organization);
        $tickets = (int) ($this->option('tickets') ?: $this->profile['tickets']);

        $this->line("Génération de {$tickets} tickets sur 12 mois…");
        [$rows, $people] = $this->generateRows($tickets, $categories);

        $this->importFiles($import, $organization, $rows, $people, $categories);
        $this->correctAndReview($organization);

        $this->line('Recalcul des fiches, segments RFM et métriques par marque…');
        app(CustomerLifecycle::class)->refreshOrganization($organization);
        app(CustomerScoring::class)->refreshOrganization($organization);
        app(BrandMetrics::class)->refreshOrganization($organization);

        $this->createGroups($organization);
        $this->createLoyalty($organization);
        $sample = $this->sampleFile($organization);

        $this->summary($organization->fresh(), $sample);

        return self::SUCCESS;
    }

    private function createOrganization(): Organization
    {
        $p = $this->profile;

        $organization = Organization::create([
            'name' => $p['name'],
            'slug' => $p['slug'],
            'sector' => 'commerce',
            'whatsapp_api_enabled' => false,
            'currency' => 'XAF',
            'timezone' => 'Africa/Libreville',
            'product_analysis_level' => $p['level'],
            'loyalty_settings' => $p['loyalty']
                ? ['enabled' => true, 'fcfa_per_point' => 1000, 'starts_on' => today()->subMonths(13)->toDateString(), 'tiers' => Organization::LOYALTY_DEFAULTS['tiers']]
                : null,
            'privacy_email' => "contact.{$p['email_prefix']}@menentia-demo.test",
        ]);

        Role::seedDefaultsFor($organization);
        $role = fn (string $name) => Role::where('organization_id', $organization->id)->where('name', $name)->value('id');

        foreach ($p['stores'] as $s) {
            $brand = $this->brands[$s['brand']] ??= Brand::create(['organization_id' => $organization->id, 'name' => $s['brand']]);
            $this->stores[$s['code']] = Store::create([
                'organization_id' => $organization->id,
                'brand_id' => $brand->id,
                'name' => $s['store'],
                'code' => $s['code'],
                'city' => $s['city'],
                'whatsapp_phone_number_id' => null,
                'whatsapp_access_token' => null,
            ]);
        }

        $make = function (string $key, string $name, string $roleName, array $extra = []) use ($organization, $role, $p) {
            $this->users[$key] = User::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'email' => "{$key}.{$p['email_prefix']}@menentia-demo.test",
                'password' => $this->password,
                'role_id' => $role($roleName),
                ...$extra,
            ]);
        };

        $make('admin', 'Admin Groupe', 'Admin groupe');
        $make('direction', 'Direction Générale', 'Direction');
        $make('marketing', 'Équipe Marketing', 'Marketing');
        $make('import', 'Opérateur Import', 'Opérateur d’import');

        foreach ($p['stores'] as $s) {
            if ($s['manager'] !== null) {
                $make($s['manager'], "Resp. {$s['brand']}", 'Responsable de marque', ['brand_id' => $this->brands[$s['brand']]->id]);
            }
        }

        $first = reset($this->stores);
        $make('boutique', "Resp. {$first->name}", 'Responsable de boutique', ['store_id' => $first->id]);

        return $organization;
    }

    /** @return array<string, int> famille => id */
    private function createCategories(Organization $organization): array
    {
        $ids = [];

        foreach ($this->profile['categories'] as $parentName => $families) {
            $parent = Category::create(['organization_id' => $organization->id, 'name' => $parentName]);
            $ids[$parentName] = $parent->id;

            foreach ($families as $name => $keywords) {
                $ids[$name] = Category::create(['organization_id' => $organization->id, 'name' => $name, 'keywords' => $keywords, 'parent_id' => $parent->id])->id;
            }
        }

        return $ids;
    }

    /**
     * Clients fictifs et lignes de ventes (une ligne par article, tickets
     * multi-lignes, retours), triées par date.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function generateRows(int $tickets, array $categories): array
    {
        $p = $this->profile;

        if (! empty($p['stories'])) {
            return $this->storyRows($tickets);
        }

        $people = $this->generatePeople(max(20, (int) round($p['customers'] * $tickets / $p['tickets'])));
        $storesByBrand = collect($p['stores'])->groupBy('brand');
        $counters = [];
        $rows = [];
        $sales = [];

        for ($t = 0; $t < $tickets; $t++) {
            $anonymous = mt_rand(1, 100) <= $p['anonymous_share'];

            if ($anonymous) {
                $store = $this->weighted($p['stores'], fn ($s) => $s['weight']);
                $offset = $this->randomDay(1, 365);
                $person = null;
            } else {
                $index = $this->weighted(array_keys($people), fn ($i) => $people[$i]['weight']);
                $person = &$people[$index];
                $brand = $person['brands'][array_rand($person['brands'])];
                $store = $this->weighted($storesByBrand[$brand]->all(), fn ($s) => $s['weight']);
                $offset = $this->randomDay($person['end'], $person['start']);
            }

            $date = today()->subDays($offset);
            $counters[$store['prefix']] = ($counters[$store['prefix']] ?? 0) + 1;
            $receipt = $store['prefix'].'-'.$date->format('ym').'-'.str_pad((string) $counters[$store['prefix']], 5, '0', STR_PAD_LEFT);
            $ticketRows = $this->ticketLines($store, $date, $receipt, $person);
            $identified = $person !== null;
            unset($person);

            foreach ($ticketRows as $row) {
                $rows[] = $row;
            }

            if ($identified && empty($p['totals_only'])) {
                $sales[] = $ticketRows;
            }
        }

        // Retours (environ 3 % des tickets identifiés) : une ligne rendue quelques jours après.
        foreach (array_slice($this->shuffled($sales), 0, (int) round(count($sales) * 0.03)) as $ticket) {
            $line = $ticket[mt_rand(0, count($ticket) - 1)];
            $date = Carbon::createFromFormat('d/m/Y', $line['Date'])->addDays(mt_rand(1, 10));

            if ($date->gte(today())) {
                continue;
            }

            $rows[] = [...$line, 'Date' => $date->format('d/m/Y'), 'N° ticket' => 'R'.$line['N° ticket'], 'Ticket origine' => $line['N° ticket'],
                'Qté' => 1, 'Remise' => 0, 'Montant' => -((int) $line['Prix unitaire']), 'Type' => 'Retour', '_day' => $date->toDateString()];
        }

        usort($rows, fn ($a, $b) => [$a['_day'], $a['N° ticket']] <=> [$b['_day'], $b['N° ticket']]);

        return [$rows, $people];
    }

    /**
     * Prénoms féminins et masculins des listes (clientes de la marque femme,
     * clients de la marque homme).
     */
    private const WOMEN = ['Aïcha', 'Carine', 'Christelle', 'Diane', 'Edwige', 'Flore', 'Grâce', 'Inès', 'Josiane', 'Laetitia', 'Lydie', 'Murielle', 'Nadège', 'Olivia', 'Prisca', 'Sandrine', 'Stéphanie', 'Ursule', 'Valérie', 'Zita', 'Bénédicte'];

    private const MEN = ['Brice', 'Cédric', 'Davy', 'Fabrice', 'Ghislain', 'Hervé', 'Joël', 'Kévin', 'Landry', 'Marcel', 'Noël', 'Patrick', 'Rodrigue', 'Serge', 'Thierry', 'Wilfried', 'Yannick', 'Armel', 'Dimitri'];

    /**
     * Démo « mode » construite autour de huit histoires, chacune portée par
     * une cohorte de clients au comportement choisi (le reste est tiré au
     * sort autour de ces comportements) :
     *  1. bonnes clientes Mango sans achat depuis 4 mois (reconquête) ;
     *  2. fidèles Adidas jamais venus chez Grand Store, même zone (vente croisée) ;
     *  3. clients multimarques : environ 17 % des clients, 40 % du CA (VIP groupe) ;
     *  4. nouveaux clients Carré qui ne reviennent pas (bienvenue + relance J+30) ;
     *  5. rentrée (août-septembre) et fêtes (décembre) sur enfant et sneakers ;
     *  6. costumes en baisse chez Carré, sneakers en hausse chez Adidas ;
     *  7. sandales Mango très souvent retournées (alerte qualité) ;
     *  8. près de la moitié des tickets anonymes à Grand Store (collecte du téléphone).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function storyRows(int $tickets): array
    {
        $scale = max(0.03, $tickets / $this->profile['tickets']);
        $n = fn (int $count) => max(3, (int) round($count * $scale));

        // [cohorte, nombre, genre, marques]
        $cohorts = [
            ['mango_lost', $n(45), 'Femme', ['Mango']],
            ['mango_active', $n(60), 'Femme', ['Mango']],
            ['adidas_loyal', $n(170), null, ['Adidas']],
            ['adidas_occasional', $n(80), null, ['Adidas']],
            ['grand_store', $n(100), null, ['Grand Store']],
            ['carre_old', $n(55), 'Homme', ['Carré']],
            ['carre_new', $n(140), 'Homme', ['Carré']],
            ['carre_recent', $n(40), 'Homme', ['Carré']],
            ['multi_vip', $n(150), null, []],
        ];

        $people = $this->generatePeople(array_sum(array_column($cohorts, 1)));
        $index = 0;

        foreach ($cohorts as [$cohort, $count, $gender, $brands]) {
            for ($i = 0; $i < $count; $i++, $index++) {
                $person = &$people[$index];
                $person['cohort'] = $cohort;

                if ($gender !== null) {
                    $person['gender'] = $gender;
                    $first = $gender === 'Femme' ? self::WOMEN[array_rand(self::WOMEN)] : self::MEN[array_rand(self::MEN)];
                    $person['name'] = $first.' '.explode(' ', $person['name'], 2)[1];
                }

                $person['brands'] = $brands ?: $this->vipBrands();
                $person['plan'] = $this->plan($cohort, $person['brands']);
                // Les meilleurs clients donnent plus souvent leur accord WhatsApp.
                $person['consent'] = $cohort === 'multi_vip' ? (mt_rand(1, 100) <= 75 ? 'oui' : '') : $person['consent'];
                unset($person);
            }
        }

        $storesByBrand = collect($this->profile['stores'])->keyBy('brand');
        $counters = [];
        $rows = [];
        $identifiedByBrand = [];
        $emit = function (array $store, int $offset, ?array &$person, ?int $lines) use (&$counters, &$rows) {
            $date = today()->subDays($offset);
            $counters[$store['prefix']] = ($counters[$store['prefix']] ?? 0) + 1;
            $receipt = $store['prefix'].'-'.$date->format('ym').'-'.str_pad((string) $counters[$store['prefix']], 5, '0', STR_PAD_LEFT);
            $ticket = $this->ticketLines($store, $date, $receipt, $person, $lines, $offset);

            foreach ($ticket as $line) {
                $rows[] = $line;

                // Histoire 7 : les sandales sont souvent rapportées (taille, confort).
                $returnRate = $line['Famille'] === 'Chaussures femme' ? 24 : 1;
                $back = $date->copy()->addDays(mt_rand(2, 12));

                if ($person !== null && mt_rand(1, 100) <= $returnRate && $back->lt(today())) {
                    $rows[] = [...$line, '_day' => $back->toDateString(), 'Date' => $back->format('d/m/Y'), 'N° ticket' => 'R'.$receipt, 'Ticket origine' => $receipt,
                        'Qté' => 1, 'Remise' => 0, 'Montant' => -((int) $line['Prix unitaire']), 'Type' => 'Retour', 'Consentement' => ''];
                }
            }
        };

        foreach ($people as &$person) {
            foreach ($person['plan'] as [$brand, $offset, $lines]) {
                $emit($storesByBrand[$brand], $offset, $person, $lines);
                $identifiedByBrand[$brand] = ($identifiedByBrand[$brand] ?? 0) + 1;
            }
        }
        unset($person);

        // Histoire 8 : ventes anonymes, près de la moitié des tickets à Grand Store, peu ailleurs.
        $nobody = null;
        foreach ($identifiedByBrand as $brand => $count) {
            $share = $brand === 'Grand Store' ? 0.46 : 0.08;
            foreach (range(1, max(1, (int) round($count * $share / (1 - $share)))) as $ignored) {
                $emit($storesByBrand[$brand], $this->seasonalDay(1, 364, $brand), $nobody, null);
            }
        }

        usort($rows, fn ($a, $b) => [$a['_day'], $a['N° ticket']] <=> [$b['_day'], $b['N° ticket']]);

        return [$rows, $people];
    }

    /** Marques d'un client multimarque : 2 à 4 enseignes du groupe. */
    private function vipBrands(): array
    {
        $brands = ['Adidas' => 30, 'Grand Store' => 30, 'Mango' => 25, 'Carré' => 15];
        $howMany = $this->weighted([[2, 60], [3, 30], [4, 10]], fn ($x) => $x[1])[0];
        $chosen = [];

        while (count($chosen) < $howMany) {
            $chosen[$this->weighted(array_keys($brands), fn ($b) => $brands[$b])] = true;
        }

        return array_keys($chosen);
    }

    /**
     * Achats d'un client selon sa cohorte : [marque, jours avant aujourd'hui, nombre de lignes].
     *
     * @param  list<string>  $brands
     * @return list<array{0: string, 1: int, 2: int|null}>
     */
    private function plan(string $cohort, array $brands): array
    {
        $brand = $brands[0];
        $days = fn (int $count, int $min, int $max, string $b) => array_map(fn () => $this->seasonalDay($min, $max, $b), range(1, $count));
        $plan = fn (array $offsets, string $b, int $minLines, int $maxLines) => array_map(fn ($d) => [$b, $d, mt_rand($minLines, $maxLines)], $offsets);

        return match ($cohort) {
            // 1. Très bonnes clientes jusqu'à il y a quatre mois, puis plus rien.
            'mango_lost' => $plan($days(mt_rand(5, 8), 118, 340, $brand), $brand, 2, 3),
            'mango_active' => $plan([...$days(mt_rand(1, 4), 2, 360, $brand), mt_rand(2, 55)], $brand, 1, 2),
            // 2. Fidèles Adidas : réguliers et récents, jamais chez Grand Store.
            'adidas_loyal' => $plan([...$days(mt_rand(2, 5), 2, 360, $brand), mt_rand(2, 70)], $brand, 1, 2),
            'adidas_occasional' => $plan($days(mt_rand(1, 2), 2, 360, $brand), $brand, 1, 1),
            'grand_store' => $plan($days(mt_rand(2, 4), 2, 360, $brand), $brand, 1, 3),
            // 6. Clients Carré historiques : surtout il y a plus de six mois (costumes).
            'carre_old' => $plan([...$days(mt_rand(2, 4), 150, 360, $brand), ...$days(mt_rand(0, 1), 2, 149, $brand)], $brand, 1, 2),
            // 4. Nouveaux clients Carré : un seul achat pour près de 9 sur 10.
            'carre_new' => (function () use ($brand) {
                $first = mt_rand(95, 280);
                $visits = [[$brand, $first, mt_rand(1, 2)]];

                if (mt_rand(1, 100) <= 12) {
                    $visits[] = [$brand, $first - mt_rand(10, 85), 1];
                }

                return $visits;
            })(),
            'carre_recent' => [[$brand, mt_rand(2, 90), mt_rand(1, 2)]],
            // 3. Multimarques : achats réguliers dans plusieurs enseignes, paniers plus garnis.
            'multi_vip' => array_map(
                fn ($i) => [$b = $brands[$i % count($brands)], $i === 0 ? mt_rand(1, 40) : $this->seasonalDay(2, 360, $b), mt_rand(2, 3)],
                range(0, mt_rand(5, 8)),
            ),
            default => [],
        };
    }

    /**
     * Jour d'achat entre $min et $max jours avant aujourd'hui (histoire 5) :
     * rentrée (août-septembre) et fêtes (décembre) plus fortes chez Grand
     * Store et Adidas, fêtes plus fortes partout, samedis plus chargés.
     */
    private function seasonalDay(int $min, int $max, string $brand): int
    {
        for ($i = 0; $i < 30; $i++) {
            $offset = mt_rand(max(1, $min), max($min, $max));
            $date = today()->subDays($offset);
            $month = (int) $date->format('n');
            $weight = match (true) {
                $month === 12 => 1.0,
                in_array($month, [8, 9], true) && in_array($brand, ['Grand Store', 'Adidas'], true) => 1.0,
                default => 0.45,
            } * ($date->isSaturday() ? 1.0 : 0.8);

            if (mt_rand() / mt_getrandmax() <= $weight) {
                return $offset;
            }
        }

        return $offset;
    }

    /**
     * Poids d'une famille dans le choix des articles d'un ticket (histoires
     * 5 et 6) : saison (enfant, jouets, sneakers, parfums) et tendance
     * (costumes en baisse, sneakers en hausse sur l'année).
     */
    private function familyWeight(string $family, Carbon $date, int $offset): float
    {
        $month = (int) $date->format('n');
        $season = match (true) {
            in_array($family, ['Mode enfant', 'Jouets'], true) && in_array($month, [8, 9, 12], true) => 3.0,
            $family === 'Sneakers' && in_array($month, [8, 9, 12], true) => 2.0,
            $family === 'Parfums' && $month === 12 => 3.0,
            default => 1.0,
        };
        $trend = match ($family) {
            'Costumes' => $offset > 180 ? 3.0 : ($offset > 90 ? 1.3 : 0.3),
            'Sneakers' => $offset > 180 ? 0.5 : ($offset > 90 ? 1.0 : 2.8),
            default => 1.0,
        };

        return $season * $trend;
    }

    /** @return list<array<string, mixed>> */
    private function generatePeople(int $count): array
    {
        $p = $this->profile;
        $brands = array_values(array_unique(array_column($p['stores'], 'brand')));
        $brandWeights = collect($p['stores'])->groupBy('brand')->map(fn ($s) => $s->sum('weight'))->all();
        $phones = [];
        $people = [];

        for ($i = 0; $i < $count; $i++) {
            do {
                $national = (mt_rand(0, 1) ? '6' : '7').str_pad((string) mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
            } while (isset($phones[$national]));
            $phones[$national] = true;

            $business = mt_rand(1, 100) <= ($p['business_share'] ?? 0);
            $first = RetailDemoProfiles::FIRST_NAMES[array_rand(RetailDemoProfiles::FIRST_NAMES)];
            $last = RetailDemoProfiles::LAST_NAMES[array_rand(RetailDemoProfiles::LAST_NAMES)];
            $name = $business
                ? RetailDemoProfiles::COMPANY_KINDS[array_rand(RetailDemoProfiles::COMPANY_KINDS)].' '.$last.' ('.RetailDemoProfiles::QUARTERS[array_rand(RetailDemoProfiles::QUARTERS)].')'
                : "{$first} {$last}";

            // Marques fréquentées : 55 % une seule, 35 % deux, 10 % trois ou plus.
            $roll = mt_rand(1, 100);
            $howMany = min(count($brands), $roll <= 55 ? 1 : ($roll <= 90 ? 2 : 3));
            $chosen = [];
            while (count($chosen) < $howMany) {
                $chosen[$this->weighted($brands, fn ($b) => $brandWeights[$b])] = true;
            }

            // Fenêtre d'achat (jours avant aujourd'hui) : clients actifs, partis, nouveaux.
            $kind = mt_rand(1, 100);
            [$start, $end] = match (true) {
                $kind <= 68 => [mt_rand(60, 365), 1],
                $kind <= 88 => [mt_rand(250, 365), mt_rand(120, 220)],
                default => [mt_rand(5, 60), 1],
            };

            $people[] = [
                'name' => $name,
                'phone' => $national,
                'display' => match (mt_rand(1, 4)) {
                    1 => '0'.$national,
                    2 => substr($national, 0, 2).' '.substr($national, 2, 2).' '.substr($national, 4, 2).' '.substr($national, 6, 2),
                    default => '+241 '.substr($national, 0, 2).' '.substr($national, 2, 2).' '.substr($national, 4, 2).' '.substr($national, 6, 2),
                },
                'code' => $business || mt_rand(1, 100) <= 15 ? sprintf('CLI-%05d', $i + 1) : '',
                'type' => $business ? ['Grossiste', 'Boutique', 'Restaurant', 'Revendeur'][mt_rand(0, 3)] : (mt_rand(1, 100) <= 4 ? 'Entreprise' : 'Particulier'),
                'brands' => array_keys($chosen),
                'weight' => [1, 1, 2, 2, 3, 5, 8][mt_rand(0, 6)],
                'start' => $start,
                'end' => $end,
                'consent' => ($c = mt_rand(1, 100)) <= 45 ? 'oui' : ($c <= 55 ? 'non' : ''),
                'gender' => $business ? '' : (mt_rand(0, 1) ? 'Femme' : 'Homme'),
                'seen' => false,
            ];
        }

        return $people;
    }

    /** @return list<array<string, mixed>> */
    private function ticketLines(array $store, Carbon $date, string $receipt, ?array &$person, ?int $forcedLines = null, ?int $offset = null): array
    {
        $p = $this->profile;
        $payment = $this->weighted([['Espèces', 40], ['Airtel Money', 25], ['Moov Money', 10], ['Carte bancaire', 20], ['Virement', 5]], fn ($x) => $x[1])[0];
        $base = [
            '_day' => $date->toDateString(),
            'Date' => $date->format('d/m/Y'),
            'Magasin' => $store['code'],
            'N° ticket' => $receipt,
            'Ticket origine' => '',
            'Téléphone' => '',
            'Code client' => '',
            'Client' => '',
            'Type client' => '',
            'Paiement' => $payment,
            'Consentement' => '',
            'Type' => 'Vente',
        ];

        if ($person !== null) {
            // Distribution : après la première facture, souvent seul le code client figure.
            $codeOnly = $person['code'] !== '' && $person['seen'] && ! empty($p['business_share']) && mt_rand(1, 100) <= 40;
            $base['Téléphone'] = $codeOnly ? '' : $person['display'];
            $base['Code client'] = $person['code'];
            $base['Client'] = $person['name'];
            $base['Type client'] = $person['type'];
            $base['Consentement'] = $person['seen'] ? '' : $person['consent'];
            $person['seen'] = true;
        } elseif (mt_rand(1, 100) <= 20) {
            $base['Client'] = 'Client de passage';
        }

        if (! empty($p['totals_only'])) {
            $guests = mt_rand(1, 6);

            return [[...$base, 'Famille' => mt_rand(1, 100) <= 25 ? 'À emporter' : '', 'Catégorie' => '', 'Désignation' => '', 'Code article' => '',
                'Qté' => 1, 'Prix unitaire' => '', 'Remise' => 0, 'Montant' => $guests * mt_rand(4, 14) * 1000,
                'Couverts' => $guests, 'Service' => mt_rand(0, 1) ? 'Midi' : 'Soir']];
        }

        $articles = $p['articles'][$store['brand']];
        $family = fn (string $f) => collect($p['categories'])->search(fn ($families) => array_key_exists($f, $families));
        $count = $forcedLines ?? (! empty($p['business_share']) ? mt_rand(2, 6) : $this->weighted([[1, 60], [2, 30], [3, 10]], fn ($x) => $x[1])[0]);
        $sale = in_array((int) $date->format('n'), [1, 7], true) ? 40 : 12;
        $lines = [];

        // Démo à histoires : articles tirés selon la saison et la tendance de leur famille.
        if (! empty($p['stories'])) {
            $picked = [];
            $candidates = array_keys($articles);
            while (count($picked) < min($count, count($articles))) {
                $choice = $this->weighted(array_values(array_diff($candidates, $picked)), fn ($k) => $this->familyWeight($articles[$k][2], $date, $offset ?? 180));
                $picked[] = $choice;
            }
        } else {
            $picked = (array) array_rand($articles, min($count, count($articles)));
        }

        foreach ($picked as $index) {
            [$label, $code, $fam, $min, $max, $genre, $sizes] = $articles[$index];
            $qty = ! empty($p['business_share']) ? mt_rand(2, 40) : (str_contains($label, 'T-shirt') || str_contains($label, 'Chaussettes') ? mt_rand(1, 2) : 1);
            $price = (int) (round(mt_rand($min, $max) / 500) * 500);
            $discount = mt_rand(1, 100) <= $sale ? (int) (round($qty * $price * mt_rand(10, 20) / 100 / 100) * 100) : 0;

            $lines[] = [...$base, 'Famille' => $fam, 'Catégorie' => (string) $family($fam), 'Désignation' => $label, 'Code article' => $code,
                'Qté' => $qty, 'Prix unitaire' => $price, 'Remise' => $discount, 'Montant' => $qty * $price - $discount,
                'Genre' => $genre ?: ($person['gender'] ?? ''), 'Taille' => $sizes ? $sizes[array_rand($sizes)] : '',
                'Couleur' => $p['colors'] ? $p['colors'][array_rand($p['colors'])] : ''];
        }

        return $lines;
    }

    /**
     * Fichiers trimestriels importés par l'opérateur d'import : le 3e avec
     * des lignes en erreur, le dernier au format Excel avec des numéros
     * connus sous un autre nom (file « À vérifier ») ; plus un petit import
     * de test annulé.
     */
    private function importFiles(SalesImport $import, Organization $organization, array $rows, array $people, array $categories): void
    {
        $defaultCategory = isset($this->profile['default_family']) ? $categories[$this->profile['default_family']] : null;
        $quarters = [[]];
        $bounds = [today()->subDays(273)->toDateString(), today()->subDays(182)->toDateString(), today()->subDays(91)->toDateString()];

        foreach ($rows as $row) {
            $q = count(array_filter($bounds, fn ($b) => $row['_day'] >= $b));
            $quarters[$q][] = $row;
        }

        // Lignes en erreur (3e trimestre) : date manquante ou impossible, point de vente inconnu, montant illisible, famille absente.
        if (isset($quarters[2][0])) {
            $sample = $quarters[2][0];
            array_push($quarters[2],
                [...$sample, 'Date' => '', 'N° ticket' => 'ERR-1'],
                [...$sample, 'Date' => '31/02/2026', 'N° ticket' => 'ERR-2'],
                [...$sample, 'Magasin' => 'Boutique Akanda', 'N° ticket' => 'ERR-3'],
                [...$sample, 'Montant' => 'offert', 'Prix unitaire' => '', 'N° ticket' => 'ERR-4'],
                [...$sample, 'Famille' => '', 'Catégorie' => '', 'Désignation' => '', 'N° ticket' => 'ERR-5'],
            );
        }

        // Numéros connus achetant sous un autre nom (famille, standard) : jamais rattachés d'office.
        $known = array_values(array_filter($people, fn ($x) => $x['seen'] && $x['code'] === ''));
        if (isset($quarters[3][0]) && $known !== []) {
            foreach (array_slice($this->shuffled($known), 0, 6) as $i => $person) {
                $line = $quarters[3][array_rand($quarters[3])];
                $quarters[3][] = [...$line, 'N° ticket' => 'PART-'.($i + 1), 'Téléphone' => $person['display'], 'Code client' => '',
                    'Client' => RetailDemoProfiles::FIRST_NAMES[($i * 7) % 40].' '.RetailDemoProfiles::LAST_NAMES[($i * 11 + 3) % 25], 'Consentement' => ''];
            }
        }

        foreach ($quarters as $q => $quarterRows) {
            if ($quarterRows === []) {
                continue;
            }

            $from = Carbon::parse(min(array_filter(array_column($quarterRows, '_day'))));
            $to = Carbon::parse(max(array_column($quarterRows, '_day')));
            $name = 'export-caisse-'.$from->format('Y-m').'-a-'.$to->format('Y-m').($q === 3 ? '.xlsx' : '.csv');
            $this->line("  Import de {$name} (".count($quarterRows).' lignes)…');
            $batch = $this->runImport($import, $name, $quarterRows, $defaultCategory);
            $this->line("    → {$batch->tickets_count} tickets, {$batch->rejected_rows} rejet(s), {$batch->anonymous_tickets} anonyme(s)");
        }

        // Import de test, annulé ensuite : ses ventes disparaissent, l'action est journalisée.
        $test = array_map(fn ($row) => [...$row, 'N° ticket' => 'TEST-'.$row['N° ticket']], array_slice($quarters[3] ?? $rows, 0, 12));
        $batch = $this->runImport($import, 'ventes-semaine-test.csv', $test, $defaultCategory);
        $import->cancel($batch, $this->users['admin']);
        $this->line("  Import de test n° {$batch->id} annulé.");
    }

    private function runImport(SalesImport $import, string $name, array $rows, ?int $defaultCategory): ImportBatch
    {
        $columns = array_values(array_filter(array_keys(array_merge(...array_map(fn ($r) => $r, $rows))), fn ($k) => $k !== '_day'));
        $dir = storage_path('app/demo-tmp');
        @mkdir($dir, 0775, true);
        $path = $dir.DIRECTORY_SEPARATOR.$name;

        if (str_ends_with($name, '.xlsx')) {
            $sheet = new Spreadsheet;
            $sheet->getActiveSheet()->fromArray([$columns, ...array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? '', $columns), $rows)], null, 'A1', true);
            (new Xlsx($sheet))->save($path);
        } else {
            $out = fopen($path, 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($c) => $r[$c] ?? '', $columns), ';', '"', '');
            }
            fclose($out);
        }

        $user = $this->users['import'];
        $batch = $import->upload(new UploadedFile($path, $name, null, null, true), $user);
        $import->validate($batch, $batch->mapping ?? [], $defaultCategory, $user);
        $batch->update(['status' => ImportBatch::STATUS_PROCESSING, 'started_at' => now()]);
        $import->process($batch);
        @unlink($path);

        return $batch->refresh();
    }

    /** Quelques corrections (tickets annulés) et décisions de rapprochement, journalisées. */
    private function correctAndReview(Organization $organization): void
    {
        $admin = $this->users['admin'];
        $reasons = ['Ticket saisi deux fois en caisse', 'Erreur de point de vente', 'Vente test du caissier', 'Paiement refusé après édition'];

        Purchase::whereIn('store_id', collect($this->stores)->pluck('id'))->where('type', Purchase::TYPE_SALE)->inRandomOrder()->take(6)->get()
            ->each(function (Purchase $ticket, int $i) use ($admin, $reasons) {
                $ticket->update(['status' => Purchase::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $admin->id, 'cancel_reason' => $reasons[$i % 4]]);
                Audit::record('sale.cancelled', $ticket, "Ticket n° {$ticket->receipt_number} annulé : {$reasons[$i % 4]}", ['amount' => (int) $ticket->amount], $admin, $ticket->store->organization_id);
            });

        // Un rapprochement traité (même personne), les autres restent à vérifier.
        if ($review = CustomerMatchReview::where('organization_id', $organization->id)->where('status', 'pending')->first()) {
            app(CustomerIdentity::class)->resolve($review, CustomerMatchReview::STATUS_ATTACHED, $admin);
        }
    }

    private function createGroups(Organization $organization): void
    {
        if (! empty($this->profile['stories'])) {
            $this->createStoryGroups($organization);

            return;
        }

        $admin = $this->users['admin'];
        $brands = collect($this->brands)->values();
        $definitions = [['Clients multimarques', ['brands' => 'multi']]];

        if ($brands->count() >= 2) {
            $definitions[] = ["Champions {$brands[0]->name}", ['brand' => $brands[0]->id, 'segment' => 'champions']];
            $definitions[] = ["Fidèles {$brands[1]->name} à faire découvrir {$brands->last()->name}", ['brand' => $brands[1]->id, 'never_brand' => $brands->last()->id, 'segment' => 'loyal']];
        }
        $definitions[] = ['Clients à risque', ['risk' => 'any']];

        foreach ($definitions as [$name, $filters]) {
            $group = Group::create(['organization_id' => $organization->id, 'name' => $name]);
            $search = new CustomerSearch($admin, $filters);
            $contacts = Contact::query()->where($search->contactScope())->whereIn('contacts.customer_id', $search->query()->select('customers.id'))->pluck('contacts.id');
            $group->contacts()->syncWithoutDetaching($contacts->all());
        }
    }

    /**
     * Décisions préparées pour les histoires : groupes de clients, messages
     * des Actions du jour et campagnes en brouillon (visibles dès que l'API
     * WhatsApp est activée).
     */
    private function createStoryGroups(Organization $organization): void
    {
        $brand = fn (string $name) => $this->brands[$name]->id;
        $metrics = fn () => DB::table('customer_brand_metrics')->where('organization_id', $organization->id)->where('order_count', '>', 0);
        $groups = [];

        $groups['Reconquête Mango'] = [
            'customers' => $metrics()->where('brand_id', $brand('Mango'))->whereIn('rfm_segment', ['at_risk', 'cant_lose'])->pluck('customer_id'),
            'brand' => 'Mango',
            'message' => 'Bonjour {prenom}, vous nous manquez chez Mango Centre-ville ! La nouvelle collection est arrivée et une attention vous attend en boutique cette semaine.',
            'description' => 'Bonnes clientes Mango (segments À risque et À ne pas perdre) sans achat depuis plus de 3 mois.',
        ];

        $gsBuyers = $metrics()->where('brand_id', $brand('Grand Store'))->pluck('customer_id');
        $groups['Vente croisée Adidas → Grand Store'] = [
            'customers' => $metrics()->where('brand_id', $brand('Adidas'))->where('order_count', '>=', 2)->whereNotIn('customer_id', $gsBuyers)->pluck('customer_id'),
            'brand' => 'Adidas',
            'message' => 'Bonjour {prenom}, merci pour votre fidélité chez Adidas Glass. Juste à côté, Grand Store Glass vous offre 10 % sur votre premier achat avec ce message.',
            'description' => 'Clients Adidas (2 achats ou plus) jamais venus chez Grand Store, dans la même zone de Glass.',
        ];

        $groups['VIP multimarques'] = [
            'customers' => DB::table('customers')->where('organization_id', $organization->id)->where('brands_count', '>=', 2)->pluck('id'),
            'brand' => null,
            'message' => 'Bonjour {prenom}, vous faites partie des clients VIP de Retail Group : profitez d’un accès en avant-première aux nouveautés de nos quatre enseignes.',
            'description' => 'Clients ayant acheté dans au moins deux enseignes du groupe.',
        ];

        $groups['Bienvenue Carré J+30'] = [
            'customers' => $metrics()->where('brand_id', $brand('Carré'))->where('order_count', 1)
                ->where('first_purchase_at', '>=', today()->subDays(120)->toDateString())->pluck('customer_id'),
            'brand' => 'Carré',
            'message' => 'Bonjour {prenom}, merci pour votre premier achat chez Carré Centre-ville. Votre retoucheur vous attend : ajustements offerts pendant 30 jours.',
            'description' => 'Nouveaux clients Carré des 4 derniers mois, un seul achat : relance à J+30.',
        ];

        foreach ($groups as $name => $definition) {
            $group = Group::create(['organization_id' => $organization->id, 'name' => $name]);
            $storeIds = $definition['brand'] ? collect($this->stores)->where('brand_id', $brand($definition['brand']))->pluck('id') : collect($this->stores)->pluck('id');
            $contacts = Contact::whereIn('customer_id', $definition['customers'])->whereIn('store_id', $storeIds)->pluck('id');
            $group->contacts()->syncWithoutDetaching($contacts->all());

            $campaign = Campaign::create([
                'name' => $name,
                'description' => $definition['description'],
                'message_type' => 'text',
                'text_body' => $definition['message'],
                'segment_filters' => ['group' => $group->id],
                'status' => 'draft',
            ]);
            $campaign->stores()->attach($storeIds->all());
        }

        // Messages des Actions du jour (reconquête, bienvenue, vente croisée) adaptés au groupe.
        $organization->update(['action_messages' => [
            'at_risk' => 'Bonjour {prenom}, cela fait un moment que nous ne vous avons pas vu chez {boutique}. La nouvelle collection est arrivée : passez nous voir, une attention vous attend.',
            'second_purchase' => 'Bonjour {prenom}, merci pour votre premier achat chez {boutique}. Tout vous convient ? Ajustements et conseils offerts pendant 30 jours.',
            'cross_sell' => 'Bonjour {prenom}, merci pour votre fidélité chez {boutique}. Découvrez aussi {autre_marque}, juste à côté : 10 % sur votre premier achat avec ce message.',
        ]]);

        // Rapport du soir envoyé à la direction.
        $organization->update(['report_settings' => [...Organization::REPORT_DEFAULTS, 'enabled' => true, 'recipients' => [$this->users['direction']->email], 'monthly_target' => 12000000]]);
    }

    /** Récompenses du programme de fidélité et quelques échanges déjà faits. */
    private function createLoyalty(Organization $organization): void
    {
        if (empty($this->profile['loyalty'])) {
            return;
        }

        $rewards = collect([
            ['Bon d’achat 5 000 FCFA', 150],
            ['Retouche offerte', 80],
            ['Bon d’achat 15 000 FCFA', 400],
            ['Accès VIP aux soldes privées', 600],
        ])->map(fn ($r) => LoyaltyReward::create(['organization_id' => $organization->id, 'name' => $r[0], 'points_cost' => $r[1], 'active' => true]));

        $loyalty = Loyalty::for($organization);
        $redeemed = 0;

        Customer::where('organization_id', $organization->id)->orderByDesc('total_spent')->take(60)->get()
            ->each(function (Customer $customer, int $i) use ($loyalty, $rewards, &$redeemed) {
                if ($i % 3 !== 0) {
                    return;
                }

                $reward = $rewards->filter(fn ($r) => $r->points_cost <= $loyalty->balance($customer))->sortByDesc('points_cost')->first();

                if ($reward) {
                    $loyalty->redeem($customer, $reward, $this->users['boutique']);
                    $redeemed++;
                }
            });

        $this->line("  Fidélité : {$rewards->count()} récompenses, {$redeemed} échanges.");
    }

    /** Fichier Excel d'exemple, prêt à importer (ventes des deux derniers jours, nouveaux n° de ticket). */
    private function sampleFile(Organization $organization): string
    {
        [$rows] = $this->generateRows(12, []);
        $rows = array_map(function ($row) {
            $day = today()->subDays(mt_rand(1, 2));

            return [...$row, '_day' => $day->toDateString(), 'Date' => $day->format('d/m/Y'), 'N° ticket' => 'EX-'.$row['N° ticket'], 'Ticket origine' => ''];
        }, array_slice(array_values(array_filter($rows, fn ($r) => ($r['Type'] ?? 'Vente') === 'Vente')), 0, 30));

        $columns = array_values(array_filter(array_keys(array_merge(...array_values($rows))), fn ($k) => $k !== '_day'));
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Ventes')->fromArray([$columns, ...array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? '', $columns), array_values($rows))], null, 'A1', true);

        $relative = "demo/exemple-import-ventes-{$this->profile['email_prefix']}.xlsx";
        Storage::disk('local')->makeDirectory('demo');
        (new Xlsx($sheet))->save(Storage::disk('local')->path($relative));

        return Storage::disk('local')->path($relative);
    }

    private function summary(Organization $organization, ?string $sample = null): void
    {
        $storeIds = $organization->stores()->pluck('id');

        $this->table(['Élément', 'Nombre'], [
            ['Marques / points de vente', $organization->brands()->count().' / '.$storeIds->count()],
            ['Fiches clients', Customer::where('organization_id', $organization->id)->count()],
            ['Clients multimarques', Customer::where('organization_id', $organization->id)->where('brands_count', '>=', 2)->count()],
            ['Tickets valides', Purchase::whereIn('store_id', $storeIds)->count()],
            ['dont retours', Purchase::whereIn('store_id', $storeIds)->where('type', 'return')->count()],
            ['dont ventes anonymes', Purchase::whereIn('store_id', $storeIds)->whereNull('contact_id')->count()],
            ['Tickets annulés', Purchase::withCancelled()->whereIn('store_id', $storeIds)->where('status', 'cancelled')->count()],
            ['Imports', ImportBatch::where('organization_id', $organization->id)->count()],
            ['Rapprochements à vérifier', CustomerMatchReview::where('organization_id', $organization->id)->where('status', 'pending')->count()],
        ]);

        $this->line('Comptes de démonstration (mot de passe : '.env('DEMO_PASSWORD', 'Demo-Retail-2026').') :');
        $this->table(['E-mail', 'Rôle', 'Périmètre'], User::where('organization_id', $organization->id)->with(['role', 'brand', 'managedStore'])->orderBy('id')->get()
            ->map(fn (User $u) => [$u->email, $u->role?->name, $u->brand?->name ?? $u->managedStore?->name ?? 'Tout le groupe'])->all());

        if ($sample) {
            $this->line("Fichier Excel d’exemple à importer (Imports › Ventes) : {$sample}");
        }
    }

    /**
     * Jour aléatoire entre $min et $max jours avant aujourd'hui, plus de
     * ventes en fin d'année, en janvier et juillet (soldes), et le samedi.
     */
    private function randomDay(int $min, int $max): int
    {
        $low = max(1, min($min, $max));
        $high = max($low, $min, $max);

        for ($i = 0; $i < 20; $i++) {
            $offset = mt_rand($low, $high);
            $date = today()->subDays($offset);
            $weight = match ((int) $date->format('n')) {
                12 => 1.0,
                1, 7, 11 => 0.8,
                default => 0.6,
            } * ($date->isSaturday() ? 1.0 : 0.75);

            if (mt_rand() / mt_getrandmax() <= $weight) {
                return $offset;
            }
        }

        return $offset;
    }

    private function weighted(array $items, callable $weight): mixed
    {
        $total = array_sum(array_map($weight, $items));
        $roll = mt_rand() / mt_getrandmax() * $total;

        foreach ($items as $item) {
            $roll -= $weight($item);
            if ($roll <= 0) {
                return $item;
            }
        }

        return end($items);
    }

    private function shuffled(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
