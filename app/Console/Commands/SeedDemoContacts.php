<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\ContactImporter;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Génère des clients fictifs réalistes (nom, téléphone, achats, âge, sexe)
 * pour chaque boutique de RETAIL GROUP, et les fait passer par le même
 * chemin que l'écran d'import (/imports -> ContactImporter) plutôt que de
 * les insérer directement en base : ça valide l'import en conditions
 * réelles en plus de peupler des données de démo.
 */
class SeedDemoContacts extends Command
{
    protected $signature = 'demo:seed-contacts {--per-store=20 : Nombre de clients fictifs à générer par boutique} {--force : Autoriser l\'exécution en production}';

    protected $description = 'Génère des clients fictifs (achats, âge, sexe) et les importe via ContactImporter pour chaque boutique';

    private const FIRST_NAMES_F = [
        'Marie', 'Sylvie', 'Ghislaine', 'Rosine', 'Judith', 'Stéphanie', 'Prisca', 'Carelle',
        'Nadège', 'Audrey', 'Larissa', 'Gwladys', 'Mireille', 'Chantal', 'Emmanuella', 'Fabiola',
    ];

    private const FIRST_NAMES_H = [
        'Jean', 'Pierre', 'Paul', 'Christian', 'Rodrigue', 'Landry', 'Steevy', 'Ulrich',
        'Herman', 'Wilfried', 'Yannick', 'Brice', 'Fabrice', 'Cédric', 'Franck', 'Junior',
    ];

    private const LAST_NAMES = [
        'Obame', 'Mba', 'Ondo', 'Nguema', 'Moussavou', 'Ndong', 'Ella', 'Ngoua', 'Meye',
        'Avome', 'Bivigou', 'Ogandaga', 'Mihindou', 'Ntoutoume', 'Ovono', 'Allogho',
    ];

    private const CATEGORIES_BY_STORE = [
        'Adidas' => ['Sneakers', 'Survêtement', 'Maillots', 'Accessoires sport'],
        'Carré' => ['Costumes', 'Chemises', 'Chaussures homme', 'Ceintures'],
        'Mango' => ['Robes', 'Tailleurs', 'Chaussures femme', 'Sacs à main'],
        'Grand Store' => ['Vêtements enfant', 'Linge de maison', 'Bijoux', 'Parfums'],
    ];

    public function handle(ContactImporter $importer): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Commande de données de démonstration : refusée en production sans --force.');

            return self::FAILURE;
        }

        $perStore = (int) $this->option('per-store');
        $usedPhones = [];

        foreach (Store::with('brand')->get() as $store) {
            $categories = self::CATEGORIES_BY_STORE[$store->brand->name] ?? ['Divers'];

            $csvPath = tempnam(sys_get_temp_dir(), 'demo_contacts_').'.csv';
            $handle = fopen($csvPath, 'w');
            fputcsv($handle, ['nom', 'telephone', 'categorie', 'montant', 'date_achat', 'naissance', 'sexe']);

            $storeClients = [];

            for ($i = 0; $i < $perStore; $i++) {
                $isFemale = random_int(0, 1) === 1;
                $firstName = $isFemale
                    ? self::FIRST_NAMES_F[array_rand(self::FIRST_NAMES_F)]
                    : self::FIRST_NAMES_H[array_rand(self::FIRST_NAMES_H)];
                $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];

                do {
                    $phone = '241'.random_int(60000000, 77999999);
                } while (isset($usedPhones[$phone]));
                $usedPhones[$phone] = true;

                $name = "{$firstName} {$lastName}";
                $birthdate = now()->subYears(random_int(19, 60))->subDays(random_int(0, 365))->format('Y-m-d');

                $storeClients[] = ['name' => $name, 'phone' => $phone, 'birthdate' => $birthdate, 'gender' => $isFemale ? 'F' : 'H'];

                fputcsv($handle, [
                    $name,
                    $phone,
                    $categories[array_rand($categories)],
                    random_int(15, 450) * 1000,
                    now()->subDays(random_int(0, 365))->format('Y-m-d'),
                    $birthdate,
                    $isFemale ? 'F' : 'H',
                ]);
            }

            // ~30% de clients fidèles : un second (voire troisième) achat sur une
            // date différente, utile pour une future analyse RFM (fréquence).
            foreach ($storeClients as $client) {
                if (random_int(1, 100) > 30) {
                    continue;
                }

                fputcsv($handle, [
                    $client['name'],
                    $client['phone'],
                    $categories[array_rand($categories)],
                    random_int(15, 450) * 1000,
                    now()->subDays(random_int(0, 180))->format('Y-m-d'),
                    $client['birthdate'],
                    $client['gender'],
                ]);
            }

            fclose($handle);

            $file = new UploadedFile($csvPath, basename($csvPath), 'text/csv', null, true);
            $summary = $importer->import($file, $store);
            unlink($csvPath);

            $this->info(sprintf(
                '%s : %d créé(s), %d mis à jour, %d achat(s) enregistré(s)%s',
                $store->name,
                $summary['contacts_created'],
                $summary['contacts_updated'],
                $summary['purchases_recorded'],
                $summary['skipped'] ? ' — '.count($summary['skipped']).' ignoré(s)' : '',
            ));
        }

        return self::SUCCESS;
    }
}
