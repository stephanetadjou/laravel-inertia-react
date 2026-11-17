<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Lien public « Que souhaitez-vous ? » (/rdv/{entreprise}) : les choix
 * proposés au client selon son étape (découverte, intéressé, RDV ou essai,
 * commande), filtrés par les modules actifs et cochés par l'entreprise dans
 * Paramètres › Ma page en ligne (organizations.online_page).
 */
class OnlinePage
{
    public const STAGES = ['discover' => 'Découverte', 'interested' => 'Intéressé', 'trial' => 'RDV / essai', 'order' => 'Commande'];

    /** @var array<string, array{label: string, sub: string, stage: string, module: ?string}> */
    public const CHOICES = [
        'ride' => ['label' => 'Course / transfert', 'sub' => 'Aéroport, hôtel, domicile', 'stage' => 'order', 'module' => 'location'],
        'rent' => ['label' => 'Louer un véhicule', 'sub' => 'Avec ou sans chauffeur', 'stage' => 'order', 'module' => 'location'],
        'event' => ['label' => 'Mariage / événement', 'sub' => 'Cortège, VIP, minibus : devis', 'stage' => 'interested', 'module' => 'location'],
        'buy' => ['label' => 'Véhicules à vendre', 'sub' => 'Prix, photos, paiement en plusieurs fois', 'stage' => 'interested', 'module' => 'location'],
        'test_drive' => ['label' => 'Essayer un véhicule', 'sub' => 'Essai avant achat', 'stage' => 'trial', 'module' => 'location'],
        'rdv' => ['label' => 'Prendre rendez-vous', 'sub' => 'Choisissez votre créneau', 'stage' => 'trial', 'module' => 'rdv'],
        'trial' => ['label' => 'Séance d’essai', 'sub' => 'Venez essayer', 'stage' => 'trial', 'module' => null],
        'visit' => ['label' => 'Programmer une visite', 'sub' => 'Sur place, à la date de votre choix', 'stage' => 'trial', 'module' => null],
        'quote' => ['label' => 'Demander un devis', 'sub' => 'Réponse sur WhatsApp', 'stage' => 'interested', 'module' => null],
        'prices' => ['label' => 'Tarifs', 'sub' => 'Nos prix en un coup d’œil', 'stage' => 'discover', 'module' => null],
        'callback' => ['label' => 'Être rappelé', 'sub' => 'Un conseiller vous écrit', 'stage' => 'discover', 'module' => null],
    ];

    /** Choix cochés d'office par secteur (l'entreprise ajuste dans Ma page en ligne). */
    public const SECTOR_DEFAULTS = [
        'location' => ['ride', 'rent', 'event', 'buy', 'test_drive', 'prices', 'callback'],
        'vehicules' => ['rent', 'buy', 'test_drive', 'prices', 'callback'],
        'sport' => ['trial', 'rdv', 'prices', 'callback'],
        'immobilier' => ['visit', 'rdv', 'prices', 'callback'],
        'pro' => ['quote', 'rdv', 'prices', 'callback'],
        'technique' => ['quote', 'rdv', 'prices', 'callback'],
    ];

    /** Choix que l'entreprise peut proposer (modules actifs). @return list<string> */
    public static function available(Organization $organization): array
    {
        return array_values(array_filter(array_keys(self::CHOICES), fn ($key) => self::CHOICES[$key]['module'] === null || $organization->hasModule(self::CHOICES[$key]['module'])));
    }

    /** Choix affichés au client. @return list<string> */
    public static function enabledChoices(Organization $organization): array
    {
        $saved = $organization->online_page['choices'] ?? null;
        $wanted = is_array($saved) ? $saved : (self::SECTOR_DEFAULTS[$organization->sector] ?? ['rdv', 'prices', 'callback']);

        return array_values(array_intersect(self::available($organization), $wanted));
    }

    /**
     * La page d'accueil « Que souhaitez-vous ? » remplace la prise de RDV
     * directe : d'office pour la location, sinon quand l'entreprise l'active.
     */
    public static function isEnabled(Organization $organization): bool
    {
        return (bool) ($organization->online_page['enabled'] ?? in_array($organization->sector, ['location', 'vehicules'], true));
    }
}
