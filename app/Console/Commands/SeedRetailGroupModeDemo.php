<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyRedemption;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BrandMetrics;
use App\Services\CustomerLifecycle;
use App\Services\CustomerScoring;
use App\Services\LoyaltyEngine;
use App\Services\SalesImport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Démonstration de la fidélité par enseigne : groupe de mode fictif, tickets
 * fictifs importés par le vrai moteur d'import, un programme 2026 (et un
 * programme 2025 clôturé, seul moyen de montrer des cartes expirées avec une
 * validité de 12 mois). Voir docs/demo-retailgroup-mode.md.
 *
 * Idempotente : --fresh supprime cette entreprise de démonstration (et elle
 * seule) puis la recrée.
 */
class SeedRetailGroupModeDemo extends Command
{
    protected $signature = 'demo:retailgroup-mode
        {--fresh : Supprimer puis recréer cette entreprise de démonstration (et elle seule)}
        {--clients=300 : Nombre approximatif de clients}
        {--force : Autoriser l’exécution en production}';

    protected $description = 'Charge RETAILGROUP MODE : enseignes, programme de fidélité, 300 clients et leurs tickets (données fictives)';

    private const SLUG = 'retailgroup-mode';

    private const EMAIL = 'admin.mode@retailgroup-demo.test';

    private const COLUMNS = ['date_vente', 'enseigne', 'magasin', 'numero_ticket', 'telephone', 'montant_net', 'nom', 'prenom', 'type_ligne', 'remise_fidelite', 'famille', 'categorie', 'article', 'quantite', 'prix_unitaire', 'vendeur', 'mode_paiement', 'sexe', 'date_naissance', 'quartier', 'email', 'consentement_whatsapp', 'code_client'];

    /** Enseignes : magasins (code => nom), règles du programme, catégorie et familles. */
    private const BRANDS = [
        'Adidas' => [
            'stores' => ['AD-GLA' => 'Adidas Glass', 'AD-MBO' => 'Adidas Mbolo'],
            'rule' => ['threshold' => 400000, 'tranche_amount' => 10000, 'points_per_tranche' => 1, 'activation_bonus' => 50, 'tiers' => [['points' => 300, 'percent' => 10], ['points' => 350, 'percent' => 15]], 'validity_months' => 12, 'reset_after_use' => true],
            'category' => 'Sport',
            'families' => ['Sneakers' => ['Samba OG', 'Gazelle', 'Stan Smith', 'Campus'], 'Running' => ['Ultraboost', 'Adizero', 'Duramo'], 'Survêtements' => ['Veste Tiro', 'Pantalon Essentials', 'Ensemble Linear'], 'Sacs de sport' => ['Sac Linear', 'Sac à dos Power']],
        ],
        'Mango' => [
            'stores' => ['MA-CV' => 'Mango Centre-ville', 'MA-AKA' => 'Mango Akanda'],
            'rule' => ['threshold' => 400000, 'tranche_amount' => 10000, 'points_per_tranche' => 1, 'activation_bonus' => 50, 'tiers' => [['points' => 350, 'percent' => 10], ['points' => 400, 'percent' => 15]], 'validity_months' => 12, 'reset_after_use' => true],
            'category' => 'Femme',
            'families' => ['Robes' => ['Robe fluide', 'Robe portefeuille', 'Robe en lin'], 'Hauts' => ['Blouse satinée', 'Chemise popeline', 'Top côtelé'], 'Pantalons femme' => ['Pantalon large', 'Jean droit'], 'Maroquinerie' => ['Sac cabas', 'Pochette']],
        ],
        'Carré' => [
            'stores' => ['CA-CV' => 'Carré Centre-ville', 'CA-GLA' => 'Carré Glass'],
            'rule' => ['threshold' => 650000, 'tranche_amount' => 15000, 'points_per_tranche' => 1, 'activation_bonus' => 75, 'tiers' => [['points' => 450, 'percent' => 10], ['points' => 550, 'percent' => 15]], 'validity_months' => 12, 'reset_after_use' => true],
            'category' => 'Homme',
            'families' => ['Costumes' => ['Costume deux pièces', 'Veste de costume'], 'Chemises' => ['Chemise slim', 'Chemise oxford'], 'Pantalons homme' => ['Chino', 'Pantalon de ville'], 'Chaussures de ville' => ['Derby cuir', 'Mocassin']],
        ],
    ];

