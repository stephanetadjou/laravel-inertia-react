// Contenu du site vitrine. Prix, familles et fonctions viennent du cahier de
// cadrage final du 28/10/2026 : toute modification se fait ici, une seule fois.

export const APP_URL = process.env.NEXT_PUBLIC_APP_URL ?? 'https://app.clientlyx.com';
export const MARKETPLACE_URL = '/marketplace';
// Service IA de l’assistant du site (services/assistant). Vide : réponses de secours seules.
export const ASSISTANT_URL = process.env.NEXT_PUBLIC_ASSISTANT_URL ?? 'https://ia.clientlyx.com/v1/assistant';

export const links = {
  login: `${APP_URL}/login`,
  signup: `${APP_URL}/register`,
  signupPlan: (plan: string) => `${APP_URL}/register?offre=${plan}`,
};

// Numéro WhatsApp commercial : à remplacer par le vrai numéro.
export const WHATSAPP_DISPLAY = '+241 XX XX XX XX';

export const brand = {
  name: 'ClientlyX',
  promise: 'Connaissez vos clients. Parlez-leur au bon moment. Faites grandir votre activité.',
  category: 'Le pilote WhatsApp des PME',
  signature: 'Connecter · Fidéliser · Développer',
  description:
    'ClientlyX transforme votre WhatsApp en outil de vente : chaque message devient une fiche client avec la source de la pub, chaque prospect est suivi et relancé, chaque ancien client est rappelé au bon moment. Pour les salons, les salles de fitness, les agences, les boutiques et les centres de formation.',
};

export type Status = 'dispo' | 'bientot';

export const problems = [
  {
    icon: 'calendarX',
    title: 'Des rendez-vous qui échappent',
    text: 'Oublis, changements de dernière minute, créneaux vides. Chaque absence est une prestation perdue.',
    stat: 'Rappel la veille, confirmation en un message',
  },
  {
    icon: 'userX',
    title: 'Des clients perdus de vue',
    text: 'Une habituée ne revient plus et personne ne le remarque avant des mois.',
    stat: 'Alerte de départ réglée sur le rythme de chaque client',
  },
  {
    icon: 'book',
    title: 'Un fichier client dans un cahier',
    text: 'Les numéros sont dans un téléphone, les ventes dans un cahier, l’historique dans la tête du patron.',
    stat: 'Une fiche complète par client, importée depuis Excel',
  },
  {
    icon: 'compass',
    title: 'Des décisions à l’aveugle',
    text: 'On connaît la caisse du jour, pas les tendances, ni les prestations qui rapportent vraiment.',
    stat: 'Un rapport chaque soir dans votre boîte mail',
  },
];

export const pillars = [
  {
    key: 'communiquer',
    eyebrow: 'Engage · Communiquer',
    title: 'Écrivez à vos clients là où ils répondent : sur WhatsApp.',
    text: 'Rappels de rendez-vous, absents à reprogrammer, clients à relancer : ClientlyX prépare le message, vous l’envoyez en un clic. Dès que votre compte WhatsApp Business est validé, les rappels partent tout seuls.',
    points: [
      'Message prérempli avec le prénom, la date et la prestation',
      'Campagnes ciblées par segment, avec statut livré et lu',
      'Consentement daté et STOP respecté automatiquement',
    ],
  },
  {
    key: 'comprendre',
    eyebrow: 'Understand · Comprendre',
    title: 'Sachez qui sont vos meilleurs clients et qui est sur le départ.',
    text: 'ClientlyX classe chaque client en 8 segments RFM, lui donne un score de 0 à 100 et vous prévient quand il dépasse son délai habituel entre deux visites.',
    points: [
      'Segments : Champions, Fidèles, À risque, À réactiver…',
      'Alerte de départ calculée sur le cycle propre à chaque client',
      'VIP par établissement, export CSV en un clic',
      'Bientôt : groupes automatiques créés par l’IA',
    ],
  },
  {
    key: 'suivre',
    eyebrow: 'Convert & Retain · Suivre et fidéliser',
    title: 'Toute la relation client au même endroit, du premier RDV à la fidélité.',
    text: 'Agenda d’équipe, réservation en ligne, encaissement depuis le rendez-vous, points de fidélité et rapport du soir : vous suivez chaque client et toute l’activité, sans cahier.',
    points: [
      'Agenda Jour / Semaine / Mois, sans double réservation',
      'Encaissement depuis le RDV, chiffre d’affaires par prestation',
      'Programme de fidélité avec paliers Découverte, Argent, Or, Premium',
    ],
  },
] as const;

