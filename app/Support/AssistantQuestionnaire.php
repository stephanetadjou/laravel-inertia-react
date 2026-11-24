<?php

namespace App\Support;

use App\Models\KnowledgeEntry;
use App\Models\Organization;

/**
 * Questionnaire guidé de l'Assistant IA : seulement ce que la configuration
 * de ClientlyX ne dit pas déjà (paiements, acompte, annulation, adresse…),
 * avec des questions propres au secteur. Chaque réponse devient une fiche de
 * la base (external_id « q:{clé} », source « questionnaire »), régénérée à
 * chaque modification ; les réponses brutes restent dans
 * organizations.assistant_settings['questionnaire'] pour être reprises.
 */
class AssistantQuestionnaire
{
    /** Secteur → groupe de questions propres. */
    private const GROUPS = [
        'beaute' => 'soins', 'sante' => 'soins', 'optique' => 'soins',
        'sport' => 'sport',
        'formation' => 'formation',
        'pro' => 'services', 'technique' => 'services', 'immobilier' => 'services', 'vehicules' => 'services', 'location' => 'services',
        'commerce' => 'commerce',
    ];

    /**
     * section, titre de la fiche, phrase (« {r} » = réponse), choix rapides, mots-clés cherchés.
     *
     * @var array<string, array{question: string, help?: string, section: string, title: string, template: string, choices?: list<string>, keywords: string}>
     */
    private const COMMON = [
        'adresse' => ['question' => 'Où vous trouve-t-on ?', 'help' => 'Quartier, rue, repère connu, parking…', 'section' => 'presentation', 'title' => 'Adresse et accès', 'template' => 'Nous sommes situés : {r}.', 'keywords' => 'adresse situe ou trouver venir quartier localisation acces plan repere'],
        'paiements' => ['question' => 'Quels moyens de paiement acceptez-vous ?', 'section' => 'regles', 'title' => 'Moyens de paiement', 'template' => 'Moyens de paiement acceptés : {r}.', 'choices' => ['Espèces', 'Airtel Money', 'Moov Money', 'Carte bancaire', 'Virement', 'Chèque'], 'keywords' => 'paiement payer regler airtel moov money carte especes virement'],
        'acompte' => ['question' => 'Demandez-vous un acompte pour réserver ?', 'section' => 'regles', 'title' => 'Acompte', 'template' => 'Acompte : {r}.', 'choices' => ['Pas d’acompte', '30 % à la réservation', '50 % à la réservation', 'Acompte fixe selon la prestation'], 'keywords' => 'acompte avance reserver payer avant arrhes'],
        'annulation' => ['question' => 'Que se passe-t-il en cas d’annulation ou de retard ?', 'section' => 'regles', 'title' => 'Annulation et retard', 'template' => 'Annulation et retard : {r}.', 'choices' => ['Annulation gratuite jusqu’à 24 h avant', 'Annulation gratuite jusqu’à 48 h avant', 'Acompte gardé en cas d’absence', 'Au-delà de 15 min de retard, le rendez-vous peut être décalé'], 'keywords' => 'annuler annulation reporter decaler retard absence'],
        'langues' => ['question' => 'Dans quelles langues pouvez-vous accueillir vos clients ?', 'section' => 'presentation', 'title' => 'Langues parlées', 'template' => 'Langues parlées : {r}.', 'choices' => ['Français', 'Anglais', 'Espagnol', 'Fang', 'Punu', 'Lingala'], 'keywords' => 'langue parlez anglais english francais'],
        'promesses' => ['question' => 'Qu’est-ce que l’assistant ne doit jamais promettre ?', 'help' => 'Il passera alors la main à votre équipe.', 'section' => 'interdit', 'title' => 'Ne jamais promettre', 'template' => '{r}', 'choices' => ['Une remise ou un prix négocié', 'Un résultat garanti', 'Un délai précis sans validation de l’équipe', 'Un avis médical ou juridique'], 'keywords' => ''],
        'humain' => ['question' => 'Dans quels cas l’assistant doit-il passer la main à votre équipe ?', 'section' => 'interdit', 'title' => 'Passer la main à l’équipe', 'template' => '{r}', 'choices' => ['Réclamation ou client mécontent', 'Demande de remise', 'Urgence', 'Question sur une facture ou un paiement'], 'keywords' => ''],
    ];

