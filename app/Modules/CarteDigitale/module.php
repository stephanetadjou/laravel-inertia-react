<?php

/*
 * Module « Carte digitale » : cartes de visite digitales par collaborateur
 * (page publique /c/{slug}, QR code, vCard, prise de RDV, formulaire de
 * contact). Gratuit et actif dans toutes les offres (produit d'appel) ; seul
 * le nombre de cartes dépend du pack (config/nentix.php, clé « cards »).
 * Chaque contact reçu crée une fiche client (source « carte ») et une tâche
 * dans « À faire ».
 */
return [
    'key' => 'carte_digitale',
    'label' => 'Carte digitale',
    'description' => 'Cartes de visite digitales avec QR code : chaque contact reçu devient une fiche client.',
    'pack' => 'gratuit',
    'depends' => [],
    'app' => 'cards',
];