export type Feature = { title: string; text: string; status: Status };
export type FeatureGroup = { id: string; icon: string; title: string; lead: string; items: Feature[] };

export const featureGroups: FeatureGroup[] = [
  {
    id: 'agenda',
    icon: 'calendar',
    title: 'Agenda et réservation',
    lead: 'Un agenda clair pour toute l’équipe, et une page de réservation que vos clients ouvrent depuis WhatsApp.',
    items: [
      { title: 'Agenda Jour, Semaine, Mois', text: 'Statuts du rendez-vous (à confirmer, prévu, confirmé, honoré, absent, annulé), capacité par créneau, zéro double réservation.', status: 'dispo' },
      { title: 'Réservation en ligne sans compte', text: 'Une page mobile avec vos créneaux libres. Le client choisit sa prestation et son intervenant, reçoit le récapitulatif sur WhatsApp et peut annuler par lien. Validation manuelle ou automatique, à vous de choisir.', status: 'dispo' },
      { title: 'Disponibilités par intervenant', text: 'Horaires et prestations de chacun. En ligne, le client choisit son intervenant ou « peu importe » : ClientlyX prend le moins chargé.', status: 'dispo' },
      { title: 'Pauses, jours fériés et congés', text: 'Les créneaux se ferment tout seuls pendant la pause, les jours fériés et les congés, pour un établissement ou toute l’entreprise.', status: 'dispo' },
    ],
  },
  {
    id: 'clients',
    icon: 'users',
    title: 'Fichier client',
    lead: 'Votre cahier devient une base de clients vivante, à jour et complète.',
    items: [
      { title: 'Import Excel et CSV', text: 'Vos contacts et votre historique d’achats en quelques minutes : vous choisissez les colonnes, les doublons sont repérés, les numéros gabonais (+241) remis au bon format.', status: 'dispo' },
      { title: 'Fiche client 360°', text: 'Visites, achats, dépenses, préférences, consentement, notes : tout sur une page.', status: 'dispo' },
      { title: 'Parcours client', text: 'Contact, Prospect, Client, À risque, Inactif : chacun à sa place, mis à jour seul.', status: 'dispo' },
      { title: 'Groupes et exports', text: 'Vos propres groupes de clients (abonnées, entreprises partenaires…) et toutes vos listes en CSV, en un clic.', status: 'dispo' },
    ],
  },
  {
    id: 'segmentation',
    icon: 'pie',
    title: 'Segmentation client',
    lead: 'Savoir qui sont vos meilleurs clients, qui s’éloigne, et à qui écrire quoi.',
    items: [
      { title: '8 segments RFM', text: 'Champions, Clients fidèles, Potentiels, Nouveaux clients, À ne pas perdre, À risque, Hibernants, Perdus : recalculés chaque nuit selon la récence, la fréquence et le montant, avec l’action conseillée pour chacun.', status: 'dispo' },
      { title: 'Score client de 0 à 100', text: 'Une note simple (40 % récence, 30 % fréquence, 30 % montant) pour classer vos clients et repérer ceux qui comptent le plus.', status: 'dispo' },
      { title: 'Alerte de départ', text: 'Calculée sur le rythme propre à chaque client : risque moyen au-delà d’une fois et demie son délai habituel, élevé au-delà de deux fois.', status: 'dispo' },
      { title: 'VIP par établissement', text: 'Vos meilleurs clients repérés site par site.', status: 'dispo' },
      { title: 'Campagne ciblée', text: 'Ciblage par étape du parcours, groupe, VIP ou genre, avec le nombre de destinataires affiché en direct. Le ciblage direct par segment RFM arrive ensuite.', status: 'dispo' },
      { title: 'Groupes automatiques par l’IA', text: 'L’IA regroupe les clients qui se ressemblent (habitudes, dépenses, prestations) et nomme chaque groupe.', status: 'bientot' },
      { title: 'Valeur vie client', text: 'Ce que chaque client vous rapporte dans la durée.', status: 'bientot' },
    ],
  },
  {
    id: 'whatsapp',
    icon: 'message',
    title: 'WhatsApp et relances',
    lead: 'Le canal que vos clients lisent vraiment, sans changer leurs habitudes.',
    items: [
      { title: 'Fiche automatique et source de la pub', text: 'Le premier message d’un nouveau contact crée sa fiche, avec la pub Facebook ou Instagram qui l’a amené. Vous savez enfin quelle pub rapporte.', status: 'dispo' },
      { title: 'Accueil et réponse hors horaires', text: 'Un message d’accueil immédiat, et un message adapté quand vous êtes fermé.', status: 'dispo' },
      { title: 'Réponses automatiques de votre secteur', text: 'Un menu à boutons : prix, horaires, prendre RDV, parler à quelqu’un. Préparé pour votre métier, modifiable en quelques clics. Le client qui veut un humain vous est passé avec tout l’historique.', status: 'dispo' },
      { title: 'Listes de relance du jour', text: 'Rendez-vous à rappeler, rendez-vous manqués à reprogrammer, clients à risque, nouveaux clients à fidéliser, inactifs à reconquérir, meilleurs clients à remercier, anniversaires de la semaine : chacun avec son message prêt, modifiable.', status: 'dispo' },
      { title: 'Relance en un clic', text: 'Le message s’ouvre dans WhatsApp sur votre téléphone, déjà rempli. Chaque relance est tracée sur la fiche du client.', status: 'dispo' },
      { title: 'Campagnes WhatsApp', text: 'Avec votre compte WhatsApp Business API : modèles validés par Meta, envoi par lots, statuts remis et lu, coût estimé avant l’envoi, réponse « Stop » respectée.', status: 'dispo' },
      { title: 'Confirmation du RDV par bouton', text: 'La veille, le client reçoit un rappel avec « Je confirme » ou « Je décale ». Le rendez-vous se met à jour tout seul.', status: 'dispo' },
      { title: 'Suivi des prospects en glisser-déposer', text: 'Les étapes de votre secteur en colonnes, un délai de relance par étape et la tâche créée dans À faire.', status: 'dispo' },
      { title: 'Crédits de messages', text: 'Le coût de chaque campagne affiché avant l’envoi, un solde rechargeable, aucun envoi sans crédit.', status: 'dispo' },
      { title: 'Boîte de réception partagée', text: 'Toute l’équipe lit et répond aux messages WhatsApp depuis le même numéro, avec la fiche du client à côté de la conversation : segment, points de fidélité, prochain rendez-vous.', status: 'dispo' },
      { title: 'Répartition des conversations', text: 'Chaque client a son responsable dans l’équipe. Un nouveau message va directement chez lui, sinon chez la personne que vous choisissez.', status: 'dispo' },
      { title: 'Connexion WhatsApp guidée', text: 'Pendant l’essai, ClientlyX vous guide pas à pas pour connecter votre numéro, puis envoie un message de test dès le premier jour.', status: 'bientot' },
    ],
  },
  {
    id: 'ventes',
    icon: 'wallet',
    title: 'Ventes et encaissement',
    lead: 'Prestations et produits dans un seul catalogue, encaissés depuis le rendez-vous.',
    items: [
      { title: 'Catalogue unique', text: 'Prestations et produits avec catégories, durées et prix en FCFA.', status: 'dispo' },
      { title: 'Encaissement depuis le RDV', text: 'Un rendez-vous terminé devient une vente en deux gestes.', status: 'dispo' },
      { title: 'Chiffre d’affaires détaillé', text: 'Prestations et produits séparés, meilleures prestations, panier moyen par visite.', status: 'dispo' },
      { title: 'Moyen de paiement', text: 'Espèces, Airtel Money, Moov Money, MTN, Orange Money, carte ou virement, noté à chaque encaissement.', status: 'dispo' },
      { title: 'Acompte Mobile Money', text: 'Un acompte à la réservation pour réduire les absences.', status: 'bientot' },
    ],
  },
  {
    id: 'fidelite',
    icon: 'gift',
    title: 'Fidélité',
    lead: 'Récompensez vos habitués sans carte en carton.',
    items: [
      { title: 'Points par FCFA dépensé', text: 'Les points s’ajoutent à chaque encaissement à partir de la date de lancement, visibles sur la fiche client.', status: 'dispo' },
      { title: 'Paliers sur 12 mois', text: 'Noms et seuils réglables. Le palier se calcule sur les points gagnés en 12 mois ; prendre une récompense ne le fait jamais perdre.', status: 'dispo' },
      { title: 'Récompenses depuis la fiche', text: 'Une remise ou une prestation offerte, échangée en un clic.', status: 'dispo' },
    ],
  },
  {
    id: 'pilotage',
    icon: 'chart',
    title: 'Pilotage',
    lead: 'Les chiffres de votre activité, sans tableau Excel.',
    items: [
      { title: 'Tableau de bord', text: 'Chiffre d’affaires, contacts, opt-in WhatsApp, acheteurs, clients à traiter.', status: 'dispo' },
      { title: 'Rentabilité par source de pub', text: 'Prospects, RDV, ventes et chiffre d’affaires par source, comparés à votre budget pub.', status: 'dispo' },
      { title: 'Rapport quotidien au dirigeant', text: 'Chaque soir à l’heure choisie (21 h par défaut), par email : chiffre d’affaires, rendez-vous et absences du jour, programme du lendemain, clients à risque et actions conseillées.', status: 'dispo' },
      { title: 'Plusieurs établissements', text: 'Marques et sites séparés, rôles limités à un établissement.', status: 'dispo' },
    ],
  },
  {
    id: 'ia',
    icon: 'sparkles',
    title: 'Intelligence artificielle',
    lead: 'Une IA qui travaille pour vous, sur vos propres chiffres. Elle explique et propose ; vous gardez la main.',
    items: [
      { title: 'Agent IA de prise de rendez-vous', text: 'Sur WhatsApp, il répond à vos clients jour et nuit (prix, horaires, adresse) et réserve directement sur vos vrais créneaux et intervenants, sans outil externe. Il passe la main à un humain quand il le faut.', status: 'bientot' },
      { title: 'Rédaction des campagnes', text: 'L’IA écrit le message adapté à chaque segment, dans votre ton. Vous relisez avant l’envoi.', status: 'bientot' },
      { title: 'Explication des chiffres', text: 'Pourquoi le chiffre d’affaires baisse, quels clients relancer en priorité : expliqué en phrases simples.', status: 'bientot' },
      { title: 'Prévision d’affluence', text: 'Les jours et heures creux de la semaine prochaine, pour lancer une offre au bon moment.', status: 'bientot' },
    ],
  },
  {
    id: 'securite',
    icon: 'shield',
    title: 'Sécurité et données',
    lead: 'Vos données restent les vôtres.',
    items: [
      { title: 'Données séparées par entreprise', text: 'Des tests automatiques vérifient qu’aucune entreprise ne voit celles d’une autre.', status: 'dispo' },
      { title: 'Consentement et STOP', text: 'Consentement daté et sourcé pour chaque client, affiche QR code en caisse pour le recueillir, page de confidentialité publique, désinscription respectée.', status: 'dispo' },
      { title: 'Rôles et droits', text: 'Chaque membre de l’équipe ne voit que ce qui le concerne.', status: 'dispo' },
    ],
  },
];

