<?php

/*
 * Module « Location et vente de véhicules » (pack Solo) : part de la Gestion
 * de flotte (véhicules, contrats) et y ajoute le cycle client du loueur :
 * demandes reçues (WhatsApp, Facebook, téléphone, agence), devis relancés à
 * 24 h et 72 h, réservation sans double location, contrat avec acompte,
 * caution et solde (Devis et factures), états des lieux sur téléphone,
 * caution rendue ou retenue, remerciement et avis, comptes entreprise,
 * véhicules à vendre et suivi des acheteurs. Pas d'ERP : ni comptabilité,
 * ni pièces détachées, ni GPS, ni planning des chauffeurs.
 */
return [
    'key' => 'location',
    'label' => 'Location et vente de véhicules',
    'description' => 'Demandes et devis relancés, réservations par véhicule, états des lieux avec photos, cautions, ventes de véhicules et tableau de bord du loueur.',
    'pack' => 'solo',
    'depends' => ['vehicules', 'factures'],
    'app' => 'rental',
    'automation' => 'whatsapp',
];
