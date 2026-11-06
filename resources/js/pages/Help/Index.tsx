import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';

/*
 * Centre d'aide NENTIX : un guide pas à pas par module, ouvert par
 * « Besoin d'aide ? ». Contenu statique, à enrichir au fil de l'eau.
 */

interface Step {
    title: string;
    text: string;
    href?: string;
}
interface Guide {
    key: string;
    name: string;
    intro: string;
    steps: Step[];
    tips?: string[];
}

const CASES: Record<string, Guide> = {
    beaute: {
        key: 'cas',
        name: 'Cas pratique : beauté',
        intro: 'Exemple Institut Élégance : comment NENTIX aide un institut à remplir son agenda, fidéliser et faire revenir ses clientes.',
        steps: [
            { title: 'Semaine 1 : tout centraliser', text: 'Importez le fichier clientes (nom, téléphone, date de naissance), créez le catalogue (soins, prix, durées) et les intervenantes. Chaque RDV encaissé alimente l’historique de la cliente.', href: '/imports' },
            { title: 'Remplir l’agenda sans effort', text: 'Partagez le lien de réservation en ligne dans votre statut et votre profil WhatsApp : les clientes réservent seules, même la nuit.', href: '/settings/booking' },
            { title: 'Réduire les absences', text: 'Chaque matin, « À faire » liste les RDV du jour à confirmer : un clic ouvre le message WhatsApp déjà rédigé.', href: '/actions' },
            { title: 'Faire revenir les clientes', text: 'Une cliente qui venait chaque mois et n’est pas revenue depuis 45 jours apparaît « à relancer ». Envoyez-lui une offre (ex. -15 % sur son soin habituel).', href: '/actions' },
            { title: 'Récompenser les fidèles', text: 'Programme de fidélité : 1 point par tranche dépensée, un soin offert au palier. Les clientes proches d’un palier sont la cible idéale d’une relance.', href: '/settings/loyalty' },
            { title: 'Vendre plus à chaque visite', text: 'Fiche cliente : soins déjà faits, produits achetés. Proposez le soin complémentaire (ex. manucure après pédicure) ou le produit de soin à domicile.', href: '/customers' },
            { title: 'Animer les temps forts', text: 'Campagnes ciblées : anniversaires, fête des mères, Saint-Valentin, nouvelles prestations, envoyées aux seules clientes qui ont accepté.', href: '/campaigns' },
            { title: 'Piloter chaque semaine', text: 'Le rapport du soir et le tableau de bord donnent CA, RDV honorés, absences, nouvelles clientes et panier moyen. Comparez avec la semaine précédente.', href: '/dashboard' },
        ],
        tips: [
            'Objectifs réalistes à 3 mois : moins d’absences, plus de clientes qui reviennent, panier moyen en hausse.',
            'Demandez l’accord WhatsApp dès la première visite (affiche QR code en caisse) : sans accord, pas de campagne.',
            'Une routine de 10 minutes par jour suffit : « À faire » le matin, rapport du soir le soir.',
        ],
    },
    sante: {
        key: 'cas',
        name: 'Cas pratique : santé',
        intro: 'Exemple d’un cabinet (kiné, dentiste, clinique) : moins de rendez-vous manqués, des patients suivis jusqu’au bout de leur traitement.',
        steps: [
            { title: 'Centraliser les patients', text: 'Importez le fichier patients et créez vos actes (consultation, séance, contrôle) avec durée et prix.', href: '/imports' },
            { title: 'Prise de rendez-vous en ligne', text: 'Partagez le lien de réservation : les patients choisissent un créneau libre, sans appel.', href: '/settings/booking' },
            { title: 'Réduire les absences', text: 'Chaque matin, « À faire » liste les RDV à confirmer par WhatsApp en un clic.', href: '/actions' },
            { title: 'Suivre les traitements', text: 'Un patient qui n’a pas fait sa séance suivante apparaît à relancer : rappelez-le avant qu’il abandonne.', href: '/actions' },
            { title: 'Gérer les proches', text: 'Avec le module bénéficiaires, un parent gère les RDV de ses enfants sur une seule fiche.', href: '/settings/modules' },
            { title: 'Piloter', text: 'Rapport du soir : RDV honorés, absences, CA, nouveaux patients.', href: '/dashboard' },
        ],
        tips: [
            'Les messages restent informatifs (rappels, suivi) : pas de promotion sur les actes médicaux.',
            'Objectif à 3 mois : moins d’absences et plus de traitements menés à terme.',
        ],
    },
    sport: {
        key: 'cas',
        name: 'Cas pratique : sport et bien-être',
        intro: 'Exemple d’un club ou d’une salle : abonnements payés à temps, cours remplis, adhérents qui restent.',
        steps: [
            { title: 'Créer formules et cours', text: 'Formules (mensuel, trimestriel, carte de séances), cours de groupe avec horaires et capacité.', href: '/plans' },
            { title: 'Inscrire les adhérents', text: 'Importez les membres et rattachez chacun à sa formule ; les enfants via leurs parents.', href: '/imports' },
            { title: 'Encaisser sans oubli', text: 'Échéances et tranches : les retards de paiement sortent dans « À faire » avec le message de relance prêt.', href: '/installments' },
            { title: 'Faire l’appel', text: 'Appel sur mobile à chaque cours : absences repérées, séances décomptées.', href: '/classes' },
            { title: 'Garder les adhérents', text: 'Un adhérent qui ne vient plus depuis 3 semaines est signalé avant la fin de son abonnement.', href: '/actions' },
            { title: 'Piloter', text: 'Rapport du soir : présences, impayés, renouvellements à venir.', href: '/dashboard' },
        ],
        tips: [
            'Relancez les renouvellements 7 jours avant l’échéance.',
            'Annulation pour pluie : prévenez tout le groupe en un clic et proposez un rattrapage.',
        ],
    },
    technique: {
        key: 'cas',
        name: 'Cas pratique : services techniques',
        intro: 'Exemple d’un garage ou d’un dépanneur : clients rappelés au bon moment pour l’entretien.',
        steps: [
            { title: 'Centraliser clients et interventions', text: 'Importez les clients, créez vos prestations (vidange, révision, diagnostic) avec prix.', href: '/imports' },
            { title: 'Planifier', text: 'Agenda des interventions par technicien, réservation en ligne pour les entretiens simples.', href: '/appointments' },
            { title: 'Rappeler l’entretien', text: 'Un client dont la dernière vidange date de 6 mois apparaît à relancer : message prêt, un clic.', href: '/actions' },
            { title: 'Fidéliser', text: 'Programme de fidélité : un lavage ou un contrôle offert au palier.', href: '/settings/loyalty' },
            { title: 'Vendre plus', text: 'La fiche client montre l’historique : proposez pneus, freins ou batterie au bon moment.', href: '/customers' },
            { title: 'Piloter', text: 'Tableau de bord : CA, interventions, clients revenus.', href: '/dashboard' },
        ],
        tips: [
            'Notez la date de chaque intervention : c’est elle qui déclenche le rappel.',
            'Objectif à 3 mois : plus de clients qui reviennent pour l’entretien.',
        ],
    },
    pro: {
        key: 'cas',
        name: 'Cas pratique : services professionnels',
        intro: 'Exemple d’un cabinet (comptable, conseil, formation) : prospects suivis, paiements à l’heure, clients qui renouvellent.',
        steps: [
            { title: 'Centraliser clients et missions', text: 'Importez les clients et créez vos prestations (mission, forfait, formation).', href: '/imports' },
            { title: 'Suivre les prospects', text: 'Module prospects : chaque contact a une étape, un commercial et une prochaine action.', href: '/prospects' },
            { title: 'Encaisser', text: 'Tranches et abonnements : les échéances en retard sortent dans « À faire ».', href: '/installments' },
            { title: 'Relancer au bon moment', text: 'Renouvellements annuels, déclarations, fins de contrat : listes du jour avec messages prêts.', href: '/actions' },
            { title: 'Informer vos clients', text: 'Campagnes ciblées : nouvelles obligations, offres, événements.', href: '/campaigns' },
            { title: 'Piloter', text: 'Rapport commerciaux et tableau de bord : CA, taux de transformation, retards.', href: '/dashboard' },
        ],
        tips: [
            'Une prochaine action datée pour chaque prospect, sinon il est oublié.',
            'Objectif à 3 mois : moins d’impayés et plus de renouvellements.',
        ],
    },
    commerce: {
        key: 'cas',
        name: 'Cas pratique : commerce',
        intro: 'Exemple d’une enseigne (mode, restauration, distribution) : connaître ses clients à partir des ventes de caisse.',
        steps: [
            { title: 'Importer l’historique des ventes', text: 'Imports › Ventes : déposez l’export de caisse, associez les colonnes, vérifiez puis validez.', href: '/sales-imports' },
            { title: 'Classer les familles', text: 'Analyses › Familles › À classer : rangez les articles inconnus.', href: '/categories' },
            { title: 'Lire les segments', text: 'Pilotage › Clients et RFM : meilleurs clients, à risque, perdus.', href: '/pilotage' },
            { title: 'Fidéliser', text: 'Programme de fidélité par enseigne, paliers et récompenses.', href: '/settings/loyalty' },
            { title: 'Relancer', text: 'Groupes de clients à risque + campagne WhatsApp avec une offre datée.', href: '/groups' },
            { title: 'Rendre compte', text: 'Rapports PDF mensuels direction et magasin.', href: '/rapports' },
        ],
        tips: [
            'Une vente sans téléphone reste anonyme : demandez le numéro en caisse.',
            'Objectif à 3 mois : plus de CA identifié et plus de clients actifs.',
        ],
    },
    autre: {
        key: 'cas',
        name: 'Cas pratique : votre activité',
        intro: 'Comment NENTIX aide une entreprise de services à mieux connaître ses clients et à les faire revenir.',
        steps: [
            { title: 'Centraliser', text: 'Importez vos clients et créez vos prestations avec leur prix.', href: '/imports' },
            { title: 'Enregistrer chaque vente', text: 'Chaque vente ou RDV encaissé enrichit l’historique du client.', href: '/purchases' },
            { title: 'Relancer chaque jour', text: '« À faire » liste les clients à relancer, avec le message WhatsApp prêt.', href: '/actions' },
            { title: 'Fidéliser', text: 'Points, paliers et récompenses pour vos meilleurs clients.', href: '/settings/loyalty' },
            { title: 'Communiquer', text: 'Campagnes ciblées aux clients qui ont accepté.', href: '/campaigns' },
            { title: 'Piloter', text: 'Tableau de bord et rapport du soir.', href: '/dashboard' },
        ],
        tips: [
            '10 minutes par jour suffisent : « À faire » le matin, rapport le soir.',
        ],
    },
};

