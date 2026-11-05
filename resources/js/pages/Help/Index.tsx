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
        key: 'institut',
        name: 'Cas pratique : institut de beauté',
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
    const { support } = usePage<{ support?: string | null }>().props;
    const initial = typeof window !== 'undefined' ? window.location.hash.slice(1) : '';
    const [current, setCurrent] = useState(GUIDES.some((g) => g.key === initial) ? initial : 'demarrer');
    const guide = GUIDES.find((g) => g.key === current)!;

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
                {GUIDES.map((g) => (
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
