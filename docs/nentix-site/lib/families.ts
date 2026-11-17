// Les huit secteurs NENTIX (inscription par secteur, 09/11/2026).
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
  // La ressource que l'entreprise doit rentabiliser, et comment NENTIX l'optimise
  // (creux à remplir, saturation à lisser, ressource immobilisée).
  resource: { name: string; goal: string; levers: [string, string][] };
  // Précision affichée avec les tarifs (ex. : fonctions inutiles pour ce métier).
  pricingNote?: string;
};

// Secteur du site -> valeur acceptée par l'application (Lead::ACTIVITIES).
export function leadActivity(slug: string): string {
  return ({ boutiques: 'commerce' } as Record<string, string>)[slug] ?? slug;
}

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
    resource: {
      name: 'Les heures de vos intervenantes',
      goal: 'Pas de créneau vide en semaine, pas de samedi où l’on refuse des clientes.',
      levers: [
        ['Créneaux à remplir', 'Chaque soir, les créneaux libres du lendemain et les clientes dont la visite est due, avec le message « Il reste 10 h et 14 h avec Mireille ».'],
        ['Jours trop pleins', 'Le samedi est complet ? Liste d’attente, et proposition d’un autre jour aux habituées.'],
        ['Intervenante sans cliente', 'Taux de remplissage de chaque intervenante, jour par jour, pour ne pas la laisser deux heures sans travail.'],
      ],
    },
  },
  {
    slug: 'sante',
    name: 'Santé et bien-être',
    short: 'Santé',
    icon: 'heart',
    message: 'Moins d’absents, des patients revus au bon moment.',
    intro: 'Cabinets médicaux et dentaires, kinés, cliniques, spas et centres de massage : votre agenda est votre ressource. NENTIX rappelle chaque rendez-vous la veille et chaque patient quand vient l’heure de son contrôle.',
    trades: ['Cabinet médical', 'Dentiste', 'Kiné', 'Clinique', 'Spa', 'Massage'],
    rhythm: 'Contrôle tous les 6 à 12 mois',
    signal: 'Contrôle dépassé, rendez-vous manqué',
    module: 'Agenda des praticiens, rappels de contrôle',
    problem: { headline: 'Un rendez-vous manqué, c’est une heure de praticien perdue.', text: 'Les patients oublient, les contrôles passent, et l’agenda reste troué en semaine pendant que le samedi déborde.' },
    difficulties: [
      { title: 'Des patients qui ne viennent pas', text: 'Rendez-vous oublié, aucun prévenu.', cost: 'Une heure de praticien perdue.' },
      { title: 'Des contrôles oubliés', text: 'Le détartrage ou le suivi annuel passe sans rappel.', cost: 'Des patients qui partent ailleurs.' },
      { title: 'Un agenda mal rempli', text: 'Mardi vide, samedi plein.', cost: 'Du temps de praticien inutilisé.' },
      { title: 'Un secrétariat débordé', text: 'Le téléphone sonne pour des questions simples.', cost: 'Des appels manqués.' },
    ],
    solutions: [
      { title: 'Rappel la veille avec confirmation', text: 'Le patient répond « Je confirme » ou « Je décale » sur WhatsApp, le créneau se libère à temps.', feature: 'Rappel de la veille', status: 'dispo' },
      { title: 'Le rappel de contrôle', text: 'Chaque soin a son délai (détartrage à 6 mois) : le patient arrive dans « À faire » avec le message prêt.', feature: 'Relance par cycle', status: 'dispo' },
      { title: 'Les créneaux libres proposés', text: 'Les heures libres des prochains jours sont proposées aux patients à revoir.', feature: 'Occupation', status: 'dispo' },
      { title: 'Un menu WhatsApp et un assistant IA', text: 'Horaires, prendre rendez-vous, parler au secrétariat : les questions simples sont traitées seules.', feature: 'Réponses automatiques', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 6 rendez-vous manqués par semaine', lines: [['Consultation moyenne', '15 000 FCFA'], ['Absences par semaine', '6'], ['Semaines par mois', '4']], total: '360 000 FCFA perdus chaque mois' },
    story: { before: 'Mme Ndong devait revenir pour son détartrage en juin. Personne ne l’a rappelée.', after: 'Au 6e mois, elle est apparue dans « À faire ». Un message, un créneau mardi : elle est revenue.' },
    timeline: { without: [['Soin', 'Mois 0'], ['Silence', 'Mois 3'], ['Silence', 'Mois 6'], ['Oublié', 'Mois 8'], ['Parti ailleurs', 'Mois 12']], with: [['Soin', 'Mois 0'], ['Suivi', 'Mois 3'], ['Rappel de contrôle', 'Mois 6'], ['Créneau proposé', 'Mois 6'], ['Revenu', 'Mois 6']] },
    chips: [['Mme Ndong · contrôle', 'Détartrage dû depuis 10 jours'], ['Demain : 3 créneaux libres', 'Proposés aux patients à revoir']],
    file: { total: 160, toRecall: 22, unit: 'patients' },
    example: { client: 'Mme Ndong', service: 'Détartrage', cycle: 'tous les 6 mois', late: '10 jours de retard' },
    resource: {
      name: 'L’agenda de vos praticiens',
      goal: 'Pas de trou en semaine, pas d’absent sans prévenir.',
      levers: [['Rappel de la veille', 'Confirmation ou report sur WhatsApp : le créneau se libère à temps.'], ['Créneaux à remplir', 'Les heures libres proposées aux patients dont le contrôle approche.'], ['Agenda saturé', 'Les jours pleins repérés : proposez un autre moment.']],
    },
  },
  {
    slug: 'sport',
    name: 'Sport et fitness',
    short: 'Sport',
    icon: 'activity',
    message: 'Des abonnés actifs, des cours pleins, des renouvellements à l’heure.',
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
    resource: {
      name: 'Les places de vos cours et les heures de vos coachs',
      goal: 'Des cours bien remplis, des coachs occupés, personne refusé.',
      levers: [
        ['Cours peu remplis', 'À J-2, un cours à moins de 50 % : les membres peu actifs sont invités, message prêt.'],
        ['Cours complets', 'Liste d’attente qui prévient dès qu’une place se libère, et alerte « ouvrir une 2e séance » au bout de 3 semaines complètes.'],
        ['Coachs disponibles', 'Les heures libres de chaque coach proposées aux membres qui veulent un suivi personnel.'],
      ],
    },
  },
  {
    slug: 'technique',
    name: 'Services techniques',
    short: 'Technique',
    icon: 'wrench',
    message: 'L’atelier plein, les entretiens rappelés à temps.',
    intro: 'Garages et ateliers, réparation, installation, maintenance : vos heures d’atelier sont votre ressource. NENTIX relance les devis, rappelle le prochain entretien et remplit les jours creux.',
    trades: ['Garage', 'Atelier', 'Carrosserie', 'Climatisation', 'Électricité', 'Maintenance'],
    rhythm: 'Entretien tous les 3 à 12 mois',
    signal: 'Entretien dépassé, devis sans réponse',
    module: 'Rendez-vous atelier, devis, rappels d’entretien',
    problem: { headline: 'Un devis oublié, c’est un chantier qui part chez le voisin.', text: 'Les devis partent sans relance, les clients oublient leur vidange, et l’atelier alterne jours vides et jours débordés.' },
    difficulties: [
      { title: 'Des devis sans suite', text: 'Envoyé sur WhatsApp, jamais relancé.', cost: 'Des chantiers perdus.' },
      { title: 'Des entretiens oubliés', text: 'La vidange passe, le client ne revient pas.', cost: 'Un client qui va ailleurs.' },
      { title: 'Un atelier mal rempli', text: 'Lundi vide, vendredi débordé.', cost: 'Des heures de mécanicien perdues.' },
      { title: 'Aucun historique', text: 'Quel travail sur quel client, à quelle date ?', cost: 'Des erreurs et du temps perdu.' },
    ],
    solutions: [
      { title: 'Devis relancé à J+3', text: 'Trois jours après un devis sans réponse, la relance est prête dans « À faire ».', feature: 'Relance du devis', status: 'dispo' },
      { title: 'Le rappel du prochain entretien', text: 'Vidange à 3 mois, révision à 1 an : le client est rappelé au bon moment.', feature: 'Relance par cycle', status: 'dispo' },
      { title: 'Les jours creux remplis', text: 'Les heures libres de l’atelier proposées aux clients dont l’entretien approche.', feature: 'Occupation', status: 'dispo' },
      { title: 'Fiche et historique de chaque client', text: 'Rendez-vous, travaux et paiements au même endroit.', feature: 'Fiche client', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 5 devis sans suite par mois', lines: [['Devis moyen', '120 000 FCFA'], ['Devis perdus', '5'], ['Mois', '1']], total: '600 000 FCFA de travaux manqués' },
    story: { before: 'M. Obame avait demandé un devis pour ses freins. Pas de relance, il est allé ailleurs.', after: 'Le devis est apparu dans « À faire » à J+3. Un message, un rendez-vous jeudi : le chantier est fait.' },
    timeline: { without: [['Devis', 'Jour 0'], ['Silence', 'Jour 3'], ['Silence', 'Jour 7'], ['Oublié', 'Jour 14'], ['Fait ailleurs', 'Jour 20']], with: [['Devis', 'Jour 0'], ['Relance', 'Jour 3'], ['Rendez-vous', 'Jour 4'], ['Travaux', 'Jour 6'], ['Prochain entretien', 'Mois 3']] },
    chips: [['M. Obame · devis', 'Sans réponse depuis 3 jours'], ['Lundi : atelier à 40 %', 'Entretiens à proposer']],
    file: { total: 160, toRecall: 26, unit: 'clients' },
    example: { client: 'M. Obame', service: 'Vidange', cycle: 'tous les 3 mois', late: '15 jours de retard' },
    resource: {
      name: 'Les heures de votre atelier',
      goal: 'Un atelier régulièrement plein, des mécaniciens jamais inoccupés.',
      levers: [['Jours creux', 'Les heures libres proposées aux clients dont l’entretien approche.'], ['Devis à relancer', 'Chaque devis relancé à J+3, message prêt.'], ['Jours saturés', 'Les pics repérés : décalez les entretiens non urgents.']],
    },
  },
  {
    slug: 'pro',
    name: 'Services pro et agences',
    short: 'Pro',
    icon: 'briefcase',
    message: 'Chaque client suivi, aucun dossier oublié.',
    intro: 'Cabinets comptables et juridiques, consultants, agences : peu de rendez-vous, mais des dossiers qui comptent. NENTIX classe vos clients et vous rappelle ceux qui attendent.',
    trades: ['Cabinet comptable', 'Cabinet juridique', 'Consultant', 'Agence', 'Photographe', 'Assurance'],
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
    resource: {
      name: 'Les heures facturables de votre équipe',
      goal: 'Chacun bien chargé, personne débordé, aucun dossier qui attend.',
      levers: [
        ['Taux de charge', 'Le temps réservé de chaque collaborateur, semaine par semaine.'],
        ['Équipe disponible', 'Quand l’équipe a de la place, les prospects en attente remontent dans « À faire ».'],
        ['Collaborateur débordé', 'Les dossiers en retard à répartir avant qu’ils ne bloquent.'],
      ],
    },
  },
  {
    slug: 'formation',
    name: 'Centres de formation',
    short: 'Formation',
    icon: 'book',
    message: 'Chaque apprenant suivi jusqu’à son examen.',
    intro: 'Auto-écoles, écoles de langues, centres de formation professionnelle, cours de soutien : vos apprenants suivent un parcours, pas des visites. NENTIX compte les heures faites, prépare les examens et vous signale ceux qui décrochent.',
    trades: ['Auto-école', 'École privée', 'Crèche', 'Cours de soutien', 'École de langues', 'Formation professionnelle'],
    rhythm: 'Plusieurs leçons par semaine, jusqu’à l’examen',
    signal: 'Plus de leçon depuis 15 jours, ou retard sur la date d’examen',
    module: 'Suivi des apprenants, examens, paiement en tranches',
    problem: {
      headline: 'Un apprenant qui décroche, c’est une formation payée à moitié et un examen raté.',
      text: 'Les heures sont notées sur un carnet, les tranches de paiement dans un cahier, et personne ne voit l’élève qui ne vient plus depuis trois semaines avant qu’il soit trop tard.',
    },
    difficulties: [
      { title: 'Des apprenants qui décrochent', text: 'Un élève manque deux leçons, puis disparaît sans prévenir.', cost: 'Des tranches impayées et un mauvais bouche-à-oreille.' },
      { title: 'Des heures mal comptées', text: 'Combien d’heures faites, combien restent avant l’examen ? Il faut tout recompter à la main.', cost: 'Des litiges et des examens présentés trop tôt.' },
      { title: 'Des tranches oubliées', text: 'La formation se paie en plusieurs fois, mais les échéances ne sont suivies nulle part.', cost: 'Des impayés découverts en fin de formation.' },
      { title: 'Des moniteurs et des salles en conflit', text: 'Deux leçons sur le même véhicule ou dans la même salle à la même heure.', cost: 'Des leçons annulées et des élèves mécontents.' },
    ],
    solutions: [
      { title: 'Le parcours de chaque apprenant', text: 'Heures faites calculées à partir des leçons honorées et des présences, progression, examens dans l’ordre avec chaque tentative et son résultat.', feature: 'Suivi des apprenants', status: 'dispo' },
      { title: 'L’alerte de décrochage', text: 'Pas de leçon depuis 15 jours, retard sur la date d’objectif, deux échecs sans nouvelle date : l’apprenant arrive dans « À faire » avec le message prêt.', feature: 'Alerte de décrochage', status: 'dispo' },
      { title: 'Le paiement en tranches', text: 'À l’inscription, la formule et son échéancier en plusieurs tranches. Chaque tranche due apparaît dans les relances, chaque tranche encaissée est une vente.', feature: 'Paiement en tranches', status: 'dispo' },
      { title: 'Véhicules, salles et moniteurs', text: 'Un véhicule ou une salle ne sert qu’à un rendez-vous à la fois, chaque moniteur a ses horaires : plus de doubles réservations.', feature: 'Ressources', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 4 apprenants qui décrochent par mois', lines: [['Formation moyenne', '250 000 FCFA'], ['Part restant à payer', '50 %'], ['Apprenants perdus par mois', '4']], total: '500 000 FCFA de tranches perdues chaque mois' },
    story: {
      before: 'Kevin s’est inscrit au permis en septembre. Après 8 heures de conduite, il n’est plus revenu, et la deuxième tranche n’a jamais été payée.',
      after: 'Au 15e jour sans leçon, il est apparu dans « À faire ». Un message, une leçon reprogrammée : Kevin a passé son code en novembre.',
    },
    timeline: {
      without: [['Inscription', 'Semaine 1'], ['8 heures de conduite', 'Semaine 4'], ['Silence', 'Semaine 6'], ['Tranche impayée', 'Semaine 8'], ['Abandon', 'Semaine 10']],
      with: [['Inscription', 'Semaine 1'], ['8 heures de conduite', 'Semaine 4'], ['Alerte de décrochage', 'Semaine 6'], ['Leçon reprogrammée', 'Semaine 6'], ['Examen réussi', 'Semaine 12']],
    },
    chips: [['Kevin M. · décroche', '15 jours sans leçon'], ['6 tranches dues cette semaine', 'Messages de relance prêts']],
    file: { total: 40, toRecall: 7, unit: 'apprenants' },
    example: { client: 'Kevin M.', service: 'Permis B', cycle: '2 leçons par semaine', late: '15 jours sans leçon' },
    resource: {
      name: 'Les places de vos sessions et vos salles',
      goal: 'Chaque session remplie, chaque salle et chaque véhicule d’école utilisés.',
      levers: [
        ['Session peu remplie', 'À J-10, une session à moins de 60 % : relance des prospects et des anciens apprenants intéressés.'],
        ['Seuil de rentabilité', 'À J-7, décision claire : maintenir, reporter ou fusionner la session.'],
        ['Session complète', 'La liste d’attente sert à ouvrir la session suivante, déjà presque pleine.'],
      ],
    },
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
    resource: {
      name: 'Votre équipe face à l’affluence',
      goal: 'Assez de vendeuses aux heures de pointe, des clientes les jours calmes.',
      levers: [
        ['Carte de l’affluence', 'Les jours et les heures les plus chargés, lus dans vos tickets, pour placer l’équipe.'],
        ['Jours calmes', 'La veille d’un jour creux, un message ciblé aux clientes fidèles.'],
        ['Pics prévus', 'Fins de mois et samedis repérés à l’avance.'],
      ],
    },
  },
  {
    slug: 'vehicules',
    name: 'Gestion de flotte',
    short: 'Véhicules',
    icon: 'car',
    message: 'Toujours une voiture prête, aucune qui dort au parking.',
    intro: 'Loueurs courte et longue durée, location avec chauffeur, vente de véhicules neufs ou d’occasion : votre capital roule ou il dort. NENTIX vous montre chaque jour quelle voiture louer, laquelle garder libre et laquelle vendre.',
    trades: ['Location courte durée', 'Location longue durée', 'Avec chauffeur', 'Vente d’occasion', 'Utilitaires', 'Minibus'],
    rhythm: 'Du jour au contrat de plusieurs mois',
    signal: 'Véhicule au parking depuis 7 jours, ou client fidèle qui ne loue plus',
    module: 'Flotte, contrats, chauffeurs et véhicules à vendre',
    problem: {
      headline: 'Une voiture au parking coûte chaque jour. Une voiture manquante fait partir le client.',
      text: 'Les réservations sont dans un cahier ou sur WhatsApp, les retours se chevauchent, et personne ne voit le 4x4 qui n’est pas sorti depuis dix jours.',
    },
    difficulties: [
      { title: 'Des véhicules qui dorment', text: 'Certaines voitures tournent sans arrêt, d’autres restent au parking.', cost: 'Un capital immobilisé qui ne rapporte rien.' },
      { title: 'Plus rien à louer au mauvais moment', text: 'Vendredi soir, un client entreprise appelle : tout est sorti.', cost: 'Le client loue chez le concurrent.' },
      { title: 'Des contrats et des retours mal suivis', text: 'Caution, kilométrage, carburant et dates de retour sur papier.', cost: 'Des litiges et des retards qui bloquent la location suivante.' },
      { title: 'Des véhicules à vendre qui vieillissent', text: 'Une occasion en vitrine depuis trois mois, sans relance des acheteurs intéressés.', cost: 'Une décote chaque mois.' },
    ],
    solutions: [
      { title: 'Le planning de la flotte', text: 'Une ligne par véhicule, les jours en colonnes : locations, retours prévus, entretiens et trous, sans double réservation. Chauffeurs planifiés comme les véhicules.', feature: 'Planning de la flotte', status: 'dispo' },
      { title: 'Un stock de sécurité', text: 'Vous voulez toujours 2 véhicules libres : NENTIX vous prévient la veille quand ce n’est plus le cas, avec les retours attendus.', feature: 'Stock de sécurité', status: 'dispo' },
      { title: 'Contrats courte et longue durée', text: 'Caution, kilométrage et carburant au départ et au retour ; mensualités suivies pour la longue durée et renouvellement proposé à J-30.', feature: 'Contrats', status: 'dispo' },
      { title: 'Véhicules à vendre suivis', text: 'Âge de chaque véhicule en stock, acheteurs intéressés par catégorie, message prêt quand un véhicule dépasse 60 jours.', feature: 'Vente', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 3 véhicules qui dorment 10 jours par mois', lines: [['Prix de location par jour', '35 000 FCFA'], ['Jours au parking', '10'], ['Véhicules concernés', '3']], total: '1 050 000 FCFA de location manquée chaque mois' },
    story: {
      before: 'Le Hilux de l’agence n’était pas sorti depuis 9 jours, pendant que les SUV étaient réservés deux semaines à l’avance.',
      after: 'NENTIX l’a signalé au 7e jour. Un message aux clients qui avaient déjà loué un 4x4 : il est parti le lendemain pour une mission de 5 jours.',
    },
    timeline: {
      without: [['Retour du véhicule', 'Jour 0'], ['Au parking', 'Jour 3'], ['Au parking', 'Jour 7'], ['Au parking', 'Jour 10'], ['Toujours là', 'Jour 14']],
      with: [['Retour du véhicule', 'Jour 0'], ['Au parking', 'Jour 3'], ['Alerte « immobilisé »', 'Jour 7'], ['Message ciblé', 'Jour 7'], ['Loué 5 jours', 'Jour 8']],
    },
    chips: [['Hilux · immobilisé', '7 jours au parking'], ['Demain : 1 véhicule libre', 'Stock de sécurité : 2']],
    file: { total: 120, toRecall: 18, unit: 'clients' },
    example: { client: 'SARL Ogooué Mines', service: 'Location SUV', cycle: 'chaque mois', late: '45 jours sans location' },
    resource: {
      name: 'Vos jours-véhicule et votre stock à vendre',
      goal: 'Toujours quelques voitures prêtes à louer, aucune qui reste trop longtemps au parking.',
      levers: [
        ['Stock de sécurité', 'Toujours 2 véhicules libres par défaut : alerte la veille si ce n’est plus le cas.'],
        ['Véhicule immobilisé', 'Au 7e jour au parking, offre aux clients qui ont déjà loué cette catégorie.'],
        ['Taux d’utilisation', 'Par véhicule et par catégorie : savoir quoi acheter, quoi vendre.'],
      ],
    },
  },
];
