// Contenu du site vitrine. Prix, familles et fonctions viennent du cahier de
// cadrage final du 28/10/2026 : toute modification se fait ici, une seule fois.

export const APP_URL = process.env.NEXT_PUBLIC_APP_URL ?? 'https://app.menentia.com';
export const MARKETPLACE_URL = '/marketplace';
// Service IA de l’assistant du site (services/assistant). Vide : réponses de secours seules.
export const ASSISTANT_URL = process.env.NEXT_PUBLIC_ASSISTANT_URL ?? 'https://ia.menentia.com/v1/assistant';

export const links = {
  login: `${APP_URL}/login`,
  signup: `${APP_URL}/register`,
  signupPlan: (plan: string) => `${APP_URL}/register?offre=${plan}`,
};

// Numéro WhatsApp commercial : à remplacer par le vrai numéro.
export const WHATSAPP_DISPLAY = '+241 XX XX XX XX';

export const brand = {
  name: 'Menentia',
  promise: 'Connaissez vos clients. Parlez-leur au bon moment. Faites grandir votre activité.',
  signature: 'Connecter · Fidéliser · Développer',
  description:
    'Menentia est la plateforme d’intelligence client des PME africaines : fiche client, ventes, fidélité, analyse et relances WhatsApp dans une seule application, avec les modules utiles à chaque activité (rendez-vous, cours, abonnements, paiements en plusieurs fois).',
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
    eyebrow: 'Communiquer',
    title: 'Écrivez à vos clients là où ils répondent : sur WhatsApp.',
    text: 'Rappels de rendez-vous, absents à reprogrammer, clients à relancer : Menentia prépare le message, vous l’envoyez en un clic. Dès que votre compte WhatsApp Business est validé, les rappels partent tout seuls.',
    points: [
      'Message prérempli avec le prénom, la date et la prestation',
      'Campagnes ciblées par segment, avec statut livré et lu',
      'Consentement daté et STOP respecté automatiquement',
    ],
  },
  {
    key: 'comprendre',
    eyebrow: 'Comprendre',
    title: 'Sachez qui sont vos meilleurs clients et qui est sur le départ.',
    text: 'Menentia classe chaque client en 8 segments RFM, lui donne un score de 0 à 100 et vous prévient quand il dépasse son délai habituel entre deux visites.',
    points: [
      'Segments : Champions, Fidèles, À risque, À réactiver…',
      'Alerte de départ calculée sur le cycle propre à chaque client',
      'VIP par établissement, export CSV en un clic',
      'Bientôt : groupes automatiques créés par l’IA',
    ],
  },
  {
    key: 'suivre',
    eyebrow: 'Suivre',
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
      { title: 'Disponibilités par intervenant', text: 'Horaires et prestations de chacun. En ligne, le client choisit son intervenant ou « peu importe » : Menentia prend le moins chargé.', status: 'dispo' },
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
      { title: 'Listes de relance du jour', text: 'Rendez-vous à rappeler, rendez-vous manqués à reprogrammer, clients à risque, nouveaux clients à fidéliser, inactifs à reconquérir, meilleurs clients à remercier, anniversaires de la semaine : chacun avec son message prêt, modifiable.', status: 'dispo' },
      { title: 'Relance en un clic', text: 'Le message s’ouvre dans WhatsApp sur votre téléphone, déjà rempli. Chaque relance est tracée sur la fiche du client.', status: 'dispo' },
      { title: 'Campagnes WhatsApp', text: 'Avec votre compte WhatsApp Business API : modèles validés par Meta, envoi par lots, statuts remis et lu, coût estimé avant l’envoi, réponse « Stop » respectée.', status: 'dispo' },
      { title: 'Rappels automatiques', text: 'Confirmation, veille et 2 h avant, réponse « 1 = je confirme, 2 = j’annule ».', status: 'bientot' },
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
      { title: 'Chatbot IA sur WhatsApp', text: 'Il répond à vos clients jour et nuit (prix, horaires, adresse), propose vos vrais créneaux libres et prend le rendez-vous. Il passe la main à un humain quand il le faut.', status: 'bientot' },
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

// Sous-menu « Fonctionnalités » de l'en-tête : trois colonnes, chaque entrée
// renvoie à sa section de /fonctionnalites.
export type MenuFeature = { icon: string; title: string; text: string; href: string; soon?: boolean };

export const featureMenu: { title: string; items: MenuFeature[] }[] = [
  {
    title: 'Communiquer',
    items: [
      { icon: 'send', title: 'Relances WhatsApp en un clic', text: 'Le message prêt, envoyé depuis votre téléphone', href: '/fonctionnalites#whatsapp' },
      { icon: 'message', title: 'Campagnes ciblées', text: 'Un message pour chaque segment de clients', href: '/fonctionnalites#segmentation' },
      { icon: 'inbox', title: 'Boîte de réception partagée', text: 'Toute l’équipe répond depuis un seul numéro', href: '/fonctionnalites#whatsapp', soon: true },
      { icon: 'branch', title: 'Répartition des conversations', text: 'Chaque conversation va à la bonne personne', href: '/fonctionnalites#whatsapp', soon: true },
      { icon: 'sparkles', title: 'Agent IA de prise de RDV', text: 'Il répond et réserve sur WhatsApp, jour et nuit', href: '/fonctionnalites#ia', soon: true },
    ],
  },
  {
    title: 'Comprendre',
    items: [
      { icon: 'users', title: 'Fichier client 360°', text: 'Visites, achats, préférences sur une seule fiche', href: '/fonctionnalites#clients' },
      { icon: 'pie', title: 'Segments RFM et score', text: 'Vos clients classés en 8 groupes, mis à jour seuls', href: '/fonctionnalites#segmentation' },
      { icon: 'bell', title: 'Alerte de départ', text: 'Repérez les clients qui ne reviennent plus', href: '/fonctionnalites#segmentation' },
      { icon: 'chart', title: 'Rapport du soir', text: 'Les chiffres du jour envoyés au dirigeant', href: '/fonctionnalites#pilotage' },
    ],
  },
  {
    title: 'Suivre et fidéliser',
    items: [
      { icon: 'calendar', title: 'Agenda et réservation', text: 'Jour, semaine, mois, par intervenant', href: '/fonctionnalites#agenda' },
      { icon: 'wallet', title: 'Ventes et encaissement', text: 'Encaissez depuis le rendez-vous', href: '/fonctionnalites#ventes' },
      { icon: 'gift', title: 'Programme de fidélité', text: 'Points, paliers et récompenses', href: '/fonctionnalites#fidelite' },
      { icon: 'layers', title: 'Plusieurs établissements', text: 'Toutes vos boutiques et marques réunies', href: '/fonctionnalites#pilotage' },
    ],
  },
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
    id: 'solo',
    name: 'Solo',
    who: 'Indépendant : coiffeuse, coach, kiné, consultant',
    price: '9 900',
    priceCm: '7 500',
    features: ['Agenda et réservation en ligne', 'Fiche client et import Excel', 'Encaissement', 'Relances WhatsApp en un clic', 'Programme de fidélité'],
  },
  {
    id: 'equipe',
    name: 'Équipe',
    who: '2 à 8 intervenants',
    price: '25 000',
    priceCm: '19 000',
    highlight: true,
    features: ['Tout Solo', 'Agenda d’équipe', 'Segments RFM', 'Campagnes WhatsApp', 'Module de votre métier', 'Statistiques'],
  },
  {
    id: 'premium',
    name: 'Premium',
    who: 'Plusieurs sites, grandes structures',
    price: '50 000',
    priceCm: '39 000',
    features: ['Tout Équipe', 'Plusieurs établissements', 'Score client et alertes de départ', 'Assistant IA', 'Support prioritaire'],
  },
];