    private const BY_GROUP = [
        'soins' => [
            'preparation' => ['question' => 'Comment le client doit-il venir préparé ?', 'help' => 'Ex. cheveux lavés, sans maquillage, à jeun, ordonnance…', 'section' => 'faq', 'title' => 'Comment venir préparé', 'template' => 'Pour votre rendez-vous : {r}.', 'keywords' => 'preparer venir avant rendez vous apporter jeun maquillage cheveux ordonnance'],
            'contre_indications' => ['question' => 'Y a-t-il des cas à orienter d’abord vers l’équipe ?', 'help' => 'Ex. grossesse, allergie, traitement en cours…', 'section' => 'interdit', 'title' => 'Cas à orienter vers l’équipe', 'template' => 'Orienter vers l’équipe : {r}', 'keywords' => ''],
            'enfants' => ['question' => 'Recevez-vous les enfants ?', 'section' => 'faq', 'title' => 'Enfants', 'template' => 'Enfants : {r}.', 'choices' => ['Oui, à partir de 3 ans', 'Oui, à partir de 6 ans', 'Oui, accompagnés d’un parent', 'Non'], 'keywords' => 'enfant enfants bebe fille garcon age'],
        ],
        'sport' => [
            'essai' => ['question' => 'Proposez-vous une séance d’essai ?', 'section' => 'faq', 'title' => 'Séance d’essai', 'template' => 'Séance d’essai : {r}.', 'choices' => ['Oui, gratuite', 'Oui, payante', 'Non'], 'keywords' => 'essai essayer gratuit decouverte premiere seance'],
            'certificat' => ['question' => 'Un certificat médical est-il demandé ?', 'section' => 'regles', 'title' => 'Certificat médical', 'template' => 'Certificat médical : {r}.', 'choices' => ['Oui, à l’inscription', 'Seulement pour les compétitions', 'Non'], 'keywords' => 'certificat medical medecin inscription'],
            'engagement' => ['question' => 'Y a-t-il un engagement minimum ?', 'section' => 'regles', 'title' => 'Engagement', 'template' => 'Engagement : {r}.', 'choices' => ['Sans engagement', '3 mois minimum', '6 mois minimum', '12 mois minimum'], 'keywords' => 'engagement duree minimum resilier arreter abonnement'],
        ],
        'formation' => [
            'pieces' => ['question' => 'Quelles pièces faut-il fournir pour s’inscrire ?', 'section' => 'regles', 'title' => 'Pièces à fournir', 'template' => 'Pièces à fournir pour l’inscription : {r}.', 'keywords' => 'pieces documents dossier inscription fournir papier'],
            'sessions' => ['question' => 'Quand commencent les prochaines sessions ?', 'section' => 'faq', 'title' => 'Prochaines sessions', 'template' => 'Prochaines sessions : {r}.', 'keywords' => 'session rentree debut commence date prochaine classe'],
            'financement' => ['question' => 'Comment se fait le paiement de la formation ?', 'section' => 'regles', 'title' => 'Paiement de la formation', 'template' => 'Paiement de la formation : {r}.', 'choices' => ['En une fois', 'En 2 ou 3 tranches', 'Paiement mensuel', 'Prise en charge par l’employeur possible'], 'keywords' => 'paiement tranche financement payer formation mensuel'],
        ],
        'services' => [
            'delai' => ['question' => 'Quel est votre délai habituel ?', 'help' => 'Livraison, intervention ou réponse au devis.', 'section' => 'faq', 'title' => 'Délais', 'template' => 'Délais : {r}.', 'keywords' => 'delai combien de temps quand livraison intervention rapide'],
            'deplacement' => ['question' => 'Vous déplacez-vous chez le client ?', 'section' => 'faq', 'title' => 'Déplacement', 'template' => 'Déplacement : {r}.', 'choices' => ['Oui, gratuitement à Libreville', 'Oui, avec frais de déplacement', 'Non, au bureau seulement', 'Oui, ou en visio'], 'keywords' => 'deplacer deplacement venir chez domicile bureau visio'],
            'garantie' => ['question' => 'Offrez-vous une garantie ou un suivi après la prestation ?', 'section' => 'faq', 'title' => 'Garantie et suivi', 'template' => 'Garantie et suivi : {r}.', 'keywords' => 'garantie suivi apres probleme sav assistance'],
        ],
        'commerce' => [
            'livraison' => ['question' => 'Livrez-vous ?', 'section' => 'faq', 'title' => 'Livraison', 'template' => 'Livraison : {r}.', 'choices' => ['Oui, gratuite à Libreville', 'Oui, payante', 'Retrait en boutique seulement'], 'keywords' => 'livraison livrer livrez domicile retrait'],
            'retours' => ['question' => 'Acceptez-vous les échanges et retours ?', 'section' => 'regles', 'title' => 'Échanges et retours', 'template' => 'Échanges et retours : {r}.', 'choices' => ['Échange sous 7 jours avec ticket', 'Échange sous 14 jours avec ticket', 'Ni échange ni remboursement'], 'keywords' => 'echange retour rembourser remboursement ticket'],
            'disponibilite' => ['question' => 'Comment le client sait-il si un article est disponible ?', 'section' => 'faq', 'title' => 'Disponibilité des articles', 'template' => 'Disponibilité : {r}.', 'keywords' => 'disponible stock article taille couleur reste encore'],
        ],
    ];

