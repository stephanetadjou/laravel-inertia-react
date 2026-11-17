<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Role;

/**
 * Préréglages par secteur : à l'inscription, ClientlyX active les modules du
 * secteur, crée les étapes de suivi, les réponses automatiques et les
 * messages de relance. Rejouable sans doublon : une seconde application ne
 * touche plus aux modules (l'entreprise les a peut-être changés dans
 * Paramètres › Modules) et ne complète que ce qui manque.
 */
class SectorPresets
{
    /**
     * modules : actifs d'office ; optional : proposés, désactivés ;
     * stages : étapes de suivi (renommables dans Paramètres › Prospects) ;
     * messages : messages de relance de « À faire » propres au secteur.
     */
    public const PRESETS = [
        'beaute' => [
            'modules' => ['rdv', 'catalogue', 'prospects', 'reponses_auto', 'rappels_rdv', 'relance_cycle'],
            'optional' => ['fidelite', 'produits'],
            'stages' => [
                ['key' => 'nouveau', 'label' => 'Nouveau contact', 'kind' => 'a_contacter', 'delay_days' => 1],
                ['key' => 'rdv_reserve', 'label' => 'RDV réservé', 'kind' => 'en_discussion', 'delay_days' => 0],
                ['key' => 'rdv_confirme', 'label' => 'RDV confirmé', 'kind' => 'en_discussion', 'delay_days' => 0],
                ['key' => 'venue', 'label' => 'Venue', 'kind' => 'gagne', 'delay_days' => 0],
                ['key' => 'a_relancer', 'label' => 'À relancer', 'kind' => 'en_essai', 'delay_days' => 2],
            ],
            'messages' => [
                'service_cycle' => 'Bonjour {prenom}, cela fait {jours} jours depuis votre {prestation} chez {boutique}. C’est le bon moment pour revenir : on vous réserve un créneau ?',
                'prospect_followup' => 'Bonjour {prenom}, ici {boutique}. Souhaitez-vous que nous vous réservions un créneau cette semaine ?',
            ],
        ],
        'sante' => [
            'modules' => ['rdv', 'catalogue', 'reponses_auto', 'rappels_rdv', 'relance_cycle'],
            'optional' => ['beneficiaires', 'tranches', 'lieux'],
            'stages' => [],
            'messages' => [
                'service_cycle' => 'Bonjour {prenom}, ici {boutique}. Votre dernier {prestation} date de {jours} jours : c’est le moment de votre contrôle. On vous réserve un créneau ?',
                'no_show' => 'Bonjour {prenom}, nous ne vous avons pas vu à votre rendez-vous du {date_rdv} chez {boutique}. Souhaitez-vous en fixer un nouveau ?',
            ],
        ],
        'optique' => [
            'modules' => ['rdv', 'catalogue', 'produits', 'stock', 'factures', 'reponses_auto', 'rappels_rdv', 'relance_cycle'],
            'optional' => ['tranches', 'caisse', 'depenses', 'beneficiaires'],
            'stages' => [],
            'messages' => [
                'service_cycle' => 'Bonjour {prenom}, ici {boutique}. Votre dernier examen de vue date de {jours} jours : c’est le moment de vérifier votre vue. On vous réserve un créneau ?',
                'quote_followup' => 'Bonjour {prenom}, ici {boutique}. Avez-vous pu regarder le devis de vos lunettes ? Nous pouvons lancer la commande dès votre accord.',
            ],
        ],
        'technique' => [
            'modules' => ['rdv', 'catalogue', 'reponses_auto', 'rappels_rdv', 'relance_cycle', 'relance_devis', 'prospects'],
            'optional' => ['tranches', 'lieux'],
            'stages' => [
                ['key' => 'demande', 'label' => 'Demande de devis', 'kind' => 'a_contacter', 'delay_days' => 1],
                ['key' => 'devis_envoye', 'label' => 'Devis envoyé', 'kind' => 'en_discussion', 'delay_days' => 3],
                ['key' => 'accepte', 'label' => 'Devis accepté', 'kind' => 'gagne', 'delay_days' => 0],
                ['key' => 'perdu', 'label' => 'Pas donné suite', 'kind' => 'perdu', 'delay_days' => 0],
            ],
            'messages' => [
                'service_cycle' => 'Bonjour {prenom}, ici {boutique}. Votre dernière {prestation} date de {jours} jours : pensez au prochain entretien. On vous réserve un passage à l’atelier ?',
                'quote_followup' => 'Bonjour {prenom}, ici {boutique}. Avez-vous pu regarder notre devis pour votre véhicule ? Nous pouvons le prendre dès cette semaine.',
            ],
        ],
        'sport' => [
            'modules' => ['rdv', 'abonnements', 'essai', 'seances_groupe', 'tranches', 'catalogue', 'prospects', 'reponses_auto'],
            'optional' => ['fidelite', 'beneficiaires', 'lieux'],
            'stages' => [
                ['key' => 'prospect', 'label' => 'Prospect', 'kind' => 'a_contacter', 'delay_days' => 1],
                ['key' => 'essai_planifie', 'label' => 'Essai planifié', 'kind' => 'en_discussion', 'delay_days' => 0],
                ['key' => 'essai_fait', 'label' => 'Essai fait', 'kind' => 'en_essai', 'delay_days' => 1],
                ['key' => 'abonne', 'label' => 'Abonné', 'kind' => 'gagne', 'delay_days' => 0],
                ['key' => 'a_renouveler', 'label' => 'À renouveler', 'kind' => 'en_discussion', 'delay_days' => 3],
            ],
            'messages' => [
                'prospect_followup' => 'Bonjour {prenom}, merci d’être venu(e) à la séance d’essai chez {boutique} ! Prêt(e) à continuer ? Nous pouvons préparer votre abonnement dès aujourd’hui.',
            ],
        ],
        'formation' => [
            'modules' => ['formation', 'rdv', 'seances_groupe', 'abonnements', 'tranches', 'catalogue', 'ressources', 'prospects', 'reponses_auto', 'rappels_rdv'],
            'optional' => ['beneficiaires', 'lieux', 'fidelite'],
            'stages' => [
                ['key' => 'demande', 'label' => 'Demande d’information', 'kind' => 'a_contacter', 'delay_days' => 1],
                ['key' => 'devis', 'label' => 'Tarifs envoyés', 'kind' => 'en_discussion', 'delay_days' => 3],
                ['key' => 'visite', 'label' => 'Venu au centre', 'kind' => 'en_discussion', 'delay_days' => 2],
                ['key' => 'inscrit', 'label' => 'Inscrit', 'kind' => 'gagne', 'delay_days' => 0],
                ['key' => 'perdu', 'label' => 'Pas donné suite', 'kind' => 'perdu', 'delay_days' => 0],
            ],
            'messages' => [
                'prospect_followup' => 'Bonjour {prenom}, ici {boutique}. Avez-vous pu regarder nos formules ? Les inscriptions sont ouvertes : nous pouvons fixer votre première leçon dès cette semaine.',
            ],
        ],
        'vehicules' => [
            'modules' => ['vehicules', 'ressources', 'tranches', 'prospects', 'reponses_auto'],
            'optional' => ['lieux', 'agents_ia'],
            // Acheteurs d'un véhicule : de la demande à la vente.
            'stages' => [
                ['key' => 'interesse', 'label' => 'Intéressé', 'kind' => 'a_contacter', 'delay_days' => 2],
                ['key' => 'essai', 'label' => 'Essai fait', 'kind' => 'en_discussion', 'delay_days' => 3],
                ['key' => 'offre', 'label' => 'Offre faite', 'kind' => 'en_discussion', 'delay_days' => 3],
                ['key' => 'vendu', 'label' => 'Vendu', 'kind' => 'gagne', 'delay_days' => 0],
                ['key' => 'perdu', 'label' => 'Pas donné suite', 'kind' => 'perdu', 'delay_days' => 0],
            ],
            'messages' => [
                'prospect_followup' => 'Bonjour {prenom}, ici {boutique}. Le véhicule qui vous intéressait est toujours disponible : souhaitez-vous l’essayer cette semaine ?',
            ],
        ],
        'location' => [
            'modules' => ['location', 'vehicules', 'factures', 'tranches', 'caisse', 'reponses_auto', 'tunnels'],
            'optional' => ['lieux', 'depenses', 'agents_ia'],
            'stages' => [],
            'messages' => [],
        ],
        'pro' => [
            'modules' => ['prospects', 'relance_devis', 'reponses_auto'],
            'optional' => ['rdv', 'catalogue', 'tranches'],
            'stages' => [
                ['key' => 'a_contacter', 'label' => 'À contacter', 'kind' => 'a_contacter', 'delay_days' => 2],
                ['key' => 'discussion', 'label' => 'En discussion', 'kind' => 'en_discussion', 'delay_days' => 5],
                ['key' => 'devis_envoye', 'label' => 'Devis envoyé', 'kind' => 'en_discussion', 'delay_days' => 3],
                ['key' => 'gagne', 'label' => 'Gagné', 'kind' => 'gagne', 'delay_days' => 0],
                ['key' => 'perdu', 'label' => 'Perdu', 'kind' => 'perdu', 'delay_days' => 0],
            ],
            'messages' => [
                'quote_followup' => 'Bonjour {prenom}, ici {boutique}. Avez-vous pu regarder notre devis ? Je reste disponible pour en parler ou l’ajuster à votre besoin.',
            ],
        ],
        'commerce' => [
            'modules' => ['familles', 'fidelite', 'lieux', 'reponses_auto'],
            'optional' => ['catalogue', 'tranches'],
            'stages' => [],
            'messages' => [],
        ],
    ];