// Méga-menu « Fonctionnalités » de l’en-tête : trois colonnes (les piliers) + une colonne Découvrir.
export type MenuItem = { icon: string; label: string; href: string; soon?: boolean; desc?: string };
export const featureMenu: { title: string; items: MenuItem[] }[] = [
  {
    title: 'Communiquer',
    items: [
      { icon: 'send', label: 'Relances WhatsApp en un clic', href: '/fonctionnalites#whatsapp', desc: 'Le message prêt, envoyé depuis votre téléphone' },
      { icon: 'message', label: 'Campagnes ciblées', href: '/fonctionnalites#whatsapp', desc: 'Un message pour chaque segment de clients' },
      { icon: 'inbox', label: 'Boîte de réception partagée', href: '/fonctionnalites#whatsapp', desc: 'Toute l’équipe répond depuis un seul numéro' },
      { icon: 'route', label: 'Répartition des conversations', href: '/fonctionnalites#whatsapp', desc: 'Chaque conversation va à la bonne personne' },
      { icon: 'sparkles', label: 'Agent IA de prise de RDV', href: '/fonctionnalites#ia', soon: true, desc: 'Il répond et réserve sur WhatsApp, jour et nuit' },
    ],
  },
  {
    title: 'Comprendre',
    items: [
      { icon: 'users', label: 'Fichier client 360°', href: '/fonctionnalites#clients', desc: 'Visites, achats, préférences sur une seule fiche' },
      { icon: 'pie', label: 'Segments RFM et score', href: '/fonctionnalites#segmentation', desc: 'Vos clients classés en 8 groupes, mis à jour seuls' },
      { icon: 'bell', label: 'Alerte de départ', href: '/fonctionnalites#segmentation', desc: 'Repérez les clients qui ne reviennent plus' },
      { icon: 'chart', label: 'Rapport du soir', href: '/fonctionnalites#pilotage', desc: 'Les chiffres du jour envoyés au dirigeant' },
    ],
  },
  {
    title: 'Suivre et fidéliser',
    items: [
      { icon: 'calendar', label: 'Agenda et réservation', href: '/fonctionnalites#agenda', desc: 'Jour, semaine, mois, par intervenant' },
      { icon: 'wallet', label: 'Ventes et encaissement', href: '/fonctionnalites#ventes', desc: 'Encaissez depuis le rendez-vous' },
      { icon: 'gift', label: 'Programme de fidélité', href: '/fonctionnalites#fidelite', desc: 'Points, paliers et récompenses' },
      { icon: 'layers', label: 'Plusieurs établissements', href: '/fonctionnalites#pilotage', desc: 'Toutes vos boutiques et marques réunies' },
    ],
  },
];

