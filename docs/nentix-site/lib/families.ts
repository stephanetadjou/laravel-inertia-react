// Les quatre secteurs NENTIX (inscription par secteur, 09/11/2026).
// Chaque page présente le problème, les difficultés concrètes, puis la solution
// NENTIX, dans le même ordre que les difficultés.

import type { Status } from './site';

export type Difficulty = { title: string; text: string; cost: string };
export type Solution = { title: string; text: string; feature: string; status: Status };

export type Family = {
  slug: string;
  name: string;
  short: string;
  icon: string;
  message: string;
  intro: string;
  trades: string[];
  rhythm: string;
  signal: string;
  module: string;
  problem: { headline: string; text: string };
  difficulties: Difficulty[];
  solutions: Solution[];
  calc: { label: string; lines: [string, string][]; total: string };
  story: { before: string; after: string };
  timeline: { without: [string, string][]; with: [string, string][] };
  chips: [string, string][];
  file: { total: number; toRecall: number; unit: string };
  example: { client: string; service: string; cycle: string; late: string };
  // Précision affichée avec les tarifs (ex. : fonctions inutiles pour ce métier).
  pricingNote?: string;
};

export const families: Family[] = [
  {
    slug: 'beaute',
    name: 'Beauté et salons',
    short: 'Beauté',
    icon: 'scissors',
    message: 'Vos clientes reviennent, NENTIX s’en occupe.',
    intro: 'Salons de coiffure, tresses, barbiers, onglerie, esthétique et spas : vos clientes reviennent toutes les 2 à 8 semaines. NENTIX sait quand chacune devrait revenir, et vous aide à la faire revenir.',
    trades: ['Coiffure', 'Tresses', 'Barbier', 'Onglerie', 'Esthétique', 'Spa'],
    rhythm: '2 à 8 semaines',
    signal: 'La cliente n’est pas revenue après son délai habituel',
    module: 'Rappel « il est temps de revenir », forfaits et cartes de séances',
    problem: {
      headline: 'Vos clientes vous aiment, mais vous ne savez pas quand elles reviendront.',
      text: 'Dans un salon, tout passe par le téléphone de la gérante : les rendez-vous en vocal WhatsApp, les clientes dans le répertoire, la caisse dans un cahier. Quand une habituée ne revient pas, personne ne le remarque. Le samedi déborde pendant que le mardi reste vide.',
    },
    difficulties: [
      { title: 'Rendez-vous oubliés', text: 'La cliente oublie ou prévient au dernier moment. La coiffeuse attend, le créneau est perdu.', cost: 'Un créneau vide ne se rattrape jamais.' },
      { title: 'Des habituées qui partent sans prévenir', text: 'Une cliente qui venait toutes les 5 semaines n’est plus venue depuis 2 mois. Elle est peut-être chez la concurrente.', cost: 'On s’en rend compte trop tard pour la retenir.' },
      { title: 'Un agenda déséquilibré', text: 'Le samedi, on refuse du monde. Le mardi, on attend.', cost: 'Du chiffre perdu les jours creux, de la fatigue les jours pleins.' },
      { title: 'Un fichier client éparpillé', text: 'Les numéros dans le téléphone, les prestations dans un cahier, les préférences dans la tête des coiffeuses.', cost: 'Impossible de savoir qui sont vos meilleures clientes.' },
    ],
    solutions: [
      { title: 'Le rappel de la veille avec « Je confirme / Je décale »', text: 'La veille, la cliente reçoit son rappel sur WhatsApp avec deux boutons. « Je confirme » confirme le rendez-vous ; « Je décale » vous crée une tâche pour la recaser. Plus de créneau perdu en silence.', feature: 'Rappel à boutons', status: 'dispo' },
      { title: '« Revenir après N jours » sur chaque prestation', text: 'Coupe tous les 30 jours, tresses tous les 60 : dès que le délai est passé sans nouveau rendez-vous, la cliente arrive dans « À faire » avec le message prêt.', feature: 'Relance par cycle', status: 'dispo' },
      { title: 'Le robot qui répond pendant que vous coiffez', text: 'Une nouvelle cliente écrit : NENTIX crée sa fiche, note la pub qui l’a fait venir et lui envoie le menu (prix, prendre RDV, horaires, parler à quelqu’un). Le soir, le message hors horaires répond pour vous.', feature: 'Réponses automatiques', status: 'dispo' },
      { title: 'Chaque cliente suivie, de la demande à la visite', text: 'Nouveau contact, RDV réservé, RDV confirmé, Venue, À relancer : les étapes avancent toutes seules avec l’agenda, et vous voyez d’un coup d’œil qui relancer.', feature: 'Suivi par étapes', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 2 absences par semaine', lines: [['Prestation moyenne', '15 000 FCFA'], ['Absences par semaine', '2'], ['Semaines par mois', '4']], total: '120 000 FCFA perdus chaque mois' },
    story: {
      before: 'Mireille venait toutes les 5 semaines pour ses tresses. Après 44 jours sans nouvelles, personne au salon ne l’avait remarqué.',
      after: 'NENTIX l’a signalée au 36e jour. La gérante lui a écrit avec une remise de 10 % ; Mireille a réservé pour le samedi suivant.',
    },
    timeline: {
      without: [['Visite', 'Semaine 0'], ['Silence', 'Semaine 5'], ['Oubliée', 'Semaine 7'], ['Silence', 'Semaine 9'], ['Partie ailleurs', 'Semaine 12']],
      with: [['Visite', 'Semaine 0'], ['Rappel la veille', 'Semaine 5'], ['Alerte de retard', 'Semaine 6'], ['Message avec offre', 'Semaine 6'], ['Revient au salon', 'Semaine 7']],
    },
    chips: [['Rappel envoyé', 'Tresses · mardi 10 h · confirmé'], ['Mireille O. à relancer', '9 jours après son délai habituel']],
    file: { total: 160, toRecall: 37, unit: 'clientes' },
    example: { client: 'Mireille O.', service: 'Tresses', cycle: 'toutes les 5 semaines', late: '9 jours de retard' },
  },
  {
    slug: 'sport',
    name: 'Sport et bien-être',
    short: 'Sport',
    icon: 'activity',
    message: 'Repérez les membres qui décrochent avant qu’ils partent.',
    intro: 'Salles de sport, coachs, yoga, danse et cours collectifs : un membre qui vient moins souvent est un abonnement qui ne sera pas renouvelé. NENTIX le repère avant l’échéance.',
    trades: ['Salle de sport', 'Coach', 'Yoga', 'Danse', 'Arts martiaux', 'Cours collectifs'],
    rhythm: 'Plusieurs fois par semaine',
    signal: 'Fréquentation en baisse avant l’échéance',
    module: 'Abonnement avec échéance, cours avec capacité',
    problem: {
      headline: 'Vos membres ne partent pas du jour au lendemain. Ils viennent de moins en moins, puis ne renouvellent pas.',
      text: 'L’abonnement se paie en début de mois, la fréquentation se lit à l’accueil, et les relances de renouvellement se font au cas par cas. Quand on remarque l’absence, l’abonnement est déjà perdu.',
    },
    difficulties: [
      { title: 'Un décrochage invisible', text: 'Un membre passe de 3 séances par semaine à une toutes les deux semaines. Rien ne vous alerte.', cost: 'Le départ est décidé avant que vous le sachiez.' },
      { title: 'Des renouvellements oubliés', text: 'Les échéances tombent chaque jour. Sans liste, on oublie de relancer.', cost: 'Des abonnements perdus par simple oubli.' },
      { title: 'Des cours mal remplis', text: 'Le cours de 18 h est plein, celui de 7 h presque vide, et les inscriptions se font dans un groupe WhatsApp.', cost: 'Des coachs payés pour des salles vides.' },
      { title: 'Aucune vue sur les revenus', text: 'Combien de membres actifs ? Combien d’abonnements à renouveler ce mois-ci ? Il faut tout recompter.', cost: 'Des décisions prises au jugé.' },
    ],
    solutions: [
      { title: 'De la séance d’essai à l’abonnement', text: 'Prospect, Essai planifié, Essai fait, Abonné, À renouveler : le lendemain de l’essai, la relance pour proposer l’abonnement est prête dans « À faire ».', feature: 'Séance d’essai', status: 'dispo' },
      { title: 'Formules, échéances et relances de paiement', text: 'Mensualités, saisons, cartes de séances : chaque abonnement prépare son échéancier, et les paiements à venir ou en retard sont listés avec le message prêt.', feature: 'Abonnements', status: 'dispo' },
      { title: 'Cours, appel et alerte d’absence', text: 'Chaque cours a ses inscrits et ses places. L’appel se fait en un geste ; deux absences en un mois, et le membre arrive dans « À faire » avant qu’il décroche.', feature: 'Cours de groupe', status: 'dispo' },
      { title: 'Les prospects des pubs, repérés et servis', text: 'Un clic sur votre pub Facebook ou Instagram, un message : la fiche est créée, la source notée, et le robot propose la séance d’essai.', feature: 'Réponses automatiques', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 10 abonnements non renouvelés', lines: [['Abonnement mensuel', '25 000 FCFA'], ['Membres qui ne renouvellent pas', '10'], ['Mois', '1']], total: '250 000 FCFA perdus chaque mois' },
    story: {
      before: 'Junior venait 3 fois par semaine. En octobre, il n’est venu qu’une fois en 15 jours, et personne ne s’en est aperçu.',
      after: 'Il passe dans le segment « À risque ». Son coach lui écrit et propose un créneau à 7 h : Junior renouvelle son abonnement.',
    },
    timeline: {
      without: [['Inscription', 'Mois 0'], ['3 séances / semaine', 'Mois 1'], ['1 séance / 2 semaines', 'Mois 2'], ['Échéance', 'Mois 3'], ['Ne renouvelle pas', 'Mois 3']],
      with: [['Inscription', 'Mois 0'], ['3 séances / semaine', 'Mois 1'], ['Alerte de baisse', 'Mois 2'], ['Appel du coach', 'Mois 2'], ['Renouvelle', 'Mois 3']],
    },
    chips: [['Junior E. · à risque', '1 séance en 2 semaines'], ['12 échéances cette semaine', 'Messages de renouvellement prêts']],
    file: { total: 160, toRecall: 33, unit: 'membres' },
    example: { client: 'Junior E.', service: 'Abonnement mensuel', cycle: '3 séances par semaine', late: '1 séance en 2 semaines' },
  },
  {
    slug: 'pro',
    name: 'Services pro et agences',
    short: 'Pro',
    icon: 'briefcase',
    message: 'Chaque client suivi, aucun dossier oublié.',
    intro: 'Cabinets comptables et juridiques, consultants, centres de formation : peu de rendez-vous, mais des dossiers qui comptent. NENTIX classe vos clients et vous rappelle ceux qui attendent.',
    trades: ['Cabinet comptable', 'Cabinet juridique', 'Consultant', 'Formation', 'Agence', 'Assurance'],
    rhythm: 'Quelques rendez-vous par an',
    signal: 'Dossier sans suite',
    module: 'Suivi de dossier par étapes',
    problem: {
      headline: 'Peu de rendez-vous, mais chacun compte. Un dossier oublié, c’est un client perdu.',
      text: 'La relation se joue sur quelques rendez-vous par an et des échéances précises. Les prospects refroidissent entre deux réunions, et les pièces attendues se perdent entre WhatsApp, l’email et le bureau.',
    },
    difficulties: [
      { title: 'Des prospects qui refroidissent', text: 'Le premier rendez-vous se passe bien, puis plus de nouvelles, des deux côtés.', cost: 'Des missions signées ailleurs.' },
      { title: 'Des échéances clients oubliées', text: 'Déclarations, renouvellements, relances de paiement : tout tient dans un agenda personnel.', cost: 'Des pénalités pour le client et une relation abîmée.' },
      { title: 'Des pièces en attente', text: 'Les documents arrivent par WhatsApp, par email ou en main propre. On ne sait plus ce qui manque.', cost: 'Des dossiers bloqués pendant des semaines.' },
      { title: 'Aucune vue sur la rentabilité', text: 'Quels clients rapportent le plus ? Lesquels prennent du temps sans suite ?', cost: 'Un temps mal réparti.' },
    ],
    solutions: [
      { title: 'Un tableau de vos prospects', text: 'À contacter, En discussion, Devis envoyé, Gagné, Perdu : faites glisser chaque prospect d’une colonne à l’autre. Chaque étape a son délai de relance, et la raison d’une perte est notée.', feature: 'Suivi des prospects', status: 'dispo' },
      { title: 'La relance du devis à J+3', text: 'Trois jours après l’envoi d’un devis sans réponse, la relance arrive dans « À faire », message prêt à envoyer sur WhatsApp.', feature: 'Relance du devis', status: 'dispo' },
      { title: 'Commerciaux et objectifs', text: 'Chaque prospect a son commercial. Objectifs, résultats et primes sont suivis sans tableau Excel, et le rapport du soir résume la journée.', feature: 'Équipe commerciale', status: 'dispo' },
      { title: 'Aucune demande perdue', text: 'Un prospect écrit sur WhatsApp : sa fiche est créée, la source de la pub notée, et « Être rappelé » crée une tâche pour votre équipe.', feature: 'Réponses automatiques', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 3 dossiers sans suite par mois', lines: [['Honoraires moyens', '150 000 FCFA'], ['Prospects sans suite', '3'], ['Mois', '1']], total: '450 000 FCFA d’honoraires manqués' },
    story: {
      before: 'Une PME a rencontré le cabinet en septembre pour son bilan. Sans relance, le dossier est resté en suspens.',
      after: 'Le contact était en étape « Prospect » depuis 30 jours : il est apparu dans les relances. Un message, un rendez-vous, et la lettre de mission est signée.',
    },
    timeline: {
      without: [['Premier rendez-vous', 'Septembre'], ['Silence', 'Octobre'], ['Dossier en attente', 'Novembre'], ['Silence', 'Décembre'], ['Signé ailleurs', 'Janvier']],
      with: [['Premier rendez-vous', 'Septembre'], ['Étape Prospect', 'Septembre'], ['Relance à 30 jours', 'Octobre'], ['Pièces reçues', 'Octobre'], ['Mission signée', 'Novembre']],
    },
    chips: [['Relance prête', 'Pièces manquantes · envoi en 1 clic'], ['SARL Akanda · Prospect', 'Sans suite depuis 30 jours']],
    file: { total: 160, toRecall: 24, unit: 'clients' },
    example: { client: 'SARL Akanda Services', service: 'Bilan annuel', cycle: 'chaque année', late: 'pièces attendues' },
  },
  {
    slug: 'boutiques',
    name: 'Boutiques',
    short: 'Boutiques',
    icon: 'store',
    message: 'Vos clientes reviennent acheter, au bon moment.',
    intro: 'Mode, cosmétiques, chaussures, épiceries fines, supermarchés : vos ventes passent par la caisse, mais vous ne savez pas qui achète quoi. NENTIX relie chaque ticket à la bonne cliente et vous dit qui relancer, avec quel produit.',
    trades: ['Mode', 'Cosmétiques', 'Chaussures', 'Accessoires', 'Supermarché', 'Plusieurs boutiques'],
    rhythm: '2 à 8 semaines',
    signal: 'La cliente n’a pas racheté dans sa famille de produits habituelle',
    module: 'Import des ventes, analyse par famille, fidélité par enseigne',
    problem: {
      headline: 'Votre caisse connaît vos ventes. Elle ne connaît pas vos clientes.',
      text: 'Les tickets s’accumulent dans le logiciel de caisse, les numéros dans le téléphone de la vendeuse, et les promotions partent à tout le monde. Une bonne cliente qui ne revient plus passe inaperçue.',
    },
    difficulties: [
      { title: 'Des clientes anonymes', text: 'Des centaines de tickets par mois, mais impossible de dire qui sont vos 50 meilleures clientes.', cost: 'Vous traitez une fidèle comme une inconnue.' },
      { title: 'Des promotions envoyées à tout le monde', text: 'La même promo pour toutes : celles qui n’achètent jamais de chaussures reçoivent l’offre chaussures.', cost: 'Des messages ignorés, puis des « Stop ».' },
      { title: 'Des départs silencieux', text: 'Une cliente qui venait chaque mois n’est plus passée depuis trois mois.', cost: 'Elle achète déjà chez le voisin.' },
      { title: 'Plusieurs boutiques, aucune vue d’ensemble', text: 'Chaque point de vente a ses chiffres, personne ne voit la cliente qui achète dans deux enseignes.', cost: 'Aucune vente croisée entre vos marques.' },
    ],
    solutions: [
      { title: 'L’import des ventes de votre caisse', text: 'Un fichier Excel de votre caisse suffit : chaque ticket rejoint la bonne fiche, les familles de produits sont reconnues, les erreurs listées ligne par ligne.', feature: 'Import des ventes', status: 'dispo' },
      { title: 'Des campagnes par famille de produits', text: 'Les clientes qui achètent des robes mais plus depuis 60 jours, celles qui n’ont jamais acheté de parfum : un groupe en un clic, une campagne ciblée, son coût affiché avant l’envoi.', feature: 'Campagnes par famille', status: 'dispo' },
      { title: 'Nouvelle, acheteuse, fidèle, à risque', text: 'Chaque cliente change d’étape toute seule selon ses achats. Celles qui s’éloignent arrivent dans « À faire » avec le message prêt.', feature: 'Parcours client', status: 'dispo' },
      { title: 'La fidélité par enseigne', text: 'Points, paliers et récompenses, une grille par boutique ou commune, et la vente croisée entre vos marques.', feature: 'Fidélité', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 20 clientes qui ne reviennent plus', lines: [['Panier moyen', '30 000 FCFA'], ['Clientes perdues par mois', '20'], ['Achats par cliente et par mois', '1']], total: '600 000 FCFA de ventes perdues chaque mois' },
    story: {
      before: 'Nadia achetait une robe chaque mois chez Boutique Élégance. Depuis août, plus rien, et personne ne l’a remarqué.',
      after: 'Elle est apparue dans « Clientes à risque » avec la famille « Robes ». Un message avec la nouvelle collection : Nadia est revenue samedi.',
    },
    timeline: {
      without: [['Achat', 'Mois 0'], ['Achat', 'Mois 1'], ['Silence', 'Mois 2'], ['Silence', 'Mois 3'], ['Partie ailleurs', 'Mois 4']],
      with: [['Achat', 'Mois 0'], ['Achat', 'Mois 1'], ['Alerte « à risque »', 'Mois 2'], ['Message ciblé', 'Mois 2'], ['Revient acheter', 'Mois 2']],
    },
    chips: [['Nadia K. · à risque', 'Robes · 75 jours sans achat'], ['Campagne « Parfums »', '128 clientes · coût affiché']],
    file: { total: 160, toRecall: 31, unit: 'clientes' },
    example: { client: 'Nadia K.', service: 'Robes', cycle: 'chaque mois', late: '75 jours sans achat' },
  },
];