    public static function has(?string $sector): bool
    {
        return isset(self::PRESETS[$sector]);
    }

    /** Applique (ou complète) le préréglage du secteur, sans sauvegarder deux fois la même chose. */
    public static function apply(Organization $organization, bool $resetModules = false): void
    {
        $preset = self::PRESETS[$organization->sector] ?? null;

        if ($preset === null) {
            return;
        }

        $first = $organization->sector_preset_applied_at === null;
        $settings = [];

        if ($preset['stages'] !== [] && empty($organization->modules['settings']['prospects']['stages'])) {
            $settings['prospects'] = ['stages' => $preset['stages']];
        }

        $enabled = $first || $resetModules ? $preset['modules'] : $organization->enabledModules();
        $organization->setModules($enabled, $settings, reviewed: true);

        // Réponses automatiques : activées d'office, textes du secteur (cf. AutoReplyDefaults).
        if ($organization->auto_replies === null) {
            $organization->auto_replies = ['enabled' => true];
        }

        // Messages de relance : seulement ceux que l'entreprise n'a pas déjà écrits.
        $organization->action_messages = [...$preset['messages'], ...($organization->action_messages ?? [])];

        // Crédits de messages : une nouvelle entreprise démarre à 0 (recharge dans /admin).
        if ($first && $organization->message_credits === null && $organization->wasRecentlyCreated) {
            $organization->message_credits = 0;
        }

        $organization->sector_preset_applied_at ??= now();
        $organization->save();

        if ($organization->hasModule('prospects')) {
            Role::ensureSalesRole($organization);
        }
    }
}
