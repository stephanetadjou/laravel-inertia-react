<?php

/*
 * ClientlyX : packs vendus et règles d'accès aux modules (cf. App\Support\Modules).
 * Prix mensuels en FCFA ; null = sur devis. Chaque module déclare dans son
 * manifeste (app/Modules/<Nom>/module.php) le pack minimum qui l'inclut.
 */
return [
    // false tant que la grille n'est pas en vente : les packs s'affichent
    // (cadenas, « Disponible en Équipe ») sans retirer de module aux comptes existants.
    'enforce_packs' => (bool) env('NENTIX_ENFORCE_PACKS', false),

    // Pack appliqué pendant l'essai gratuit de 14 jours.
    'trial_pack' => 'equipe',

    'packs' => [
        'gratuit' => [
            'label' => 'Gratuit',
            'price' => 0,
            'tagline' => 'Le CRM léger pour démarrer',
            'users' => 1,
            'sites' => 1,
            'customers' => 100,
            'automation' => false,
            // Cartes digitales : nombre (null = illimité), mention « Créé avec ClientlyX »,
            // période maximale des statistiques (jours), impression 85 × 55 mm.
            'cards' => 3,
            'card_branding' => true,
            // Tunnels de vente actifs (0 = non inclus, null = illimité).
            'funnels' => 0,
            'card_stats_days' => 7,
            'card_print' => false,
            'highlights' => ['Fiches clients et historique', 'Agenda et ventes simples', 'Relances à la main par lien WhatsApp', '3 cartes digitales avec QR code'],
        ],
        'solo' => [
            'label' => 'Solo',
            'price' => 9900,
            'tagline' => 'Votre métier, automatisé',
            'users' => 1,
            'sites' => 1,
            'customers' => 500,
            'automation' => true,
            // Cartes digitales : nombre (null = illimité), mention « Créé avec ClientlyX »,
            // période maximale des statistiques (jours), impression 85 × 55 mm.
            'cards' => 10,
            'card_branding' => true,
            // Tunnels de vente actifs (0 = non inclus, null = illimité).
            'funnels' => 1,
            'card_stats_days' => 30,
            'card_print' => false,
            'highlights' => ['Le module de votre secteur', 'Rappels et réponses automatiques WhatsApp', 'Relance par cycle de prestation', '10 cartes digitales'],
        ],
        'equipe' => [
            'label' => 'Équipe',
            'price' => 25000,
            'tagline' => 'Piloter l’entreprise au quotidien',
            'users' => 5,
            'sites' => 1,
            'customers' => 3000,
            'automation' => true,
            // Cartes digitales : nombre (null = illimité), mention « Créé avec ClientlyX »,
            // période maximale des statistiques (jours), impression 85 × 55 mm.
            'cards' => 30,
            'card_branding' => false,
            // Tunnels de vente actifs (0 = non inclus, null = illimité).
            'funnels' => 3,
            'card_stats_days' => 90,
            'card_print' => true,
            'highlights' => ['Devis et factures avec acomptes', 'Caisse du jour et dépenses', 'Prospects, campagnes, fidélité', '30 cartes digitales sans mention, avec statistiques'],
        ],
        'premium' => [
            'label' => 'Premium',
            'price' => 50000,
            'tagline' => 'Plusieurs sites, l’analyse et l’IA',
            'users' => 15,
            'sites' => 5,
            'customers' => null,
            'automation' => true,
            // Cartes digitales : nombre (null = illimité), mention « Créé avec ClientlyX »,
            // période maximale des statistiques (jours), impression 85 × 55 mm.
            'cards' => null,
            'card_branding' => false,
            // Tunnels de vente actifs (0 = non inclus, null = illimité).
            'funnels' => null,
            'card_stats_days' => 365,
            'card_print' => true,
            'highlights' => ['Plusieurs sites', 'Analyse clients avancée et rapports', 'Agents IA et suivi des commerciaux', 'Cartes digitales illimitées'],
        ],
        'entreprise' => [
            'label' => 'Entreprise',
            'price' => null,
            'tagline' => 'Sur mesure',
            'users' => null,
            'sites' => null,
            'customers' => null,
            'automation' => true,
            // Cartes digitales : nombre (null = illimité), mention « Créé avec ClientlyX »,
            // période maximale des statistiques (jours), impression 85 × 55 mm.
            'cards' => null,
            'card_branding' => false,
            // Tunnels de vente actifs (0 = non inclus, null = illimité).
            'funnels' => null,
            'card_stats_days' => 365,
            'card_print' => true,
            'highlights' => ['Tout ClientlyX', 'Plusieurs entreprises', 'Accompagnement dédié'],
        ],
    ],

    // Pas d'utilisateur ni de carte en option : une entreprise qui grandit passe à l'offre supérieure.
];
