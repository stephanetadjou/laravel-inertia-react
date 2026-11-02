<?php

namespace Database\Seeders;

/**
 * Profils des entreprises de démonstration Menentia Retail (données
 * fictives) : groupe de mode à Libreville, chaîne de restaurants,
 * distributeur de produits de grande consommation. Utilisés par
 * `php artisan demo:retail` (App\Console\Commands\SeedRetailDemo).
 *
 * Chaque article : [libellé, code, famille, prix mini, prix maxi, genre].
 * Pas de taille ni de couleur : l'analyse se fait par article, famille ou catégorie.
 */
class RetailDemoProfiles
{
    public const TYPES = ['mode', 'restaurant', 'distribution'];

    public static function get(string $type): array
    {
        return match ($type) {
            'restaurant' => self::restaurant(),
            'distribution' => self::distribution(),
            default => self::mode(),
        };
    }

    private static function mode(): array
    {
        return [
            'slug' => 'demo-retail-mode',
            'name' => 'Retail Group Démo (mode)',
            'email_prefix' => 'mode',
            // Démo construite autour d'histoires (SeedRetailDemo::storyRows) : cohortes de clients
            // aux comportements choisis, pour que chaque constat se lise dans les écrans.
            'stories' => true,
            'tickets' => 3500,
            'customers' => 850,
            'anonymous_share' => 15,
            'level' => 'family',
            'loyalty' => true,
            'stores' => [
                ['brand' => 'Adidas', 'store' => 'Adidas Glass', 'code' => 'ADI-GLA', 'city' => 'Libreville (Glass)', 'prefix' => 'ADG', 'weight' => 25, 'manager' => 'adidas'],
                ['brand' => 'Grand Store', 'store' => 'Grand Store Glass', 'code' => 'GST-GLA', 'city' => 'Libreville (Glass)', 'prefix' => 'GSG', 'weight' => 35, 'manager' => 'grandstore'],
                ['brand' => 'Mango', 'store' => 'Mango Centre-ville', 'code' => 'MNG-CV', 'city' => 'Libreville (Centre-ville)', 'prefix' => 'MCV', 'weight' => 22, 'manager' => 'mango'],
                ['brand' => 'Carré', 'store' => 'Carré Centre-ville', 'code' => 'CAR-CV', 'city' => 'Libreville (Centre-ville)', 'prefix' => 'CCV', 'weight' => 18, 'manager' => 'carre'],
            ],
            // Catégorie => familles (avec mots-clés pour la reconnaissance automatique).
            'categories' => [
                'Chaussures' => ['Sneakers' => 'sneaker, basket', 'Running' => 'running, course', 'Football' => 'crampon, foot', 'Chaussures ville' => 'derby, mocassin', 'Chaussures femme' => 'escarpin, sandale'],
                'Sport & streetwear' => ['Survêtements' => 'survetement, jogging', 'T-shirts sport' => 'tee, maillot', 'Accessoires sport' => 'sac de sport, gourde'],
                'Vêtements homme' => ['Costumes' => 'costume, blazer', 'Chemises' => 'chemise', 'Pantalons homme' => 'chino, pantalon', 'Polos' => 'polo'],
                'Vêtements femme' => ['Robes' => 'robe', 'Tops & blouses' => 'top, blouse', 'Jeans femme' => 'jean', 'Vestes femme' => 'veste, trench'],
                'Accessoires' => ['Sacs' => 'sac, pochette', 'Bijoux' => 'collier, bracelet, boucles', 'Accessoires homme' => 'cravate, ceinture'],
                'Maison' => ['Linge de maison' => 'drap, serviette', 'Décoration' => 'bougie, cadre, vase'],
                'Beauté' => ['Parfums' => 'parfum, eau de toilette', 'Soins' => 'creme, lait'],
                'Enfant' => ['Mode enfant' => 'enfant, bebe', 'Jouets' => 'jouet, peluche'],
            ],
            'articles' => [
                'Adidas' => [
                    ['Sneakers basses cuir blanc', 'AD-SNK-01', 'Sneakers', 55000, 85000, 'Mixte'],
                    ['Sneakers rétro daim', 'AD-SNK-02', 'Sneakers', 60000, 90000, 'Mixte'],
                    ['Chaussures running légères', 'AD-RUN-01', 'Running', 45000, 75000, 'Homme'],
                    ['Chaussures running femme', 'AD-RUN-02', 'Running', 45000, 70000, 'Femme'],
                    ['Crampons football terrain sec', 'AD-FOT-01', 'Football', 40000, 95000, 'Homme'],
                    ['Survêtement training', 'AD-SVT-01', 'Survêtements', 38000, 55000, 'Homme'],
                    ['Veste coupe-vent', 'AD-SVT-02', 'Survêtements', 30000, 45000, 'Mixte'],
                    ['T-shirt technique', 'AD-TSH-01', 'T-shirts sport', 12000, 18000, 'Mixte'],
                    ['Maillot football', 'AD-TSH-02', 'T-shirts sport', 25000, 40000, 'Homme'],
                    ['Sac à dos sport', 'AD-ACC-01', 'Accessoires sport', 20000, 30000, 'Mixte'],
                    ['Chaussettes sport (lot de 3)', 'AD-ACC-02', 'Accessoires sport', 6000, 9000, 'Mixte'],
                ],
                'Carré' => [
                    ['Costume deux pièces', 'CA-COS-01', 'Costumes', 120000, 220000, 'Homme'],
                    ['Blazer uni', 'CA-COS-02', 'Costumes', 75000, 120000, 'Homme'],
                    ['Chemise coton manches longues', 'CA-CHE-01', 'Chemises', 22000, 35000, 'Homme'],
                    ['Chemise lin', 'CA-CHE-02', 'Chemises', 28000, 40000, 'Homme'],
                    ['Pantalon chino', 'CA-PAN-01', 'Pantalons homme', 25000, 38000, 'Homme'],
                    ['Polo piqué', 'CA-POL-01', 'Polos', 18000, 26000, 'Homme'],
                    ['Derby cuir', 'CA-CHA-01', 'Chaussures ville', 65000, 110000, 'Homme'],
                    ['Cravate soie', 'CA-ACC-01', 'Accessoires homme', 15000, 25000, 'Homme'],
                    ['Ceinture cuir', 'CA-ACC-02', 'Accessoires homme', 18000, 28000, 'Homme'],
                ],
                'Mango' => [
                    ['Robe midi fluide', 'MA-ROB-01', 'Robes', 35000, 60000, 'Femme'],
                    ['Robe de soirée', 'MA-ROB-02', 'Robes', 55000, 90000, 'Femme'],
                    ['Blouse imprimée', 'MA-TOP-01', 'Tops & blouses', 18000, 30000, 'Femme'],
                    ['Top côtelé', 'MA-TOP-02', 'Tops & blouses', 12000, 18000, 'Femme'],
                    ['Jean mom taille haute', 'MA-JEA-01', 'Jeans femme', 28000, 40000, 'Femme'],
                    ['Trench classique', 'MA-VES-01', 'Vestes femme', 60000, 95000, 'Femme'],
                    ['Sac cabas cuir', 'MA-SAC-01', 'Sacs', 35000, 65000, 'Femme'],
                    ['Pochette du soir', 'MA-SAC-02', 'Sacs', 18000, 30000, 'Femme'],
                    ['Boucles d’oreilles dorées', 'MA-BIJ-01', 'Bijoux', 8000, 15000, 'Femme'],
                    ['Sandales à talon', 'MA-CHA-01', 'Chaussures femme', 30000, 50000, 'Femme'],
                ],
                'Grand Store' => [
                    ['Parure de draps 2 places', 'GS-LIN-01', 'Linge de maison', 25000, 45000, ''],
                    ['Serviettes de bain (lot)', 'GS-LIN-02', 'Linge de maison', 12000, 20000, ''],
                    ['Bougie parfumée', 'GS-DEC-01', 'Décoration', 8000, 15000, ''],
                    ['Vase céramique', 'GS-DEC-02', 'Décoration', 15000, 30000, ''],
                    ['Eau de parfum 100 ml', 'GS-PAR-01', 'Parfums', 45000, 85000, 'Femme'],
                    ['Eau de toilette homme', 'GS-PAR-02', 'Parfums', 35000, 65000, 'Homme'],
                    ['Crème hydratante visage', 'GS-SOI-01', 'Soins', 12000, 25000, 'Femme'],
                    ['Ensemble bébé coton', 'GS-ENF-01', 'Mode enfant', 15000, 25000, 'Enfant'],
                    ['Robe fille', 'GS-ENF-02', 'Mode enfant', 15000, 28000, 'Enfant'],
                    ['Peluche éléphant', 'GS-JOU-01', 'Jouets', 9000, 15000, 'Enfant'],
                    ['Jeu de construction', 'GS-JOU-02', 'Jouets', 20000, 45000, 'Enfant'],
                    ['Polo homme', 'GS-POL-01', 'Polos', 15000, 22000, 'Homme'],
                    ['Robe portefeuille', 'GS-ROB-01', 'Robes', 28000, 45000, 'Femme'],
                ],
            ],
        ];
    }

