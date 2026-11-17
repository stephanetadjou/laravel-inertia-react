<?php

/*
 * Module « Tunnels de vente » (à partir de l'offre Solo) : une page d'offre
 * pour une campagne (mariages, fêtes, séance d'essai…) avec compte à rebours
 * ou places limitées, formulaire court et bouton WhatsApp ; chaque demande
 * passe par le moteur du lien public (fiche client, demande, « À faire »),
 * puis une séquence WhatsApp J0 / J+1 / J+3 qui s'arrête dès l'achat ou au
 * STOP. Nombre de tunnels actifs par offre : config/nentix.php (« funnels »).
 */
return [
    'key' => 'tunnels',
    'label' => 'Tunnels de vente',
    'description' => 'Pages d’offre pour vos campagnes (fêtes, mariages, essai gratuit…) avec compte à rebours, formulaire, relances WhatsApp et statistiques.',
    'pack' => 'solo',
    'depends' => [],
    'app' => 'funnels',
    'automation' => 'whatsapp',
];