export const discoverMenu: MenuItem[] = [
  { icon: 'grid', label: 'Toutes les fonctionnalités', href: '/fonctionnalites' },
  { icon: 'play', label: 'Connexion WhatsApp guidée', href: '/whatsapp#connexion' },
  { icon: 'help', label: 'Pourquoi ClientlyX', href: '/#pourquoi' },
  { icon: 'shield', label: 'Sécurité et données', href: '/fonctionnalites#securite' },
  { icon: 'store', label: 'Marketplace', href: MARKETPLACE_URL },
];

export { families, type Family } from './families';

export type Plan = {
  id: string;
  name: string;
  who: string;
  price: string;
  priceCm: string;
  highlight?: boolean;
  features: string[];
};

export const plans: Plan[] = [
  {
    id: 'gratuit',
    name: 'Gratuit',
    who: 'Pour démarrer : votre fichier client et vos relances à la main',
    price: '0',
    priceCm: '0',
    features: ['1 utilisateur, jusqu’à 100 clients', 'Fiche client, agenda et catalogue', 'Relances en un clic par lien WhatsApp', 'Segments RFM et clients à relancer', 'Sans limite de durée'],
  },
  {
    id: 'solo',
    name: 'Solo',
    who: 'L’indépendant qui veut des relances automatiques',
    price: '9 900',
    priceCm: '7 500',
    features: ['Tout Gratuit, 1 utilisateur, 500 clients', 'API WhatsApp : rappels de RDV et relances automatiques', 'Réponses automatiques et hors horaires', 'Module de votre métier', 'Stock des produits vendus avec vos prestations'],
  },
  {
    id: 'equipe',
    name: 'Équipe',
    who: 'La PME qui veut piloter clients, ventes et argent',
    price: '25 000',
    priceCm: '19 000',
    highlight: true,
    features: ['Tout Solo, 5 utilisateurs, clients illimités', 'Devis et factures acceptés sur WhatsApp', 'Caisse du jour et dépenses', 'Suivi des prospects et campagnes ciblées', 'Programme de fidélité'],
  },
  {
    id: 'premium',
    name: 'Premium',
    who: 'Plusieurs sites, plusieurs marques ou une équipe commerciale',
    price: '50 000',
    priceCm: '39 000',
    features: ['Tout Équipe, 15 utilisateurs, 5 établissements', 'Analyse IA : score, alerte de départ, segments', 'Agents IA sur WhatsApp', 'Rapports PDF et rapport du soir', '1 heure de formation'],
  },
  {
    id: 'entreprise',
    name: 'Entreprise',
    who: 'Réseaux, franchises et grandes équipes',
    price: 'Sur devis',
    priceCm: 'Sur devis',
    features: ['Tout Premium, utilisateurs et sites sur mesure', 'Accompagnement et formation sur place', 'Connexion à vos autres logiciels', 'Interlocuteur dédié'],
  },
];

