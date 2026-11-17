<?php

/*
 * Manifeste des modules historiques de ClientlyX (code encore dans app/Http,
 * app/Models…). Chaque entrée : pack minimum, dépendances, application du
 * lanceur où il apparaît, et automatisation (WhatsApp / IA) qu'il apporte.
 * Libellés et descriptions : Organization::MODULES.
 */
return [
    ['key' => 'rdv', 'pack' => 'gratuit', 'app' => 'agenda'],
    ['key' => 'catalogue', 'pack' => 'gratuit', 'app' => 'sales'],
    ['key' => 'produits', 'pack' => 'gratuit', 'app' => 'sales'],
    ['key' => 'seances_groupe', 'pack' => 'solo', 'app' => 'agenda'],
    ['key' => 'abonnements', 'pack' => 'solo', 'app' => 'sales'],
    ['key' => 'essai', 'pack' => 'solo', 'app' => 'customers'],
    ['key' => 'formation', 'pack' => 'solo', 'app' => 'learners'],
    ['key' => 'vehicules', 'pack' => 'solo', 'app' => 'fleet'],
    ['key' => 'ressources', 'pack' => 'solo', 'app' => 'agenda'],
    ['key' => 'beneficiaires', 'pack' => 'solo', 'app' => 'customers'],
    ['key' => 'rappels_rdv', 'pack' => 'solo', 'app' => 'agenda', 'depends' => ['rdv'], 'automation' => 'whatsapp'],
    ['key' => 'relance_cycle', 'pack' => 'solo', 'app' => 'customers', 'automation' => 'whatsapp'],
    ['key' => 'reponses_auto', 'pack' => 'solo', 'app' => 'discussions', 'automation' => 'whatsapp'],
    ['key' => 'relance_devis', 'pack' => 'solo', 'app' => 'invoices', 'automation' => 'whatsapp'],
    ['key' => 'tranches', 'pack' => 'equipe', 'app' => 'sales'],
    ['key' => 'prospects', 'pack' => 'equipe', 'app' => 'prospects'],
    ['key' => 'fidelite', 'pack' => 'equipe', 'app' => 'customers'],
    ['key' => 'campagnes', 'pack' => 'equipe', 'app' => 'campaigns', 'automation' => 'whatsapp'],
    ['key' => 'lieux', 'pack' => 'premium', 'app' => 'settings'],
    ['key' => 'familles', 'pack' => 'premium', 'app' => 'analytics'],
    ['key' => 'agents_ia', 'pack' => 'premium', 'app' => 'discussions', 'automation' => 'ia'],
];