    private static function restaurant(): array
    {
        return [
            'slug' => 'demo-retail-restaurant',
            'name' => 'Saveurs d’Akanda Démo (restaurants)',
            'email_prefix' => 'resto',
            'tickets' => 900,
            'customers' => 300,
            'anonymous_share' => 40,
            'level' => 'family',
            'loyalty' => true,
            'stores' => [
                ['brand' => 'Le Grill d’Akanda', 'store' => 'Grill Akanda', 'code' => 'GRL-AKA', 'city' => 'Akanda', 'prefix' => 'GRA', 'weight' => 45, 'manager' => 'grill'],
                ['brand' => 'Mboka Burger', 'store' => 'Mboka Burger Louis', 'code' => 'MBK-LOU', 'city' => 'Libreville (Louis)', 'prefix' => 'MBL', 'weight' => 30, 'manager' => 'mboka'],
                ['brand' => 'Mboka Burger', 'store' => 'Mboka Burger Owendo', 'code' => 'MBK-OWE', 'city' => 'Owendo', 'prefix' => 'MBO', 'weight' => 25, 'manager' => null],
            ],
            'categories' => [
                'Restauration' => ['Repas sur place' => 'sur place, salle', 'À emporter' => 'emporter, livraison'],
            ],
            // Restaurant : tickets de totaux seulement (famille par défaut au mapping).
            'totals_only' => true,
            'default_family' => 'Repas sur place',
            'articles' => [],
        ];
    }

