// Les cinq familles de métiers (cahier de cadrage final, section 2).
// Chaque page présente le problème, les difficultés concrètes, puis la solution
// Menentia, dans le même ordre que les difficultés.

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
    name: 'Beauté et soins',
    short: 'Beauté',
    icon: 'scissors',
    message: 'Vos clientes reviennent, Menentia s’en occupe.',
    intro: 'Salons de coiffure, tresses, barbiers, onglerie, esthétique et spas : vos clientes reviennent toutes les 2 à 8 semaines. Menentia sait quand chacune devrait revenir, et vous aide à la faire revenir.',
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
      { title: 'Le rappel de la veille, prêt à partir', text: 'Chaque jour, la liste « Rendez-vous à rappeler » vous donne les rendez-vous du lendemain avec le message prêt : un clic, et il part depuis votre téléphone. L’envoi automatique arrive avec l’API WhatsApp.', feature: 'Relances WhatsApp', status: 'dispo' },
      { title: 'L’alerte « il est temps de revenir »', text: 'Menentia connaît le rythme de chaque cliente et vous prévient dès qu’elle dépasse son délai. Vous la relancez en un clic, avec une offre si vous le souhaitez.', feature: 'Alerte de départ', status: 'dispo' },
      { title: 'Réservation en ligne et relances ciblées', text: 'Votre lien de réservation dans votre statut WhatsApp : les clientes voient vos créneaux libres et réservent seules, même à minuit. Pour remplir le mardi, relancez un segment précis.', feature: 'Réservation en ligne', status: 'dispo' },
      { title: 'Une fiche complète par cliente', text: 'Visites, prestations, dépenses, préférences et points de fidélité sur une seule page. Votre fichier Excel ou vos contacts s’importent en quelques minutes.', feature: 'Fiche client 360°', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 2 absences par semaine', lines: [['Prestation moyenne', '15 000 FCFA'], ['Absences par semaine', '2'], ['Semaines par mois', '4']], total: '120 000 FCFA perdus chaque mois' },
    story: {
      before: 'Mireille venait toutes les 5 semaines pour ses tresses. Après 44 jours sans nouvelles, personne au salon ne l’avait remarqué.',
      after: 'Menentia l’a signalée au 36e jour. La gérante lui a écrit avec une remise de 10 % ; Mireille a réservé pour le samedi suivant.',
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
    slug: 'sante',
    name: 'Santé',
    short: 'Santé',
    icon: 'heart',
    message: 'Moins de rendez-vous manqués, des patients suivis.',
    intro: 'Cliniques, cabinets dentaires, kinés, opticiens, psychologues : un contrôle manqué ou un traitement interrompu, c’est un patient mal suivi. Menentia rappelle, confirme et vous montre qui décroche.',
    trades: ['Clinique', 'Dentiste', 'Kinésithérapeute', 'Opticien', 'Psychologue', 'Laboratoire'],
    rhythm: 'Traitement ou contrôle périodique',
    signal: 'Traitement interrompu ou contrôle manqué',
    module: 'Notes privées chiffrées, rappel de contrôle',
    problem: {
      headline: 'Un patient qui manque son contrôle n’est pas seulement un créneau perdu. C’est un patient mal suivi.',
      text: 'Les rendez-vous se prennent par téléphone, les rappels se font quand on a le temps, et les dossiers dorment dans des classeurs. Les patients décrochent en silence, et le cabinet le découvre au prochain problème de santé.',
    },
    difficulties: [
      { title: 'Consultations et séances manquées', text: 'Le patient oublie ou ne prévient pas. La salle d’attente est vide à 10 h et pleine à 16 h.', cost: 'Du temps de praticien perdu, des patients qui attendent.' },
      { title: 'Des traitements interrompus', text: 'Une rééducation de 10 séances qui s’arrête à la 4e. Un contrôle à 6 mois qui n’a jamais lieu.', cost: 'Des soins moins efficaces et un patient qui ne revient plus.' },
      { title: 'Un secrétariat débordé', text: 'Le téléphone sonne pendant les soins. Prendre, déplacer et rappeler les rendez-vous occupe une personne à plein temps.', cost: 'Des appels manqués, donc des patients perdus.' },
      { title: 'Des données sensibles mal protégées', text: 'Des informations médicales circulent parfois par message, sans contrôle de qui y a accès.', cost: 'Un risque pour la confidentialité et pour la confiance.' },
    ],
    solutions: [
      { title: 'Rappel la veille sur WhatsApp', text: 'Les rendez-vous du lendemain sont listés avec le message de rappel prêt. Le patient qui a réservé en ligne peut annuler par lien, ce qui libère le créneau pour un autre.', feature: 'Relances WhatsApp', status: 'dispo' },
      { title: 'Les patients en retard listés chaque matin', text: 'Menentia repère ceux qui ont arrêté avant la fin du traitement ou dépassé leur date de contrôle, avec le message de rappel prêt.', feature: 'Alerte de départ', status: 'dispo' },
      { title: 'La réservation en ligne, sans appel', text: 'Les patients prennent rendez-vous eux-mêmes sur vos créneaux libres. Le secrétariat ne gère plus que les exceptions.', feature: 'Réservation en ligne', status: 'dispo' },
      { title: 'Notes privées et droits par rôle', text: 'Chaque membre de l’équipe ne voit que ce qui le concerne. Les notes médicales restent visibles du seul praticien et ne partent jamais par message.', feature: 'Module Santé', status: 'bientot' },
    ],
    calc: { label: 'Ce que coûtent 4 séances manquées par semaine', lines: [['Séance de kiné', '10 000 FCFA'], ['Séances manquées par semaine', '4'], ['Semaines par mois', '4']], total: '160 000 FCFA perdus chaque mois' },
    story: {
      before: 'M. Paul faisait un détartrage tous les 6 mois. Cela fait 7 mois qu’il n’est pas revenu, et personne ne l’a rappelé.',
      after: 'Il apparaît dans la liste « contrôles en retard ». Un message de rappel avec le lien de réservation : rendez-vous pris pour jeudi.',
    },
    timeline: {
      without: [['Consultation', 'Mois 0'], ['Silence', 'Mois 3'], ['Contrôle oublié', 'Mois 6'], ['Silence', 'Mois 9'], ['Patient perdu', 'Mois 12']],
      with: [['Consultation', 'Mois 0'], ['Suivi noté', 'Mois 0'], ['Rappel de contrôle', 'Mois 6'], ['Rendez-vous pris', 'Mois 6'], ['Patient suivi', 'Mois 6']],
    },
    chips: [['Contrôle à 6 mois', 'Rappel prêt · lien de réservation'], ['Séance 5 sur 10', 'Paul M. n’est pas venu jeudi']],
    file: { total: 160, toRecall: 29, unit: 'patients' },
    example: { client: 'Paul M.', service: 'Contrôle dentaire', cycle: 'tous les 6 mois', late: '3 semaines de retard' },
  },
  {
    slug: 'sport',
    name: 'Sport et bien-être',
    short: 'Sport',
    icon: 'activity',
    message: 'Repérez les membres qui décrochent avant qu’ils partent.',
    intro: 'Salles de sport, coachs, yoga, danse et cours collectifs : un membre qui vient moins souvent est un abonnement qui ne sera pas renouvelé. Menentia le repère avant l’échéance.',
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
      { title: 'L’alerte de baisse de fréquentation', text: 'Menentia compare la fréquentation de chaque membre à son habitude et vous prévient avant qu’il décroche.', feature: 'Alerte de départ', status: 'dispo' },
      { title: 'La liste des échéances à relancer', text: 'Chaque semaine, les abonnements qui arrivent à échéance, avec le message de renouvellement prêt.', feature: 'Module Sport', status: 'bientot' },
      { title: 'Créneaux avec capacité et réservation', text: 'Chaque créneau a sa capacité et chaque coach ses horaires. Les membres réservent leur place en ligne, sans appeler.', feature: 'Agenda et réservation', status: 'dispo' },
      { title: 'Le tableau de bord des membres', text: 'Membres actifs, nouveaux, à risque et chiffre d’affaires du mois, sans rien recompter.', feature: 'Tableau de bord', status: 'dispo' },
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
    slug: 'technique',
    name: 'Services techniques',
    short: 'Technique',
    icon: 'wrench',
    message: 'Vos clients reviennent pour l’entretien, au bon moment.',
    intro: 'Garages, auto-écoles, réparateurs, climatisation : vos clients reviennent quand on le leur rappelle. Menentia suit les échéances et prépare le rappel au bon moment.',
    trades: ['Garage', 'Auto-école', 'Climatisation', 'Électroménager', 'Informatique', 'Lavage auto'],
    rhythm: 'Entretien périodique',
    signal: 'Entretien dépassé sans rendez-vous',
    module: 'Rappel d’entretien, devis envoyé sur WhatsApp',
    problem: {
      headline: 'Vos clients reviennent quand on le leur rappelle. Sinon, ils vont au garage d’à côté.',
      text: 'Le travail est bien fait, mais le suivi repose sur la mémoire. L’historique d’un véhicule est dans un carnet, les devis se donnent de vive voix, et personne ne rappelle le client quand l’entretien arrive.',
    },
    difficulties: [
      { title: 'Des entretiens oubliés', text: 'La vidange est due, le client n’y pense pas et va là où il passe ce jour-là.', cost: 'Un client fidèle qui part chez un concurrent.' },
      { title: 'Des devis sans suite', text: 'Le devis est donné, puis plus de nouvelles. Personne ne relance.', cost: 'Des réparations qui ne sont jamais faites chez vous.' },
      { title: 'Un historique introuvable', text: 'Quelles pièces ont été changées sur ce véhicule, et quand ? Il faut fouiller les carnets.', cost: 'Du temps perdu et des erreurs de diagnostic.' },
      { title: 'Un atelier saturé puis vide', text: 'Tout le monde arrive le lundi matin. Le jeudi, l’atelier attend.', cost: 'Des clients qui patientent, puis des mécaniciens inoccupés.' },
    ],
    solutions: [
      { title: 'Le rappel d’entretien au bon moment', text: 'Menentia suit l’échéance de chaque client et prépare le rappel. Le rappel se transforme en rendez-vous.', feature: 'Module Technique', status: 'bientot' },
      { title: 'La relance des devis', text: 'Les devis partent sur WhatsApp, et les clients sans réponse sont listés pour être relancés.', feature: 'Module Technique', status: 'bientot' },
      { title: 'Une fiche par client et par véhicule', text: 'Chaque intervention, pièce et montant est enregistré sur la fiche du client.', feature: 'Fiche client 360°', status: 'dispo' },
      { title: 'Des rendez-vous planifiés', text: 'Un agenda par poste ou par mécanicien, et la réservation en ligne des créneaux libres.', feature: 'Agenda et réservation', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 15 vidanges oubliées par mois', lines: [['Vidange moyenne', '35 000 FCFA'], ['Clients qui ne reviennent pas', '15'], ['Mois', '1']], total: '525 000 FCFA partis chez un concurrent' },
    story: {
      before: 'Un client a fait sa vidange en mai. En octobre, il a roulé 5 000 km de plus sans revenir.',
      after: 'Son échéance apparaît dans la liste du jour. Le message part avec un créneau proposé : rendez-vous pris pour samedi.',
    },
    timeline: {
      without: [['Vidange', 'Mai'], ['Silence', 'Juillet'], ['Échéance passée', 'Octobre'], ['Silence', 'Novembre'], ['Garage concurrent', 'Décembre']],
      with: [['Vidange', 'Mai'], ['Historique noté', 'Mai'], ['Rappel d’entretien', 'Octobre'], ['Rendez-vous pris', 'Octobre'], ['Client fidèle', 'Octobre']],
    },
    chips: [['Vidange due', 'Toyota Hilux · 5 000 km'], ['Devis sans réponse', 'Relance prête · 3 jours']],
    file: { total: 160, toRecall: 41, unit: 'véhicules' },
    example: { client: 'Toyota Hilux, M. Ndong', service: 'Vidange', cycle: 'tous les 5 000 km', late: 'échéance dépassée' },
  },
  {
    slug: 'pro',
    name: 'Services professionnels',
    short: 'Pro',
    icon: 'briefcase',
    message: 'Chaque client suivi, aucun dossier oublié.',
    intro: 'Cabinets comptables et juridiques, consultants, centres de formation : peu de rendez-vous, mais des dossiers qui comptent. Menentia classe vos clients et vous rappelle ceux qui attendent.',
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
      { title: 'Le parcours Prospect, Client, À risque', text: 'Chaque contact a son étape. Ceux qui n’ont pas eu de suite remontent dans la liste du jour.', feature: 'Parcours client', status: 'dispo' },
      { title: 'Des relances planifiées', text: 'Les échéances de vos clients sont suivies, et le message de relance est prêt.', feature: 'Module Pro', status: 'bientot' },
      { title: 'Le suivi de dossier par étapes', text: 'Chaque dossier avance par étapes, avec ce qui manque, visible par toute l’équipe.', feature: 'Module Pro', status: 'bientot' },
      { title: 'Score et chiffre d’affaires par client', text: 'Vos clients classés par score et par chiffre d’affaires, pour savoir où mettre votre temps.', feature: 'Segmentation client', status: 'dispo' },
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
    slug: 'commerce',
    name: 'Commerce et distribution',
    short: 'Commerce',
    icon: 'store',
    message: 'Connaissez vos clients, écrivez à chacun ce qui lui parle.',
    intro: 'Boutiques, supermarchés, quincailleries, magasins de cosmétiques ou d’électronique, distributeurs et grossistes : vous avez déjà une base de clients, souvent des milliers de numéros. Même sans rendez-vous, Menentia l’analyse, la découpe en segments et vous aide à envoyer à chaque groupe la campagne qui lui correspond.',
    trades: ['Boutique', 'Supermarché', 'Quincaillerie', 'Cosmétiques', 'Électronique', 'Grossiste'],
    rhythm: 'Selon le panier : de la semaine au trimestre',
    signal: 'Le client n’achète plus depuis plus longtemps que d’habitude',
    module: 'Import des ventes, segments RFM, fidélité et campagnes ciblées',
    problem: {
      headline: 'Vous avez des milliers de clients, mais vous leur envoyez tous le même message.',
      text: 'Les tickets de caisse, les fichiers Excel et les contacts WhatsApp s’accumulent. La promotion part à tout le monde, au même moment, sans savoir qui achète beaucoup, qui achète rarement, ni qui a cessé de venir. Les meilleurs clients reçoivent les mêmes messages que les autres, et ceux qui s’éloignent ne sont jamais rappelés.',
    },
    difficulties: [
      { title: 'Une base client qui dort', text: 'Des milliers de numéros dans la caisse, dans Excel et dans le téléphone, sans historique lisible.', cost: 'Une richesse que personne n’exploite.' },
      { title: 'La même promotion pour tous', text: 'Le même message à tout le fichier : les fidèles se lassent, les autres l’ignorent.', cost: 'Des envois payés pour peu de retours.' },
      { title: 'Des clients qui partent sans bruit', text: 'Un client qui achetait chaque mois n’est plus revenu depuis trois mois, et personne ne l’a vu.', cost: 'On le remarque quand il achète déjà ailleurs.' },
      { title: 'Des décisions au jugé', text: 'Quels clients rapportent le plus ? Quel magasin, quel produit, quelle période ?', cost: 'Le budget marketing va au mauvais endroit.' },
    ],
    solutions: [
      { title: 'Votre historique importé en quelques minutes', text: 'Vos clients et leurs achats s’importent depuis Excel ou CSV : vous choisissez les colonnes, les doublons sont repérés, les numéros remis au bon format. Chaque client a sa fiche avec ses achats et ses dépenses.', feature: 'Import Excel et CSV', status: 'dispo' },
      { title: 'Une campagne adaptée à chaque groupe', text: 'Ciblez par étape du parcours, groupe, VIP ou genre, avec le nombre de destinataires affiché en direct. Avec votre compte WhatsApp Business API, l’envoi part par lots, avec le coût estimé avant et les statuts remis et lu après. Le ciblage direct par segment RFM arrive ensuite. Le programme de fidélité à points récompense vos meilleurs clients à chaque achat encaissé.', feature: 'Campagnes WhatsApp', status: 'dispo' },
      { title: 'L’alerte de départ', text: 'Menentia connaît le rythme d’achat de chaque client et le signale dès qu’il dépasse son délai habituel. Les clients à risque remontent dans la liste du jour, avec le message prêt à partir en un clic.', feature: 'Alerte de départ', status: 'dispo' },
      { title: '8 segments et un score par client', text: 'Champions, Clients fidèles, Potentiels, À risque, Perdus… recalculés chaque nuit selon la récence, la fréquence et le montant, avec l’action conseillée pour chacun, un score de 0 à 100 et vos VIP magasin par magasin. Chaque soir, le rapport arrive par email : chiffre d’affaires du jour, clients à risque et actions conseillées.', feature: 'Segmentation client', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 20 bons clients perdus par mois', lines: [['Panier moyen mensuel', '25 000 FCFA'], ['Clients partis sans relance', '20'], ['Mois', '1']], total: '500 000 FCFA de ventes perdues chaque mois' },
    story: {
      before: 'Une cliente achetait ses produits d’entretien chaque mois dans la boutique. Depuis juillet, plus rien, et la seule promotion reçue était la même que pour tout le monde.',
      after: 'Passée en segment « À risque » fin août, elle a reçu un message avec une remise sur ses produits habituels. Elle est revenue la semaine suivante.',
    },
    timeline: {
      without: [['Achat mensuel', 'Juin'], ['Dernier achat', 'Juillet'], ['Promo pour tous', 'Septembre'], ['Aucune réponse', 'Octobre'], ['Cliente perdue', 'Novembre']],
      with: [['Achat mensuel', 'Juin'], ['Dernier achat', 'Juillet'], ['Passée « À risque »', 'Août'], ['Offre ciblée', 'Août'], ['De retour', 'Septembre']],
    },
    chips: [['Campagne ciblée', 'Clients fidèles · coût estimé avant l’envoi'], ['Cliente À risque', '92 jours sans achat']],
    file: { total: 2400, toRecall: 180, unit: 'clients' },
    example: { client: 'Mme Ndong', service: 'Panier mensuel', cycle: 'chaque mois', late: '92 jours sans achat' },
    pricingNote: 'Pour un commerce, l’agenda et la réservation en ligne ne servent pas : vous utilisez le fichier client, l’import des ventes, les segments, la fidélité, les campagnes WhatsApp et le rapport du soir. Le prix reste celui de l’offre choisie.',
  },
];
