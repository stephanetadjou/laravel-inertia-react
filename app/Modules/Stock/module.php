<?php

/*
 * Module « Stock » (pack Solo) : stock léger des produits vendus en
 * complément d'une prestation (opticien : montures et verres ; salon :
 * soins ; kiné : accessoires). Le stock baisse à chaque vente, alerte de
 * réassort, entrée de marchandise (avec dépense si le module Dépenses est
 * actif) et inventaire. Les commerces 100 % produits utilisent l'Analyse.
 */
return [
    'key' => 'stock',
    'label' => 'Stock des produits',
    'description' => 'Stock des produits vendus avec vos prestations : baisse à chaque vente, alerte de réassort, inventaire.',
    'pack' => 'solo',
    'depends' => ['catalogue', 'produits'],
    'app' => 'stock',
];