// Les applications ClientlyX : chacune a son espace, toutes partagent la fiche client.
export const apps: [icon: string, name: string, text: string, pack: string][] = [
  ['users', 'Clients', 'Fiche 360°, segments RFM, fidélité, prospects', 'Gratuit'],
  ['calendar', 'Agenda', 'Rendez-vous, cours, rappels la veille', 'Gratuit'],
  ['message', 'Discussions', 'WhatsApp, réponses automatiques, agents IA', 'Solo'],
  ['layers', 'Stock', 'Produits vendus avec vos prestations, alertes de réassort', 'Solo'],
  ['send', 'Devis et factures', 'Acceptés en ligne sur WhatsApp, acomptes, relances', 'Équipe'],
  ['wallet', 'Caisse du jour', 'Encaissements par moyen de paiement, clôture du soir', 'Équipe'],
  ['upload', 'Dépenses', 'Par catégorie, avec photo du reçu, bénéfice du mois', 'Équipe'],
  ['pie', 'Analyse IA', 'Score, alerte de départ, segments, tableaux de pilotage', 'Premium'],
];

// Conditions commerciales (page Tarifs), reprises par l’assistant du site.
export const offers: [icon: string, title: string, text: string][] = [
  ['gift', 'Offre Gratuit',  'Fichier client et relances par lien WhatsApp, sans limite de durée'],
  ['sparkles', '10 entreprises pilotes', 'Premier mois offert, puis 50 % de remise pendant 12 mois sur Équipe'],
  ['shield', 'Essai 14 jours', 'Sans carte bancaire, installation seule en quelques minutes'],
  ['users', 'Parrainage', '1 mois offert au parrain et au filleul'],
  ['wallet', 'Paiement annuel', '2 mois offerts, par Mobile Money'],
];