const GUIDES: Guide[] = [
    {
        key: 'demarrer',
        name: 'Démarrer',
        intro: 'Les réglages à faire une seule fois pour que tout le reste fonctionne.',
        steps: [
            {
                title: 'Choisir vos modules',
                text: 'Paramètres › Modules : cochez seulement ce que vous utilisez (agenda, fidélité, campagnes, analyses…). Le menu s’adapte.',
                href: '/settings/modules',
            },
            {
                title: 'Renseigner l’entreprise et les établissements',
                text: 'Paramètres › Entreprise, puis Établissement (ou Marques & boutiques si vous avez plusieurs points de vente).',
                href: '/organisation',
            },
            {
                title: 'Inviter votre équipe',
                text: 'Paramètres › Utilisateurs & rôles : chaque personne a son accès et ne voit que ce que son rôle permet.',
                href: '/users',
            },
            {
                title: 'Importer vos clients',
                text: 'Clients › Importer : téléchargez le modèle Excel, remplissez-le, déposez-le. Les doublons sont détectés par numéro de téléphone.',
                href: '/imports',
            },
        ],
    },
    {
        key: 'whatsapp',
        name: 'WhatsApp Business API',
        intro: 'Envoyer des campagnes depuis votre propre numéro avec l’API officielle de Meta. Comptez 1 à 3 jours, surtout pour la vérification de l’entreprise.',
        steps: [
            { title: 'Option la plus rapide : WhatsApp géré par NENTIX', text: 'Écrivez au support : nous connectons votre numéro à notre compte Meta, vous n’avez rien de technique à faire. Sinon, suivez les étapes ci-dessous.' },
            { title: 'Préparer', text: 'Un numéro dédié qui reçoit SMS ou appels et n’est pas utilisé dans l’application WhatsApp (sinon supprimez-le de l’app), un compte Facebook, et les documents de l’entreprise (RCCM, NIF) pour la vérification Meta.' },
            { title: 'Créer le portefeuille business', text: 'Sur business.facebook.com, créez le portefeuille de l’entreprise, puis lancez la vérification dans Centre de sécurité.' },
            { title: 'Créer l’application Meta', text: 'Sur developers.facebook.com : Créer une app › type Business › ajoutez le produit WhatsApp et rattachez-le à votre portefeuille.' },
            { title: 'Ajouter le numéro', text: 'WhatsApp › Configuration de l’API : ajoutez votre numéro, validez le code reçu, choisissez le nom affiché. Notez l’ID du numéro (Phone number ID).' },
            { title: 'Créer un token permanent', text: 'Paramètres business › Utilisateurs système : créez un utilisateur admin, donnez-lui l’app et le compte WhatsApp, puis « Générer un token » avec whatsapp_business_messaging et whatsapp_business_management.' },
            { title: 'Coller dans NENTIX', text: 'Paramètres › WhatsApp : collez l’ID du numéro, le token et l’App Secret (Paramètres › Général de l’app Meta). NENTIX affiche l’URL de webhook et le jeton de vérification.', href: '/settings/whatsapp' },
            { title: 'Brancher le webhook', text: 'Dans Meta, WhatsApp › Configuration › Webhook : collez l’URL et le jeton, validez, puis abonnez-vous au champ « messages ». Les statuts (remis, lu) et les réponses « Stop » remontent alors dans NENTIX.' },
            { title: 'Paiement, modèles, test', text: 'Ajoutez un moyen de paiement dans Meta, créez vos premiers modèles (Campagnes › Modèles de messages, validation Meta sous 24 h) et envoyez une campagne test à vous-même.', href: '/templates' },
        ],
        tips: ['Les campagnes marketing sont facturées par Meta par message : NENTIX affiche le coût estimé avant l’envoi.', 'Les relances une par une par lien WhatsApp restent gratuites et ne demandent aucune API.'],
    },
    {
        key: 'campagnes',
        name: 'Campagnes',
        intro: 'Envoyer un message WhatsApp à un groupe de clients, au bon moment, sans le faire un par un.',
        steps: [
            {
                title: 'Connecter WhatsApp',
                text: 'Paramètres › WhatsApp : suivez les étapes de connexion du numéro. Sans connexion, les relances restent possibles une par une depuis « À faire ».',
                href: '/settings/whatsapp',
            },
            {
                title: 'Préparer un groupe de clients',
                text: 'Clients › Groupes : filtrez (segment RFM, famille achetée, dernière visite, enseigne…) et enregistrez le groupe.',
                href: '/groups',
            },
            {
                title: 'Choisir un modèle de message',
                text: 'Campagnes › Modèles de messages : les messages marketing doivent être approuvés par Meta. Prévoyez 24 h pour un nouveau modèle.',
                href: '/templates',
            },
            {
                title: 'Créer la campagne',
                text: 'Campagnes › Nouvelle campagne : groupe + modèle + variables (prénom, offre). Seuls les clients ayant accepté le marketing (opt-in) reçoivent le message.',
                href: '/campaigns',
            },
            {
                title: 'Suivre les résultats',
                text: 'La liste des campagnes affiche envoyés, échecs et destinataires. Campagnes › Messages envoyés détaille chaque message et les réponses.',
                href: '/chat',
            },
        ],
        tips: ['Testez d’abord sur un petit groupe (vous-même et 2 collègues).', 'Un message court, personnalisé et avec une offre datée fonctionne mieux.'],
    },
    {
        key: 'fidelite',
        name: 'Fidélité',
        intro: 'Récompenser les meilleurs clients avec des points et des paliers, par enseigne si besoin.',
        steps: [
            {
                title: 'Activer le module',
                text: 'Paramètres › Modules : cochez « Programme de fidélité ».',
                href: '/settings/modules',
            },
            {
                title: 'Créer un programme',
                text: 'Paramètres › Fidélité : donnez une date de début et de fin, choisissez les enseignes, puis « mêmes règles pour toutes » ou « règles par enseigne ».',
                href: '/settings/loyalty',
            },
            {
                title: 'Régler les points et le seuil',
                text: 'Seuil d’entrée (montant d’achats sur la période du programme), points offerts à l’entrée, 1 point par tranche de X FCFA, durée de validité.',
            },
            {
                title: 'Définir les paliers et récompenses',
                text: 'Pour chaque palier : % de remise, montant fixe, bon d’achat, cadeau ou avantage libre.',
            },
            {
                title: 'Alimenter avec les ventes',
                text: 'Les ventes saisies ou importées recalculent les points automatiquement. Un ticket sans téléphone reste une vente anonyme, sans points.',
            },
            {
                title: 'Suivre et récompenser',
                text: 'Clients › Fidélité : clients proches d’un palier, récompenses atteintes. Cliquez « Récompense utilisée » : les points repartent à zéro.',
                href: '/fidelite',
            },
        ],
        tips: ['Les compteurs sont séparés par enseigne.', 'Relancez par campagne les clients « Proche » d’un palier : c’est le levier le plus rentable.'],
    },
    {
        key: 'analyses',
        name: 'Analyse des données',
        intro: 'Importer les ventes, vérifier la qualité, puis lire les bons indicateurs et graphiques.',
        steps: [
            {
                title: '1. Extraire les ventes de votre caisse',
                text: 'Depuis votre logiciel de caisse ou ERP, exportez les ventes en Excel ou CSV. Obligatoire : date, point de vente, montant, famille (ou catégorie). Recommandé : téléphone du client, n° de ticket, article.',
            },
            {
                title: '2. Importer le fichier',
                text: 'Imports › Ventes › Nouvel import. Téléchargez le modèle si besoin : il contient les bonnes colonnes et une aide.',
                href: '/sales-imports',
            },
            {
                title: '3. Associer les colonnes',
                text: 'Indiquez quelle colonne de votre fichier correspond à chaque information. Le choix est mémorisé pour les prochains imports.',
            },
            {
                title: '4. Vérifier avant de valider',
                text: 'L’écran « Vérification » montre lignes valides, rejetées, doublons, ventes anonymes et lignes à classer. Corrigez le fichier si le taux de rejet est élevé, puis validez.',
            },
            {
                title: '5. Classer les familles et contrôler les clients',
                text: 'Analyses › Familles › À classer pour les articles inconnus ; Clients › À vérifier pour les fiches douteuses (même numéro, noms différents).',
                href: '/categories',
            },
            {
                title: '6. Lire le Pilotage',
                text: 'Analyses › Pilotage, 6 pages : Direction, Clients et RFM, Fidélité, Enseignes et magasins, Familles, Qualité des données. Les filtres en haut s’appliquent à toute la page.',
                href: '/pilotage',
            },
            {
                title: '7. Partager un rapport',
                text: 'Analyses › Rapports : rapport mensuel direction, fidélité, magasin ou clients à relancer, en PDF A4, avec envoi mensuel possible.',
                href: '/rapports',
            },
        ],
        tips: [
            'Indicateurs à suivre chaque mois : CA net, nombre de clients acheteurs, panier moyen, fréquence d’achat, taux de réachat, part du CA identifiée (ventes avec client).',
            'Graphiques utiles : CA net par mois (courbe), CA par enseigne/magasin (barres), répartition des segments RFM (barres empilées), top familles (barres), évolution des clients actifs vs perdus.',
            'Segments RFM à relancer en priorité : « À ne pas perdre », « À risque », « En sommeil ».',
            'Importez au même rythme (chaque semaine ou chaque mois) pour des comparaisons justes.',
        ],
    },
];