    /** @return array<string, array{question: string, help?: string, section: string, title: string, template: string, choices?: list<string>, keywords: string}> */
    public static function questions(Organization $organization): array
    {
        $group = self::GROUPS[$organization->sector] ?? null;

        return [...self::COMMON, ...($group ? self::BY_GROUP[$group] : [])];
    }

    /** @return array<string, array{choices: list<string>, text: string}> */
    public static function answers(Organization $organization): array
    {
        return $organization->assistant_settings['questionnaire'] ?? [];
    }

    /** Questions répondues sur le total. @return array{answered: int, total: int} */
    public static function progress(Organization $organization): array
    {
        $answers = self::answers($organization);
        $questions = self::questions($organization);
        $answered = collect($questions)->keys()->filter(fn ($key) => self::text($answers[$key] ?? null) !== '')->count();

        return ['answered' => $answered, 'total' => count($questions)];
    }

    /**
     * Enregistre une réponse et (re)crée sa fiche ; une réponse vide retire la fiche.
     *
     * @param  list<string>  $choices
     */
    public static function save(Organization $organization, string $key, array $choices, string $text): ?KnowledgeEntry
    {
        $question = self::questions($organization)[$key] ?? null;
        if ($question === null) {
            return null;
        }

        $answer = ['choices' => array_values(array_intersect($choices, $question['choices'] ?? [])), 'text' => mb_substr(trim($text), 0, 2000)];
        $settings = $organization->assistant_settings ?? [];
        $settings['questionnaire'][$key] = $answer;
        $organization->forceFill(['assistant_settings' => $settings])->save();

        $value = self::text($answer);
        if ($value === '') {
            KnowledgeEntry::where('organization_id', $organization->id)->where('external_id', 'q:'.$key)->delete();

            return null;
        }

        return KnowledgeEntry::updateOrCreate(
            ['organization_id' => $organization->id, 'external_id' => 'q:'.$key],
            [
                'section' => $question['section'],
                'title' => $question['title'],
                'content' => str_replace('{r}', $value, $question['template']),
                'keywords' => $question['keywords'] ?: null,
                'source' => 'questionnaire',
                'active' => true,
                'embedding' => null,
                'embedding_model' => null,
            ],
        );
    }

    /** « Espèces, Airtel Money ; en plus : … » */
    public static function text(?array $answer): string
    {
        if ($answer === null) {
            return '';
        }

        $choices = implode(', ', $answer['choices'] ?? []);
        $text = trim((string) ($answer['text'] ?? ''));

        return trim($choices.($choices !== '' && $text !== '' ? '. ' : '').$text);
    }
}
