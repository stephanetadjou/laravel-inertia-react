<?php

namespace App\Support;

/**
 * Textes par défaut des réponses automatiques WhatsApp, par secteur.
 * L'entreprise les modifie dans Paramètres › Réponses automatiques
 * (organizations.auto_replies) ; ce qu'elle n'a pas réglé vient d'ici.
 *
 * Variables : {prenom}, {boutique}, {horaires}, {lien_rdv}.
 */
class AutoReplyDefaults
{
    /** Options du menu : clé => libellé du bouton (20 caractères au plus chez Meta). */
    public const OPTIONS = [
        'prix' => 'Nos prix',
        'horaires' => 'Nos horaires',
        'rdv' => 'Prendre RDV',
        'rappel' => 'Être rappelé',
        'humain' => 'Parler à quelqu’un',
    ];

    public const BASE = [
        'enabled' => false,
        'welcome' => 'Bonjour {prenom} 👋 Bienvenue chez {boutique} ! Comment pouvons-nous vous aider ?',
        'away' => 'Bonjour {prenom}, merci pour votre message. {boutique} est fermé pour le moment ({horaires}). Nous vous répondons dès notre retour.',
        'menu_intro' => 'Choisissez une option ci-dessous :',
        // Options affichées, dans l'ordre (3 = boutons, 4 ou plus = liste).
        'options' => ['prix', 'horaires', 'rappel', 'humain'],
        'labels' => self::OPTIONS,
        'prices_intro' => 'Voici nos principaux tarifs :',
        'prices_empty' => 'Nos tarifs dépendent de votre demande : un conseiller vous répond très vite.',
        'hours_reply' => 'Nos horaires : {horaires}.',
        'booking_reply' => 'Réservez votre créneau en ligne ici : {lien_rdv}',
        'callback_reply' => 'C’est noté {prenom} : nous vous rappelons très vite sur ce numéro.',
        'human_reply' => 'Un membre de l’équipe {boutique} prend le relais et vous répond dans les plus brefs délais.',
        // Rappel de la veille (module rappels_rdv).
        'reminder' => 'Bonjour {prenom}, petit rappel : votre rendez-vous chez {boutique} est demain {date_rdv} à {heure_rdv}. Merci de confirmer :',
        'reminder_confirmed' => 'Merci {prenom}, votre rendez-vous est confirmé. À demain !',
        'reminder_reschedule' => 'C’est noté {prenom}, nous vous recontactons pour trouver un autre créneau.',
        // Modèle Meta (avec boutons de réponse rapide) utilisé quand la fenêtre de 24 h est fermée.
        'reminder_template' => '',
        'reminder_template_language' => 'fr',
    ];

    /** Ce qui change selon le secteur. */
    public const SECTORS = [
        'beaute' => [
            'welcome' => 'Bonjour {prenom} 💇‍♀️ Bienvenue chez {boutique} ! Que souhaitez-vous ?',
            'options' => ['prix', 'rdv', 'horaires', 'humain'],
        ],
        'sport' => [
            'welcome' => 'Bonjour {prenom} 💪 Bienvenue chez {boutique} ! Une séance d’essai vous tente ?',
            'options' => ['prix', 'rdv', 'horaires', 'humain'],
            'labels' => ['rdv' => 'Séance d’essai'],
            'booking_reply' => 'Réservez votre séance d’essai ici : {lien_rdv}',
        ],
        'pro' => [
            'welcome' => 'Bonjour {prenom}, merci de contacter {boutique}. Comment pouvons-nous vous aider ?',
            'options' => ['rappel', 'horaires', 'humain'],
            'callback_reply' => 'Merci {prenom}, un conseiller vous rappelle dans la journée pour parler de votre projet.',
        ],
        'commerce' => [
            'welcome' => 'Bonjour {prenom} 🛍️ Bienvenue chez {boutique} ! Que cherchez-vous aujourd’hui ?',
            'options' => ['prix', 'horaires', 'humain'],
            'prices_empty' => 'Dites-nous l’article qui vous intéresse : une conseillère vous répond avec le prix et la disponibilité.',
        ],
    ];

    /** @param  array<string, mixed>  $saved */
    public static function merge(?string $sector, array $saved): array
    {
        $sectorDefaults = self::SECTORS[$sector] ?? [];
        $settings = [...self::BASE, ...$sectorDefaults];
        $settings['labels'] = [...self::OPTIONS, ...($sectorDefaults['labels'] ?? [])];

        foreach (array_intersect_key($saved, self::BASE) as $key => $value) {
            if ($key === 'labels' && is_array($value)) {
                $settings['labels'] = [...$settings['labels'], ...array_intersect_key(array_filter($value, 'filled'), self::OPTIONS)];
            } elseif ($key === 'options' && is_array($value)) {
                $settings['options'] = array_values(array_intersect($value, array_keys(self::OPTIONS)));
            } elseif ($key === 'enabled') {
                $settings['enabled'] = (bool) $value;
            } elseif (is_string($value) || $value === null) {
                $settings[$key] = (string) $value;
            }
        }

        return $settings;
    }
}
