<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\Group;
use App\Models\ImportBatch;
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
use App\Services\SalesImport;
use Database\Seeders\RetailDemoProfiles;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
    private function ticketLines(array $store, Carbon $date, string $receipt, ?array &$person): array
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
        $count = ! empty($p['business_share']) ? mt_rand(2, 6) : $this->weighted([[1, 60], [2, 30], [3, 10]], fn ($x) => $x[1])[0];
        $sale = in_array((int) $date->format('n'), [1, 7], true) ? 40 : 12;
        $lines = [];

        foreach ((array) array_rand($articles, min($count, count($articles))) as $index) {
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

    /** Fichier Excel d'exemple, prêt à importer (ventes des deux derniers jours, nouveaux n° de ticket). */
    private function sampleFile(Organization $organization): string
    {
        [$rows] = $this->generateRows(12, []);
        $rows = array_map(function ($row) {
            $day = today()->subDays(mt_rand(1, 2));

            return [...$row, '_day' => $day->toDateString(), 'Date' => $day->format('d/m/Y'), 'N° ticket' => 'EX-'.$row['N° ticket'], 'Ticket origine' => ''];
        }, array_filter($rows, fn ($r) => ($r['Type'] ?? 'Vente') === 'Vente'));

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