    private static function distribution(): array
    {
        return [
            'slug' => 'demo-retail-distribution',
            'name' => 'Estuaire Distribution Démo (PGC)',
            'email_prefix' => 'distri',
            'tickets' => 700,
            'customers' => 120,
            'anonymous_share' => 3,
            'business_share' => 85,
            'level' => 'category',
            'loyalty' => false,
            'stores' => [
                ['brand' => 'Estuaire Distribution', 'store' => 'Dépôt Owendo', 'code' => 'DEP-OWE', 'city' => 'Owendo', 'prefix' => 'FOW', 'weight' => 60, 'manager' => 'depots'],
                ['brand' => 'Estuaire Distribution', 'store' => 'Dépôt Ntoum', 'code' => 'DEP-NTO', 'city' => 'Ntoum', 'prefix' => 'FNT', 'weight' => 40, 'manager' => null],
            ],
            'categories' => [
                'Boissons' => ['Sodas' => 'soda, cola', 'Eaux' => 'eau minerale', 'Jus' => 'jus, nectar'],
                'Épicerie' => ['Riz' => 'riz', 'Huiles' => 'huile', 'Conserves' => 'conserve, sardine, tomate'],
                'Hygiène' => ['Savons' => 'savon', 'Lessives' => 'lessive, detergent'],
            ],
            'articles' => [
                'Estuaire Distribution' => [
                    ['Soda cola 33 cl (pack 24)', 'PGC-SOD-01', 'Sodas', 9000, 11000, ''],
                    ['Soda orange 1,5 L (pack 6)', 'PGC-SOD-02', 'Sodas', 5500, 7000, ''],
                    ['Eau minérale 1,5 L (pack 6)', 'PGC-EAU-01', 'Eaux', 2500, 3200, ''],
                    ['Jus de fruits 1 L (carton 12)', 'PGC-JUS-01', 'Jus', 14000, 18000, ''],
                    ['Riz parfumé sac 25 kg', 'PGC-RIZ-01', 'Riz', 16000, 19000, ''],
                    ['Riz brisé sac 50 kg', 'PGC-RIZ-02', 'Riz', 24000, 28000, ''],
                    ['Huile végétale bidon 20 L', 'PGC-HUI-01', 'Huiles', 24000, 30000, ''],
                    ['Sardines à l’huile (carton 50)', 'PGC-CON-01', 'Conserves', 22000, 27000, ''],
                    ['Concentré de tomate (carton 48)', 'PGC-CON-02', 'Conserves', 15000, 19000, ''],
                    ['Savon de ménage (carton 72)', 'PGC-SAV-01', 'Savons', 18000, 22000, ''],
                    ['Lessive poudre 5 kg', 'PGC-LES-01', 'Lessives', 7000, 9500, ''],
                ],
            ],
        ];
    }

    public const FIRST_NAMES = ['Aïcha', 'Brice', 'Carine', 'Cédric', 'Christelle', 'Davy', 'Diane', 'Edwige', 'Fabrice', 'Flore', 'Ghislain', 'Grâce', 'Hervé', 'Inès', 'Joël', 'Josiane', 'Kévin', 'Laetitia', 'Landry', 'Lydie', 'Marcel', 'Murielle', 'Nadège', 'Noël', 'Olivia', 'Patrick', 'Prisca', 'Rodrigue', 'Sandrine', 'Serge', 'Stéphanie', 'Thierry', 'Ursule', 'Valérie', 'Wilfried', 'Yannick', 'Zita', 'Armel', 'Bénédicte', 'Dimitri'];

    public const LAST_NAMES = ['Mba', 'Nzé', 'Obame', 'Ondo', 'Ndong', 'Moussavou', 'Mouketou', 'Nguema', 'Ella', 'Essono', 'Bekale', 'Mintsa', 'Koumba', 'Mabika', 'Boussougou', 'Ngoma', 'Nzamba', 'Mbadinga', 'Oyane', 'Edou', 'Mengue', 'Assoumou', 'Bivigou', 'Makaya', 'Pambou'];

    public const COMPANY_KINDS = ['Alimentation', 'Boutique', 'Dépôt', 'Supérette', 'Ets', 'Maquis', 'Restaurant'];

    public const QUARTERS = ['Akébé', 'Nzeng-Ayong', 'Lalala', 'Okala', 'PK8', 'Charbonnages', 'Glass', 'Louis', 'Owendo', 'Ntoum', 'Mindoubé', 'Sotega'];
}
