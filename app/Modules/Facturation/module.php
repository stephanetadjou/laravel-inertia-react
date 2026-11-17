<?php

/*
 * Module « Devis et factures » (pack Équipe) : devis envoyé par lien
 * WhatsApp et accepté en ligne, factures et factures d'acompte, paiements
 * (chaque paiement est une vente du client : fiche, RFM, caisse du jour),
 * relance des impayés.
 */
return [
    'key' => 'factures',
    'label' => 'Devis et factures',
    'description' => 'Devis acceptés par lien WhatsApp, acomptes, factures, paiements et relances des impayés.',
    'pack' => 'equipe',
    'depends' => [],
    'app' => 'invoices',
];