    private const FIRST_NAMES = ['F' => ['Awa', 'Estelle', 'Grâce', 'Nadège', 'Prisca', 'Sandrine', 'Laetitia', 'Mireille', 'Carine', 'Inès', 'Audrey', 'Murielle'], 'H' => ['Jean', 'Paul', 'Ulrich', 'Fabrice', 'Hervé', 'Cédric', 'Yannick', 'Rodrigue', 'Serge', 'Davy', 'Arnaud', 'Loïc']];

    private const LAST_NAMES = ['Ndong', 'Mba', 'Obame', 'Nguema', 'Moussavou', 'Ondo', 'Bongo', 'Mabika', 'Ella', 'Koumba', 'Mouele', 'Essono', 'Boussougou', 'Nzigou', 'Mintsa', 'Assoumou', 'Engone', 'Mavoungou'];

    private const DISTRICTS = ['Louis', 'Glass', 'Akanda', 'Nombakélé', 'Owendo', 'Batterie IV', 'Sibang', 'Angondjé'];

    private const SELLERS = ['Lucie', 'Marc', 'Sonia', 'Thierry', 'Diane'];

    private const PAYMENTS = ['Espèces', 'Carte bancaire', 'Airtel Money', 'Moov Money'];

    /** @var array<string, int> */
    private array $counters = [];

    private int $personIndex = 0;

    private CarbonImmutable $end;

    /** @var array<string, list<array<string, mixed>>> */
    private array $cohorts = [];

    public function handle(SalesImport $import, LoyaltyEngine $engine): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Données fictives : refusé en production sans --force.');