// Conditions commerciales (page Tarifs), reprises par l’assistant du site.
export const offers: [icon: string, title: string, text: string][] = [
  ['gift', '30 fondateurs', '3 mois offerts, puis 50 % de remise à vie sur Équipe'],
  ['shield', 'Essai 14 jours', 'Sans carte bancaire, accompagné sur place'],
  ['users', 'Parrainage', '1 mois offert au parrain et au filleul'],
  ['wallet', 'Paiement annuel', '2 mois offerts, par Mobile Money'],
];

export const pricingNote =
  'Prix mensuels au Gabon. Cameroun : environ 25 % de moins. Les messages WhatsApp marketing sont facturés par Meta ; Menentia propose des packs de crédits payables par Mobile Money. Les rappels de rendez-vous sont inclus.';

export const objections = [
  { q: 'J’ai déjà mon cahier et WhatsApp.', a: 'Menentia garde WhatsApp et remplace le cahier : chaque matin, il vous dit qui confirmer, qui relancer et qui ne revient plus.' },
  { q: 'C’est trop cher pour moi.', a: 'Solo coûte 9 900 FCFA par mois, moins qu’une prestation. Une seule cliente récupérée par mois le rembourse.' },
  { q: 'Je n’ai pas de carte bancaire.', a: 'L’essai de 14 jours se fait sans carte, et l’abonnement se paie par Mobile Money.' },
  { q: 'Je n’ai pas le temps de tout saisir.', a: 'Vous importez votre fichier Excel ou vos contacts, et nous faisons la configuration avec vous sur place.' },
  { q: 'Mes clients n’installeront pas d’application.', a: 'Ils n’ont rien à installer : tout passe par WhatsApp et une page de réservation qui s’ouvre dans le navigateur.' },
  { q: 'Le chatbot IA va-t-il répondre n’importe quoi à mes clients ?', a: 'Non. Il répond seulement à partir de votre catalogue, de vos horaires et de vos créneaux libres. Pour tout le reste, il passe la main à vous ou à votre équipe.' },
  { q: 'Et mes données ?', a: 'Chaque entreprise ne voit que ses données. Le consentement de chaque client est daté, et le STOP est respecté.' },
];