export const pricingNote =
  'Prix mensuels par entreprise, pas par utilisateur (2 000 FCFA par utilisateur en plus). Prix au Gabon. Cameroun : environ 25 % de moins. Les messages WhatsApp marketing sont facturés par Meta ; ClientlyX propose des packs de crédits payables par Mobile Money. Les rappels de rendez-vous sont inclus.';

export const objections = [
  { q: 'J’ai déjà mon cahier et WhatsApp.', a: 'ClientlyX garde WhatsApp et remplace le cahier : chaque matin, il vous dit qui confirmer, qui relancer et qui ne revient plus.' },
  { q: 'C’est trop cher pour moi.', a: 'Commencez avec l’offre Gratuit. Solo coûte ensuite 9 900 FCFA par mois, moins qu’une prestation : une seule cliente récupérée par mois le rembourse.' },
  { q: 'Je n’ai pas de carte bancaire.', a: 'L’essai de 14 jours se fait sans carte, et l’abonnement se paie par Mobile Money.' },
  { q: 'Je n’ai pas le temps de tout saisir.', a: 'Vous importez votre fichier Excel ou vos contacts, et nous faisons la configuration avec vous sur place.' },
  { q: 'Mes clients n’installeront pas d’application.', a: 'Ils n’ont rien à installer : tout passe par WhatsApp et une page de réservation qui s’ouvre dans le navigateur.' },
  { q: 'Mes clients vont parler à un robot ?', a: 'Les réponses automatiques traitent seulement les questions simples : prix, horaires, réservation. Pour le reste, la discussion vous revient avec tout l’historique.' },
  { q: 'Et mes données ?', a: 'Chaque entreprise ne voit que ses données. Le consentement de chaque client est daté, et le STOP est respecté.' },
];