            return self::FAILURE;
        }

        ini_set('memory_limit', '1024M');
        mt_srand(20261104);

        $existing = Organization::where('slug', self::SLUG)->first();

        if ($existing && ! $this->option('fresh')) {
            $this->warn("« {$existing->name} » est déjà chargée : rien n’a été modifié (ajoutez --fresh pour la recréer).");
            $this->summary($existing);

            return self::SUCCESS;
        }

        if ($existing) {
            $this->line('Suppression de l’entreprise de démonstration (et d’elle seule)…');
            Storage::disk('local')->deleteDirectory("imports/{$existing->id}");
            Campaign::whereHas('stores', fn ($q) => $q->where('organization_id', $existing->id))->delete();
            $existing->delete();
        }

        $this->end = CarbonImmutable::today()->min(CarbonImmutable::parse('2026-12-31'));
        $organization = $this->createOrganization();
        $this->createCategories($organization);
        $this->createPrograms($organization);

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $rows = $this->generateRows();
        $this->line(count($rows).' lignes de ventes générées, import en cours…');

        $this->runImport($import, $user, 'caisse-2025.csv', array_filter($rows, fn ($r) => $r['date_vente'] < '2026-01-01'));
        $this->runImport($import, $user, 'caisse-2026.csv', array_filter($rows, fn ($r) => $r['date_vente'] >= '2026-01-01'));

        $this->line('Recalcul des fiches, segments RFM et cartes de fidélité…');
        app(CustomerLifecycle::class)->refreshOrganization($organization);
        app(CustomerScoring::class)->refreshOrganization($organization);
        app(BrandMetrics::class)->refreshOrganization($organization);
        $engine->recalculate($organization);
        $this->manualDiscounts($organization, $user, $engine);

        if (! app()->runningUnitTests()) {
            $this->line('Fichier d’exemple : '.$this->sampleFile());
        }

        $this->summary($organization->fresh());

        return self::SUCCESS;
    }

    private function createOrganization(): Organization
    {
        $organization = Organization::create([
            'name' => 'RETAILGROUP MODE',
            'slug' => self::SLUG,
            'sector' => 'commerce',
            'whatsapp_api_enabled' => false,
            'currency' => 'XAF',
            'timezone' => 'Africa/Libreville',
            'product_analysis_level' => 'family',
            'modules' => ['enabled' => ['familles', 'lieux', 'fidelite']],
            'loyalty_settings' => ['enabled' => false],
            'privacy_email' => 'contact@retailgroup-demo.test',
        ]);

        Role::seedDefaultsFor($organization);
        $role = fn (string $name) => Role::where('organization_id', $organization->id)->where('name', $name)->value('id');

        foreach (self::BRANDS as $brandName => $definition) {
            $brand = Brand::create(['organization_id' => $organization->id, 'name' => $brandName]);

            foreach ($definition['stores'] as $code => $name) {
                Store::create(['organization_id' => $organization->id, 'brand_id' => $brand->id, 'name' => $name, 'code' => $code, 'city' => 'Libreville']);
            }
        }

        User::create(['organization_id' => $organization->id, 'name' => 'Admin Mode', 'email' => self::EMAIL, 'password' => 'Demo-Retail-2026', 'role_id' => $role('Admin groupe')]);

        return $organization;
    }

    private function createCategories(Organization $organization): void
    {
        foreach (self::BRANDS as $definition) {
            $parent = Category::create(['organization_id' => $organization->id, 'name' => $definition['category']]);

            foreach (array_keys($definition['families']) as $family) {
                Category::create(['organization_id' => $organization->id, 'name' => $family, 'parent_id' => $parent->id]);
            }
        }
    }

    private function createPrograms(Organization $organization): void
    {
        // Programme 2025 clôturé : il porte les cartes expirées (12 mois de validité).
        foreach ([['Fidélité 2025', '2025-01-01', '2025-12-31'], ['Fidélité 2026', '2026-01-01', '2026-12-31']] as [$name, $from, $to]) {
            $program = LoyaltyProgram::create(['organization_id' => $organization->id, 'name' => $name, 'starts_on' => $from, 'ends_on' => $to]);

            foreach (self::BRANDS as $brandName => $definition) {
                $program->rules()->create([...$definition['rule'], 'brand_id' => Brand::where('organization_id', $organization->id)->where('name', $brandName)->value('id')]);
            }
        }
    }

    /**
     * Toutes les lignes de ventes, cohorte par cohorte.
     *
     * @return list<array<string, mixed>>
     */
    private function generateRows(): array
    {
        $scale = max(1, (int) $this->option('clients')) / 305;
        $count = fn (int $n) => max(1, (int) round($n * $scale));
        $rows = [];
        $add = function (array $lines) use (&$rows) {
            array_push($rows, ...$lines);
        };
        $yearFrom = '2026-01-05';
        $yearTo = $this->end->toDateString();
        $recent = $this->end->subDays(25)->toDateString();
        $amountFor = fn (array $rule, int $points) => ($points - $rule['activation_bonus']) * $rule['tranche_amount'];

        $stories = [
            // Sous le seuil.
            'sous_le_seuil' => [80, fn ($p, $b, $r) => $this->spend($p, $b, (int) ($r['threshold'] * mt_rand(15, 75) / 100), $yearFrom, $yearTo)],
            'proche_du_seuil' => [30, fn ($p, $b, $r) => $this->spend($p, $b, (int) ($r['threshold'] * mt_rand(84, 97) / 100), $yearFrom, $yearTo)],
            // Le dernier ticket fait passer le seuil ces derniers jours.
            'juste_active' => [40, fn ($p, $b, $r) => [
                ...$this->spend($p, $b, (int) ($r['threshold'] * 0.85), $yearFrom, $recent),
                ...$this->spend($p, $b, (int) ($r['threshold'] * mt_rand(15, 27) / 100), $recent, $yearTo),
            ]],
            'proche_du_palier' => [25, fn ($p, $b, $r) => $this->spend($p, $b, $amountFor($r, $r['tiers'][0]['points'] - mt_rand(5, 25)), $yearFrom, $yearTo)],
            'palier_1' => [25, fn ($p, $b, $r) => $this->spend($p, $b, $amountFor($r, $r['tiers'][0]['points'] + mt_rand(2, $r['tiers'][1]['points'] - $r['tiers'][0]['points'] - 3)), $yearFrom, $yearTo)],
            'palier_2' => [15, fn ($p, $b, $r) => $this->spend($p, $b, $amountFor($r, $r['tiers'][1]['points'] + mt_rand(3, 40)), $yearFrom, $yearTo)],
            // Palier atteint, remise utilisée en caisse, puis ça recommence.
            'remise_utilisee' => [25, fn ($p, $b, $r) => [
                ...$this->spend($p, $b, $amountFor($r, $r['tiers'][0]['points'] + 5), $yearFrom, '2026-08-31'),
                ...$this->spend($p, $b, mt_rand(60, 140) * 1000, '2026-09-05', '2026-09-20', "{$r['tiers'][0]['percent']}%"),
                ...$this->spend($p, $b, mt_rand(40, 200) * 1000, '2026-09-25', $yearTo),
            ]],
            'retour' => [15, fn ($p, $b, $r) => [
                ...$this->spend($p, $b, (int) ($r['threshold'] * 1.3), $yearFrom, '2026-09-30'),
                $this->returnLine($p, $b, mt_rand(30, 120) * 1000, '2026-10-10'),
            ]],
            // Programme 2025 : activé il y a plus de 12 mois, carte expirée à l'automne 2026.
            'expire' => [20, fn ($p, $b, $r) => [
                ...$this->spend($p, $b, (int) ($r['threshold'] * 1.2), '2025-05-05', '2025-10-05'),
                ...$this->spend($p, $b, (int) ($r['threshold'] * 0.3), $yearFrom, $yearTo),
            ]],
            // Carte de 2025 qui arrive à échéance sous 30 jours.
            'expire_bientot' => [5, fn ($p, $b, $r) => [
                ...$this->spend($p, $b, (int) ($r['threshold'] * 0.9), '2025-06-01', '2025-11-05'),
                ...$this->spend($p, $b, (int) ($r['threshold'] * 0.2), $this->end->subYear()->addDays(5)->toDateString(), $this->end->subYear()->addDays(12)->toDateString()),
            ]],
        ];

        $brands = array_keys(self::BRANDS);

        foreach ($stories as $story => [$size, $build]) {
            for ($i = 0; $i < $count($size); $i++) {
                $person = $this->person();
                $brand = $brands[$i % 3];
                $add($build($person, $brand, self::BRANDS[$brand]['rule']));
                $this->cohorts[$story][] = [...$person, 'brand' => $brand];
            }
        }

        // Clients de plusieurs enseignes : membre dans l'une, en route dans l'autre.
        for ($i = 0; $i < $count(25); $i++) {
            $person = $this->person();
            $first = $brands[$i % 3];
            $second = $brands[($i + 1) % 3];
            $add($this->spend($person, $first, (int) (self::BRANDS[$first]['rule']['threshold'] * 1.15), $yearFrom, $yearTo));
            $add($this->spend($person, $second, (int) (self::BRANDS[$second]['rule']['threshold'] * mt_rand(30, 110) / 100), $yearFrom, $yearTo));

            if ($i % 3 === 0) {
                $third = $brands[($i + 2) % 3];
                $add($this->spend($person, $third, mt_rand(80, 250) * 1000, $yearFrom, $yearTo));
            }
            $this->cohorts['multi_enseignes'][] = [...$person, 'brand' => $first];
        }

        // Historique d'avant le programme, pour la plupart des clients.
        foreach ($this->cohorts as $people) {
            foreach ($people as $person) {
                if (mt_rand(1, 100) <= 45) {
                    $add($this->spend($person, $person['brand'], mt_rand(40, 180) * 1000, '2025-05-05', '2025-12-20'));
                }
            }
        }

        // Ventes sans client identifié.
        for ($i = 0; $i < $count(320); $i++) {
            $add($this->spend(null, $brands[$i % 3], mt_rand(15, 90) * 1000, '2025-05-05', $yearTo));
        }

        usort($rows, fn ($a, $b) => $a['date_vente'] <=> $b['date_vente']);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function person(): array
    {
        $i = ++$this->personIndex;
        $gender = $i % 5 < 3 ? 'F' : 'H';
        $first = self::FIRST_NAMES[$gender][$i % 12];
        $last = self::LAST_NAMES[($i * 7) % 18];

        return [
            'prenom' => $first,
            'nom' => $last,
            'telephone' => '+2416'.str_pad((string) (2000000 + $i * 1733), 7, '0', STR_PAD_LEFT),
            'sexe' => $gender,
            'date_naissance' => (1965 + $i % 40).'-'.str_pad((string) (1 + $i % 12), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT),
            'quartier' => self::DISTRICTS[$i % 8],
            'email' => $i % 3 === 0 ? 'client'.$i.'@retailgroup-demo.test' : '',
            'consentement_whatsapp' => $i % 4 === 0 ? 'non' : 'oui',
            'code_client' => 'CL'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * Répartit un montant exact en tickets sur une période. Le dernier ticket
     * porte la remise fidélité éventuelle.
     *
     * @param  array<string, mixed>|null  $person  null = vente anonyme
     * @return list<array<string, mixed>>
     */
    private function spend(?array $person, string $brand, int $total, string $from, string $to, string $discount = ''): array
    {
        $total = max(1000, (int) (round($total / 1000) * 1000));
        $parts = [];
        $unit = max($brand === 'Carré' ? 180000 : 120000, (int) ceil($total / 14));

        while ($total > 0) {
            $part = (int) (min($total, mt_rand((int) ($unit * 0.4), $unit) / 1000 * 1000));
            $part = max(1000, (int) (round($part / 1000) * 1000));
            $part = min($part, $total);
            $parts[] = $part;
            $total -= $part;
        }

        $start = CarbonImmutable::parse($from);
        $days = max(0, $start->diffInDays(CarbonImmutable::parse($to)));
        $dates = array_map(fn () => $start->addDays(mt_rand(0, (int) $days))->toDateString(), $parts);
        sort($dates);
        $lines = [];

        foreach ($parts as $i => $amount) {
            $last = $i === count($parts) - 1;
            $lines = [...$lines, ...$this->ticket($person, $brand, $dates[$i], $amount, $last ? $discount : '')];
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> une à trois lignes, ou une ligne sans détail */
    private function ticket(?array $person, string $brand, string $date, int $amount, string $discount): array
    {
        $definition = self::BRANDS[$brand];
        $code = array_rand($definition['stores']);
        $this->counters[$code] = ($this->counters[$code] ?? 0) + 1;
        $receipt = $code.'-'.str_pad((string) $this->counters[$code], 6, '0', STR_PAD_LEFT);
        $seller = self::SELLERS[mt_rand(0, 4)];
        $payment = self::PAYMENTS[mt_rand(0, 3)];
        $split = $amount >= 60000 && mt_rand(1, 3) > 1 ? mt_rand(2, 3) : 1;
        $lines = [];
        $left = $amount;

        for ($i = 0; $i < $split; $i++) {
            $lineAmount = $i === $split - 1 ? $left : (int) (round($amount / $split / 1000) * 1000);
            $left -= $lineAmount;
            $family = array_rand($definition['families']);
            $articles = $definition['families'][$family];
            // Quelques tickets arrivent sans détail : ils comptent quand même.
            $detailed = $this->counters[$code] % 11 !== 0;

            $lines[] = [
                ...array_fill_keys(self::COLUMNS, ''),
                ...($person !== null ? array_intersect_key($person, array_flip(self::COLUMNS)) : []),
                'date_vente' => $date,
                'enseigne' => $brand,
                'magasin' => $code,
                'numero_ticket' => $receipt,
                'montant_net' => $lineAmount,
                'type_ligne' => 'vente',
                'remise_fidelite' => $discount,
                'famille' => $detailed ? $family : '',
                'categorie' => $detailed ? $definition['category'] : '',
                'article' => $detailed ? $articles[mt_rand(0, count($articles) - 1)] : '',
                'quantite' => 1,
                'prix_unitaire' => $lineAmount,
                'vendeur' => $seller,
                'mode_paiement' => $payment,
            ];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function returnLine(array $person, string $brand, int $amount, string $date): array
    {
        $line = $this->ticket($person, $brand, $date, $amount, '')[0];

        return [...$line, 'montant_net' => -$amount, 'prix_unitaire' => $amount, 'type_ligne' => 'retour'];
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    private function runImport(SalesImport $import, User $user, string $name, array $rows): void
    {
        $dir = storage_path('app/demo-tmp');
        @mkdir($dir, 0775, true);
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', self::COLUMNS), ';', '"', '');
        }
        fclose($out);

        $batch = $import->upload(new UploadedFile($path, $name, null, null, true), $user);
        $import->validate($batch, $batch->mapping ?? [], null, $user);
        $batch->update(['status' => ImportBatch::STATUS_PROCESSING, 'started_at' => now()]);
        $import->process($batch);
        @unlink($path);

        $batch->refresh();
        $this->line("  {$name} : {$batch->tickets_count} tickets, {$batch->rejected_rows} ligne(s) rejetée(s).");
    }

    /** Quelques remises saisies depuis la fiche (bouton « Remise utilisée »). */
    private function manualDiscounts(Organization $organization, User $user, LoyaltyEngine $engine): void
    {
        $cards = LoyaltyCard::where('organization_id', $organization->id)->where('status', LoyaltyCard::STATUS_ACTIVE)
            ->whereNotNull('discount_percent')->orderBy('id')->take(3)->get();

        foreach ($cards as $card) {
            LoyaltyRedemption::create([
                'organization_id' => $organization->id,
                'loyalty_program_id' => $card->loyalty_program_id,
                'brand_id' => $card->brand_id,
                'customer_id' => $card->customer_id,
                'store_id' => Store::where('brand_id', $card->brand_id)->value('id'),
                'user_id' => $user->id,
                'used_on' => $this->end->subDays(2)->toDateString(),
                'discount_percent' => $card->discount_percent,
                'amount' => 15000,
            ]);
        }

        $engine->recalculate($organization, $cards->pluck('customer_id')->all());
    }

    /**
     * Fichier d'exemple à importer pendant la démonstration : il fait passer
     * un seuil, atteindre un palier, utilise une remise et enregistre un retour.
     */
    private function sampleFile(): string
    {
        $date = $this->end->toDateString();
        $sample = [];
        $row = function (array $person, int $amount, string $discount = '', string $type = 'vente') use (&$sample, $date) {
            $line = $this->ticket($person, $person['brand'], $date, abs($amount), $discount)[0];
            $sample[] = [...$line, 'numero_ticket' => 'DEMO-'.str_pad((string) (count($sample) + 1), 3, '0', STR_PAD_LEFT), 'montant_net' => $amount, 'prix_unitaire' => abs($amount), 'type_ligne' => $type];
        };

        foreach (array_slice($this->cohorts['proche_du_seuil'] ?? [], 0, 3) as $person) {
            $row($person, 90000);
        }
        foreach (array_slice($this->cohorts['proche_du_palier'] ?? [], 0, 3) as $person) {
            $row($person, 300000);
        }
        foreach (array_slice($this->cohorts['palier_1'] ?? [], 0, 2) as $person) {
            $row($person, 80000, self::BRANDS[$person['brand']]['rule']['tiers'][0]['percent'].'%');
        }
        foreach (array_slice($this->cohorts['juste_active'] ?? [], 0, 2) as $person) {
            $row($person, -40000, type: 'retour');
        }

        $path = base_path('docs/exemples/exemple-retailgroup-mode.xlsx');
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setTitle('Ventes')->fromArray([self::COLUMNS, ...array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? '', self::COLUMNS), $sample)], null, 'A1', true);
        (new Xlsx($sheet))->save($path);

        return $path;
    }

    private function summary(Organization $organization): void
    {
        $storeIds = $organization->stores()->pluck('id');
        $program = LoyaltyProgram::where('organization_id', $organization->id)->where('name', 'Fidélité 2026')->first();
        $count = fn (string $status) => LoyaltyCard::where('organization_id', $organization->id)->where('status', $status)->count();

        $this->table(['Élément', 'Nombre'], [
            ['Enseignes / magasins', $organization->brands()->count().' / '.$storeIds->count()],
            ['Fiches clients', Customer::where('organization_id', $organization->id)->count()],
            ['Tickets valides', Purchase::whereIn('store_id', $storeIds)->count()],
            ['Tickets anonymes', Purchase::whereIn('store_id', $storeIds)->whereNull('contact_id')->count()],
            ['Cartes actives', $count(LoyaltyCard::STATUS_ACTIVE)],
            ['Cartes expirées', $count(LoyaltyCard::STATUS_EXPIRED)],
            ['Clients non membres', $count(LoyaltyCard::STATUS_NONE)],
            ['Cartes du programme 2026', $program?->cards()->count() ?? 0],
        ]);
        $this->line('Connexion : '.self::EMAIL.' / Demo-Retail-2026');
    }
}