function HelpIndex() {
    const { support, auth } = usePage<{ support?: string | null; auth?: { organization?: { sector?: string | null } | null } }>().props;
    // Cas pratique du secteur choisi à l'inscription, cas général sinon.
    const sectorCase = CASES[auth?.organization?.sector ?? ''] ?? CASES.autre;
    const guides = [GUIDES[0], sectorCase, ...GUIDES.slice(1)];
    const initial = typeof window !== 'undefined' ? window.location.hash.slice(1) : '';
    const [current, setCurrent] = useState(guides.some((g) => g.key === initial) ? initial : 'demarrer');
    const guide = guides.find((g) => g.key === current)!;

    return (
        <div className="nx-help">
            <Head title="Aide" />
            <section className="nx-banner" aria-label="Centre d’aide">
                <div className="nx-banner-in">
                    <span className="nx-chip nx-chip-glow">Centre d’aide</span>
                    <h1>Comment utiliser NENTIX ?</h1>
                    <p>Choisissez un module : les étapes à suivre, dans l’ordre, avec un lien direct vers chaque écran.</p>
                </div>
                {support && (
                    <a href={support} target="_blank" rel="noopener noreferrer" className="nx-banner-cta">
                        Écrire au support
                    </a>
                )}
            </section>

            <div className="nx-help-tabs" role="tablist">
                {guides.map((g) => (
                    <button
                        key={g.key}
                        role="tab"
                        aria-selected={g.key === current}
                        onClick={() => {
                            setCurrent(g.key);
                            history.replaceState(null, '', `#${g.key}`);
                        }}
                    >
                        {g.name}
                    </button>
                ))}
            </div>

            <article className="mdash-panel">
                <h2 className="nx-help-title">{guide.name}</h2>
                <p className="nx-help-intro">{guide.intro}</p>
                <ol className="nx-help-steps">
                    {guide.steps.map((s, i) => (
                        <li key={s.title}>
                            <span className="nx-step-icon">{i + 1}</span>
                            <div>
                                <b>{s.title.replace(/^\d+\.\s*/, '')}</b>
                                <p>{s.text}</p>
                                {s.href && (
                                    <Link href={s.href} className="nx-step-go">
                                        Ouvrir l’écran →
                                    </Link>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
                {guide.tips && (
                    <div className="nx-help-tips">
                        <b>Bon à savoir</b>
                        <ul>
                            {guide.tips.map((t) => (
                                <li key={t}>{t}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </article>
        </div>
    );
}

HelpIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default HelpIndex;
