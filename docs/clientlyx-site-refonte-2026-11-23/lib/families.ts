// Les secteurs de ClientlyX (09/11/2026 ; Centre de formation le 10/11 ; Opticiens et métiers voisins le 11/11).
// Chaque page présente le problème, les difficultés concrètes, puis la solution
// ClientlyX, dans le même ordre que les difficultés.

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
  // Les 3 gains concrets pour le business, affichés sur l’accueil et la page du secteur.
  gains: string[];
};

export const families: Family[] = [
  {
    slug: 'beaute',
    name: 'Beauté et salons',
    short: 'Beauté',
    icon: 'scissors',
    message: 'Vos clientes reviennent, ClientlyX s’en occupe.',
    intro: 'Salons de coiffure, tresses, barbiers, onglerie, esthétique et spas : vos clientes reviennent toutes les 2 à 8 semaines. ClientlyX sait quand chacune devrait revenir, et vous aide à la faire revenir.',
    trades: ['Salon de coiffure', 'Barbier', 'Institut de beauté', 'Onglerie', 'Spa', 'Maquilleuse', 'Tresses et extensions', 'Esthéticienne', 'Centre de massage', 'Cils et sourcils', 'Soins capillaires et trichologie', 'Parfumerie et cosmétiques'],
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
      { title: 'L’alerte « il est temps de revenir »', text: 'ClientlyX connaît le rythme de chaque cliente et vous prévient dès qu’elle dépasse son délai. Vous la relancez en un clic, avec une offre si vous le souhaitez.', feature: 'Alerte de départ', status: 'dispo' },
      { title: 'Réservation en ligne et relances ciblées', text: 'Votre lien de réservation dans votre statut WhatsApp : les clientes voient vos créneaux libres et réservent seules, même à minuit. Pour remplir le mardi, relancez un segment précis.', feature: 'Réservation en ligne', status: 'dispo' },
      { title: 'Une fiche complète par cliente', text: 'Visites, prestations, dépenses, préférences et points de fidélité sur une seule page. Votre fichier Excel ou vos contacts s’importent en quelques minutes.', feature: 'Fiche client 360°', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 2 absences par semaine', lines: [['Prestation moyenne', '15 000 FCFA'], ['Absences par semaine', '2'], ['Semaines par mois', '4']], total: '120 000 FCFA perdus chaque mois' },
    story: {
      before: 'Mireille venait toutes les 5 semaines pour ses tresses. Après 44 jours sans nouvelles, personne au salon ne l’avait remarqué.',
      after: 'ClientlyX l’a signalée au 36e jour. La gérante lui a écrit avec une remise de 10 % ; Mireille a réservé pour le samedi suivant.',
    },
    timeline: {
      without: [['Visite', 'Semaine 0'], ['Silence', 'Semaine 5'], ['Oubliée', 'Semaine 7'], ['Silence', 'Semaine 9'], ['Partie ailleurs', 'Semaine 12']],
      with: [['Visite', 'Semaine 0'], ['Rappel la veille', 'Semaine 5'], ['Alerte de retard', 'Semaine 6'], ['Message avec offre', 'Semaine 6'], ['Revient au salon', 'Semaine 7']],
    },
    chips: [['Rappel envoyé', 'Tresses · mardi 10 h · confirmé'], ['Mireille O. à relancer', '9 jours après son délai habituel']],
    file: { total: 160, toRecall: 37, unit: 'clientes' },
    example: { client: 'Mireille O.', service: 'Tresses', cycle: 'toutes les 5 semaines', late: '9 jours de retard' },
    gains: ['Moins de rendez-vous oubliés grâce au rappel de la veille', 'Les habituées relancées avant qu’elles partent chez une concurrente', 'Les jours creux remplis par des relances ciblées'],
  },
  {
    slug: 'sante',
    name: 'Santé et bien-être',
    short: 'Santé',
    icon: 'eye',
    message: 'Un examen de vue chaque année, des lunettes toujours en stock.',
    intro: 'Opticiens, cabinets médicaux et dentaires, kinés, centres de bien-être. Exemple chez l’opticien : le client prend rendez-vous, passe son examen de vue, reçoit sa prescription, puis choisit monture, verres ou lentilles. ClientlyX relie le rendez-vous, le devis, le stock et le rappel de l’année suivante.',
    trades: ['Cabinet médical', 'Clinique', 'Cabinet dentaire', 'Opticien', 'Kinésithérapeute', 'Laboratoire d’analyses', 'Sage-femme et maternité', 'Pédiatre', 'Gynécologue', 'Psychologue', 'Nutritionniste', 'Centre d’imagerie', 'Ophtalmologue', 'Médecine traditionnelle', 'Pharmacie (RDV et conseils)'],
    rhythm: 'Un examen de vue tous les 12 à 24 mois',
    signal: 'Examen de vue d’il y a plus d’un an, ou devis de lunettes sans réponse',
    module: 'Rendez-vous d’examen, devis avec acompte, stock de montures et verres',
    problem: {
      headline: 'Le patient fait son examen, repart avec un devis… et ne revient jamais.',
      text: 'Chez l’opticien, tout se joue entre l’examen et la commande : le devis des verres reste sur le comptoir, l’acompte est noté sur un bout de papier, les montures manquent au mauvais moment, et personne ne rappelle le client l’année suivante.',
    },
    difficulties: [
      { title: 'Des devis de lunettes sans suite', text: 'Le client repart avec un devis à 175 000 FCFA pour réfléchir. Personne ne le relance.', cost: 'Une paire de lunettes perdue à chaque devis oublié.' },
      { title: 'Des acomptes mal suivis', text: 'Acompte versé, verres commandés, solde à encaisser au retrait : tout tient sur un cahier.', cost: 'Des soldes jamais encaissés.' },
      { title: 'Des ruptures de montures et de verres', text: 'Le modèle demandé n’est plus là, les lentilles mensuelles manquent en fin de mois.', cost: 'Le client va chez le concurrent d’en face.' },
      { title: 'Aucun rappel d’examen annuel', text: 'La vue change chaque année, surtout chez l’enfant. Mais personne ne rappelle.', cost: 'Le client oublie, ou va ailleurs.' },
    ],
    solutions: [
      { title: 'Le devis envoyé et accepté sur WhatsApp', text: 'Le devis part en un clic par WhatsApp. Le client l’accepte en ligne, et s’il ne répond pas, la relance est prête au 3e jour.', feature: 'Devis et factures', status: 'dispo' },
      { title: 'Acompte et solde suivis', text: 'Facture d’acompte à la commande, facture de solde au retrait : ClientlyX calcule le reste à payer et le montre sur la fiche client.', feature: 'Devis et factures', status: 'dispo' },
      { title: 'Le stock qui baisse tout seul', text: 'Chaque vente sort la monture ou les verres du stock. Une alerte vous prévient avant la rupture, avec les jours de stock restants.', feature: 'Stock', status: 'dispo' },
      { title: 'Le rappel de l’examen annuel', text: 'Un an après l’examen, le client apparaît dans « À faire » avec le message de rappel prêt.', feature: 'Relances WhatsApp', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 4 devis oubliés par mois', lines: [['Devis moyen', '120 000 FCFA'], ['Devis sans relance', '4'], ['Mois', '1']], total: '480 000 FCFA perdus chaque mois' },
    story: {
      before: 'Grâce passe son examen et repart avec un devis de lunettes progressives. Elle hésite, l’opticienne n’a pas son numéro sous la main.',
      after: 'Le devis part sur WhatsApp. Au 3e jour, ClientlyX propose la relance ; Grâce accepte en ligne et verse 50 % d’acompte par Airtel Money.',
    },
    timeline: {
      without: [['Examen de vue', 'Jour 0'], ['Devis papier', 'Jour 0'], ['Silence', 'Jour 7'], ['Oublié', 'Mois 1'], ['Lunettes achetées ailleurs', 'Mois 2']],
      with: [['Examen de vue', 'Jour 0'], ['Devis sur WhatsApp', 'Jour 0'], ['Relance', 'Jour 3'], ['Accepté + acompte', 'Jour 4'], ['Rappel examen', 'Mois 12']],
    },
    chips: [['Devis accepté en ligne', 'Lunettes progressives · 175 000 F'], ['Lentilles mensuelles', 'À réassortir · 3 en stock']],
    file: { total: 280, toRecall: 46, unit: 'clients' },
    example: { client: 'Grâce M.', service: 'Examen de vue', cycle: 'tous les 12 mois', late: '2 mois de retard' },
    pricingNote: 'Le stock des montures, verres et lentilles est inclus dès Solo.',
    gains: ['Plus de devis acceptés sur WhatsApp : lunettes, soins, traitements', 'Acomptes et soldes suivis jusqu’au dernier franc', 'Chaque patient rappelé pour son contrôle annuel, et plus de rupture de stock'],
  },

  {
    slug: 'sport',
    name: 'Fitness et bien-être',
    short: 'Fitness',
    icon: 'activity',
    message: 'Repérez les membres qui décrochent avant qu’ils partent.',
    intro: 'Salles de sport, coachs, yoga, danse et cours collectifs : un membre qui vient moins souvent est un abonnement qui ne sera pas renouvelé. ClientlyX le repère avant l’échéance.',
    trades: ['Salle de sport', 'Coach sportif', 'Centre de yoga', 'Studio de danse', 'Arts martiaux', 'CrossFit', 'Piscine et natation', 'Club de football', 'Tennis et padel', 'Boxe', 'Aquagym', 'Salle de pilates'],
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
      { title: 'L’alerte de baisse de fréquentation', text: 'ClientlyX compare la fréquentation de chaque membre à son habitude et vous prévient avant qu’il décroche.', feature: 'Alerte de départ', status: 'dispo' },
      { title: 'La liste des échéances à relancer', text: 'Chaque semaine, les abonnements qui arrivent à échéance, avec le message de renouvellement prêt.', feature: 'Module Sport', status: 'dispo' },
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
    gains: ['Les membres qui décrochent repérés avant l’échéance', 'Plus d’abonnements renouvelés, sans oubli', 'Des cours mieux remplis et des revenus visibles chaque mois'],
  },
  {
    slug: 'vehicules',
    name: 'Location de véhicules',
    short: 'Location',
    icon: 'route',
    message: 'Chaque demande suivie, chaque véhicule loué sans double réservation.',
    intro: 'Loueurs de voitures avec ou sans chauffeur, location pour événements et transferts aéroport, vente de véhicules d’occasion ou importés : ClientlyX suit la demande WhatsApp jusqu’au retour du véhicule, et fait revenir vos clients.',
    trades: ['Location de voitures', 'Location avec chauffeur', 'Location VIP', 'Transferts aéroport', 'Voitures de mariage et d’événements', 'Location de minibus et de bus', 'Location d’utilitaires', 'Location longue durée', 'Vente de véhicules d’occasion', 'Importateur de véhicules', 'Location de motos', 'Gestion de flotte incluse'],
    rhythm: 'Une location tous les 2 à 6 mois, plus les événements de l’année',
    signal: 'Devis sans réponse depuis 24 h, ou client d’événement sans nouvelle depuis 6 mois',
    module: 'Demandes, planning des véhicules, état des lieux, cautions, vente en plusieurs fois, gestion de flotte (entretien, assurance, visite technique)',
    problem: {
      headline: 'Les demandes arrivent sur WhatsApp… et personne ne sait quel véhicule est libre ni qui relancer.',
      text: 'Les prix se demandent en message privé, les réservations sont notées dans un cahier, les cautions sur un bout de papier. Résultat : des doubles réservations, des devis oubliés et des litiges au retour du véhicule.',
    },
    difficulties: [
      { title: 'Des demandes perdues', text: '« C’est combien pour samedi ? » arrive sur WhatsApp, Facebook ou au téléphone. Sans réponse rapide ni relance, le client loue ailleurs.', cost: 'Une location perdue à chaque devis oublié.' },
      { title: 'Un planning flou', text: 'On ne sait pas d’un coup d’œil quel véhicule est libre à quelle date.', cost: 'Doubles réservations ou refus inutiles.' },
      { title: 'Des paiements et cautions mal suivis', text: 'Acomptes, soldes, cautions à rendre, retenues pour dommages ou retard : tout tient sur papier.', cost: 'Des soldes jamais encaissés et des cautions contestées.' },
      { title: 'Des litiges au retour', text: 'Pas de photos ni de kilométrage au départ : la rayure ou le plein manquant devient la parole de l’un contre l’autre.', cost: 'Des réparations à vos frais et un client mécontent.' },
    ],
    solutions: [
      { title: 'Chaque demande suivie et relancée', text: 'La demande devient une fiche en 30 secondes : dates, véhicule, avec ou sans chauffeur, source. Sans réponse au devis, la relance WhatsApp est prête à 24 h puis à 72 h.', feature: 'Relances WhatsApp', status: 'dispo' },
      { title: 'Un planning sans double réservation', text: 'Le planning des véhicules par jour et par semaine bloque une réservation sur un véhicule déjà pris. Confirmation et rappel WhatsApp la veille, avec le lieu de prise en charge.', feature: 'Planning des véhicules', status: 'dispo' },
      { title: 'Acompte, caution et solde suivis', text: 'Devis puis contrat en un clic, acompte et caution enregistrés, reste à payer visible sur la fiche. Pour la vente, paiement en plusieurs fois avec rappels d’échéance.', feature: 'Devis et factures', status: 'dispo' },
      { title: 'L’état des lieux sur le téléphone', text: 'Photos, kilométrage et carburant au départ et au retour. La caution est rendue ou retenue avec un motif, et le reçu part sur WhatsApp.', feature: 'État des lieux', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 5 demandes non relancées par mois', lines: [['Location moyenne', '90 000 FCFA'], ['Demandes sans relance', '5'], ['Mois', '1']], total: '450 000 FCFA perdus chaque mois' },
    story: {
      before: 'Une entreprise demande deux véhicules avec chauffeur pour un séminaire. Le message reste sans suite pendant deux jours, et elle réserve chez un concurrent.',
      after: 'La demande est enregistrée, le devis part sur WhatsApp et la relance est prête le lendemain. Le client confirme, verse l’acompte par Mobile Money, et reçoit le rappel la veille.',
    },
    timeline: {
      without: [['Demande WhatsApp', 'Jour 0'], ['Prix donné', 'Jour 1'], ['Silence', 'Jour 3'], ['Oubliée', 'Semaine 2'], ['Louée ailleurs', 'Semaine 2']],
      with: [['Demande enregistrée', 'Jour 0'], ['Devis sur WhatsApp', 'Jour 0'], ['Relance', 'Jour 1'], ['Acompte reçu', 'Jour 2'], ['Rappel la veille', 'Jour J-1']],
    },
    chips: [['Devis accepté', '2 véhicules avec chauffeur · 3 jours'], ['Caution à rendre', 'Toyota Prado · retour aujourd’hui']],
    file: { total: 320, toRecall: 38, unit: 'clients' },
    example: { client: 'Société M.', service: 'Location avec chauffeur', cycle: 'tous les 3 mois', late: '1 mois de retard' },
    pricingNote: 'Location et vente incluses. Le tableau de bord montre l’occupation et le chiffre d’affaires par véhicule.',
    gains: ['Plus de demandes WhatsApp transformées en locations grâce aux relances', 'Zéro double réservation et des cautions suivies jusqu’au dernier franc', 'Vos clients d’événements et vos entreprises qui reviennent chaque saison'],
  },

  {
    slug: 'formation',
    name: 'Centre de formation',
    short: 'Formation',
    icon: 'book',
    message: 'Chaque apprenant suivi, de l’inscription à l’examen.',
    intro: 'Auto-écoles, formation professionnelle, langues, informatique, soutien scolaire : ClientlyX suit chaque demande jusqu’à l’inscription, puis chaque apprenant, ses heures, ses tranches et ses examens, et vous prévient dès qu’il décroche.',
    trades: ['Centre de formation professionnelle', 'Auto-école', 'École de langues', 'Soutien scolaire', 'École privée', 'Crèche et garderie', 'Centre informatique', 'École de musique', 'Formation en ligne', 'Préparation aux concours', 'École de couture', 'École de cuisine'],
    rhythm: 'Plusieurs leçons par semaine, sur 1 à 6 mois',
    signal: 'Plus de leçon depuis 14 jours, ou une tranche en retard',
    module: 'Suivi des apprenants, formules en plusieurs tranches, véhicules et salles',
    problem: {
      headline: 'Les inscriptions arrivent par WhatsApp. Puis les tranches tardent et les apprenants décrochent.',
      text: 'Dans une auto-école, les demandes arrivent après une pub, les inscriptions sont notées dans un cahier, les tranches se suivent de tête et le planning des leçons tient dans le téléphone des moniteurs. Un élève qui ne vient plus, ou qui ne paie pas sa deuxième tranche, passe inaperçu.',
    },
    difficulties: [
      { title: 'Des demandes jamais transformées', text: 'Une personne demande le prix du permis après une pub. On répond, puis on l’oublie.', cost: 'Une inscription perdue à chaque relance oubliée.' },
      { title: 'Des tranches qui tardent', text: 'Le permis se paie en trois fois. Personne ne sait qui est en retard sur la deuxième tranche.', cost: 'De la trésorerie qui manque en fin de mois.' },
      { title: 'Des apprenants qui décrochent', text: 'Un élève manque deux leçons, puis ne revient plus.', cost: 'Un apprenant qui abandonne ne recommande pas votre école.' },
      { title: 'Aucune vue sur la progression', text: 'Combien d’heures faites ? Qui est prêt pour l’examen ? Il faut demander à chaque moniteur.', cost: 'Des sessions d’examen mal préparées.' },
    ],
    solutions: [
      { title: 'Chaque demande devient un prospect', text: 'Le premier message crée la fiche avec la source de la pub. Si la personne ne s’inscrit pas, la relance est prête.', feature: 'Suivi des prospects', status: 'dispo' },
      { title: 'Les formules en plusieurs tranches', text: 'Chaque échéance a sa date. Les tranches en retard apparaissent dans « À faire », avec le message de rappel prêt.', feature: 'Formules et tranches', status: 'dispo' },
      { title: 'L’alerte de décrochage', text: 'Plus de leçon depuis 14 jours, retard sur le rythme prévu ou deux examens échoués : l’apprenant passe dans la liste à relancer.', feature: 'Alerte de décrochage', status: 'dispo' },
      { title: 'La progression de chaque apprenant', text: 'Heures faites, progression en %, dernière et prochaine leçon, prochain examen. Les heures se comptent seules, et un véhicule déjà pris ne peut pas être réservé deux fois.', feature: 'Suivi des apprenants', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 5 tranches en retard', lines: [['Tranche moyenne', '80 000 FCFA'], ['Tranches en retard', '5'], ['Mois', '1']], total: '400 000 FCFA qui manquent chaque mois' },
    story: {
      before: 'Brice s’inscrit au permis en septembre. Après 6 leçons, il ne vient plus et sa deuxième tranche n’est pas payée. Personne ne l’appelle.',
      after: 'Au 14e jour sans leçon, ClientlyX le signale dans « À faire ». Le directeur lui écrit, Brice reprend ses leçons et règle sa tranche.',
    },
    timeline: {
      without: [['Inscription', 'Semaine 0'], ['6 leçons', 'Semaine 3'], ['Absent', 'Semaine 4'], ['Tranche impayée', 'Semaine 6'], ['Abandon', 'Semaine 8']],
      with: [['Inscription', 'Semaine 0'], ['6 leçons', 'Semaine 3'], ['Alerte de décrochage', 'Semaine 5'], ['Message du directeur', 'Semaine 5'], ['Reprend et paie', 'Semaine 6']],
    },
    chips: [['Brice N. · décroche', 'Pas de leçon depuis 14 jours'], ['13 tranches en retard', 'Messages de rappel prêts']],
    file: { total: 40, toRecall: 8, unit: 'apprenants' },
    example: { client: 'Brice N.', service: 'Permis B', cycle: '2 leçons par semaine', late: '14 jours sans leçon' },
    gains: ['Les demandes venues des pubs suivies jusqu’à l’inscription', 'Les tranches en retard relancées à temps', 'Chaque apprenant suivi jusqu’à l’examen, sans abandon silencieux'],
  },
  {
    slug: 'pro',
    name: 'Services pro et agences',
    short: 'Pro',
    icon: 'briefcase',
    message: 'Aucun devis sans relance, aucun prospect oublié.',
    intro: 'Cabinets comptables, agences immobilières, agences de communication, informatique, conseil, assurances : chaque prospect qui écrit après une pub est suivi par étapes jusqu’à la signature, et vous voyez le travail de chaque commercial.',
    trades: ['Avocat', 'Notaire', 'Expert-comptable', 'Huissier', 'Agence de communication', 'Agence marketing digital', 'Photographe et shooting', 'Vidéaste', 'Événementiel', 'Consultant', 'Agence de voyage', 'Cabinet RH et recrutement', 'Architecte', 'Agence immobilière', 'Imprimerie', 'Traducteur'],
    rhythm: 'Un cycle de vente de quelques jours à quelques semaines',
    signal: 'Devis envoyé sans réponse depuis 3 jours',
    module: 'Suivi des prospects par étapes, relance du devis, commerciaux',
    problem: {
      headline: 'Vos prospects écrivent, vous envoyez un devis, puis plus de nouvelles.',
      text: 'Chaque commercial garde ses contacts dans son téléphone et le suivi tient dans un fichier Excel rarement à jour. Les devis ne sont pas relancés, et le gérant ne sait pas qui fait quoi.',
    },
    difficulties: [
      { title: 'Des devis jamais relancés', text: 'Le devis part, le prospect ne répond pas, personne ne relance.', cost: 'Des contrats signés chez un concurrent.' },
      { title: 'Des prospects oubliés', text: 'Les messages arrivent après une pub et se perdent au milieu des discussions.', cost: 'Un budget pub payé pour rien.' },
      { title: 'Des contacts dans les téléphones', text: 'Un commercial part, et ses prospects partent avec lui.', cost: 'Un fichier client à reconstruire.' },
      { title: 'Aucune vue sur l’équipe', text: 'Combien de prospects, de devis, de signatures par commercial ? Personne ne sait.', cost: 'Des objectifs impossibles à piloter.' },
    ],
    solutions: [
      { title: 'Chaque message devient un prospect', text: 'Le premier message crée la fiche avec la source de la pub, et le prospect est attribué à un commercial.', feature: 'Fiche automatique', status: 'dispo' },
      { title: 'La relance du devis à J+3', text: 'Trois jours sans réponse après un devis : une tâche et un message de relance prêt à envoyer.', feature: 'Relances', status: 'dispo' },
      { title: 'Le suivi par étapes, en glisser-déposer', text: 'À contacter, en discussion, devis envoyé, gagné ou perdu : toute l’équipe voit où en est chaque prospect.', feature: 'Suivi des prospects', status: 'dispo' },
      { title: 'Les commerciaux et le rapport du soir', text: 'Objectifs, taux de signature et primes par commercial, résumés chaque soir pour le gérant.', feature: 'Commerciaux', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûte 1 contrat perdu par mois', lines: [['Contrat moyen', '300 000 FCFA'], ['Contrats perdus faute de relance', '1'], ['Mois', '1']], total: '300 000 FCFA envolés chaque mois' },
    story: {
      before: 'Une PME demande un devis de comptabilité sur WhatsApp. Le devis part un lundi, puis plus rien.',
      after: 'Le jeudi, ClientlyX crée la tâche « Relancer le devis » avec le message prêt. Le commercial l’envoie, rendez-vous le lendemain, contrat signé.',
    },
    timeline: {
      without: [['Message après la pub', 'Jour 1'], ['Devis envoyé', 'Jour 2'], ['Silence', 'Jour 5'], ['Oublié', 'Jour 15'], ['Signé ailleurs', 'Jour 30']],
      with: [['Message après la pub', 'Jour 1'], ['Fiche et prospect créés', 'Jour 1'], ['Devis envoyé', 'Jour 2'], ['Relance à J+3', 'Jour 5'], ['Contrat signé', 'Jour 8']],
    },
    chips: [['Relance prête', 'Devis sans réponse depuis 3 jours'], ['SARL Akanda · Devis envoyé', 'Source : pub Facebook']],
    file: { total: 160, toRecall: 24, unit: 'prospects' },
    example: { client: 'SARL Akanda Services', service: 'Tenue comptable', cycle: 'devis sous 48 h', late: 'devis sans réponse' },
    gains: ['Chaque devis relancé à J+3, automatiquement', 'Aucun prospect venu d’une pub laissé sans réponse', 'Le travail de chaque commercial visible chaque soir'],
  },
  {
    slug: 'commerce',
    name: 'Boutiques et retail',
    short: 'Retail',
    icon: 'store',
    message: 'Connaissez vos clients, faites-les revenir en boutique.',
    intro: 'Boutiques de mode, chaînes de magasins, enseignes multimarques, cosmétiques et accessoires : vos ventes passent en caisse, mais vos clients restent inconnus. ClientlyX met un nom, un numéro et un historique derrière chaque ticket.',
    trades: ['Boutique de mode', 'Chaîne de magasins', 'Grand magasin multimarque', 'Chaussures et sneakers', 'Cosmétiques et parfumerie', 'Accessoires et bijoux', 'Prêt-à-porter enfant', 'Électroménager et high-tech', 'Décoration et maison', 'Épicerie fine'],
    rhythm: 'Un achat toutes les 4 à 12 semaines',
    signal: 'Le client fidèle n’est pas revenu depuis son rythme habituel',
    module: 'Import des ventes de la caisse, segments RFM, fidélité par enseigne, vente croisée entre marques',
    problem: {
      headline: 'Vous vendez tous les jours… sans savoir qui sont vos meilleurs clients.',
      text: 'Les tickets de caisse donnent le chiffre du jour, pas le nom des clients. Les promotions partent à tout le monde sur WhatsApp, sans ciblage, et les meilleurs clients partent chez le concurrent sans que personne ne le voie.',
    },
    difficulties: [
      { title: 'Des clients anonymes', text: 'La caisse enregistre des montants, pas des personnes. Impossible de savoir qui revient et qui ne revient plus.', cost: 'Vos meilleurs clients ne sont jamais remerciés.' },
      { title: 'Des promotions envoyées à tout le monde', text: 'Le même message à 800 contacts : la plupart ne sont pas concernés, certains se désabonnent.', cost: 'Peu de ventes et une image qui s’abîme.' },
      { title: 'Des clients qui partent sans bruit', text: 'Un client qui achetait chaque mois n’est plus venu depuis un trimestre. Personne ne l’a remarqué.', cost: 'Le chiffre baisse sans explication.' },
      { title: 'Des enseignes qui ne se parlent pas', text: 'Un fidèle d’une boutique n’a jamais mis les pieds dans l’autre enseigne du groupe.', cost: 'Des ventes croisées jamais faites.' },
    ],
    solutions: [
      { title: 'Chaque ticket rattaché à un client', text: 'Importez le fichier de votre caisse : chaque vente rejoint la fiche du client par son numéro, avec ses familles d’articles préférées.', feature: 'Import des ventes', status: 'dispo' },
      { title: 'Des relances ciblées', text: 'Segments RFM prêts à l’emploi : fidèles, à risque, endormis. Chaque liste a son message WhatsApp prêt, avec le consentement respecté.', feature: 'Segments RFM', status: 'dispo' },
      { title: 'L’alerte de départ', text: 'ClientlyX repère le client qui dépasse son rythme d’achat habituel et vous propose de le relancer avant qu’il soit perdu.', feature: 'Alerte de départ', status: 'dispo' },
      { title: 'Fidélité et vente croisée', text: 'Points et paliers par enseigne, et la liste des fidèles d’une marque jamais venus dans une autre.', feature: 'Fidélité par enseigne', status: 'dispo' },
    ],
    calc: { label: 'Ce que coûtent 20 clients fidèles perdus par trimestre', lines: [['Panier moyen', '35 000 FCFA'], ['Achats par trimestre', '3'], ['Clients perdus', '20']], total: '2 100 000 FCFA perdus chaque trimestre' },
    story: {
      before: 'Grâce achetait une tenue chaque mois dans la boutique. Après 3 mois sans achat, personne ne s’en était aperçu.',
      after: 'ClientlyX l’a signalée comme « à risque ». La boutique lui a envoyé la nouvelle collection avec un bon de fidélité ; elle est revenue la semaine suivante.',
    },
    timeline: {
      without: [['Achat', 'Mois 0'], ['Achat', 'Mois 1'], ['Silence', 'Mois 2'], ['Oubliée', 'Mois 3'], ['Partie ailleurs', 'Mois 4']],
      with: [['Achat', 'Mois 0'], ['Achat', 'Mois 1'], ['Alerte de retard', 'Mois 2'], ['Offre ciblée', 'Mois 2'], ['Revient en boutique', 'Mois 2']],
    },
    chips: [['Client à risque', 'Grâce M. · 92 jours sans achat'], ['Vente croisée', '38 fidèles Mango jamais venus chez Carré']],
    file: { total: 1200, toRecall: 140, unit: 'clients' },
    example: { client: 'Grâce M.', service: 'Prêt-à-porter', cycle: 'tous les mois', late: '2 mois de retard' },
    pricingNote: 'Ni stock ni catalogue à gérer : ClientlyX lit le fichier de votre caisse.',
    gains: ['Chaque ticket rattaché à un client connu', 'Des promotions ciblées qui vendent, au lieu d’arroser tout le monde', 'Les clients à risque relancés avant de partir chez le concurrent'],
  }
];

// Autres métiers de services compatibles sans réglage particulier : ils prennent
// les modules du secteur le plus proche (liste inspirée des métiers de services d’Odoo).
export const otherTrades: { family: string; label: string; trades: string[] }[] = [
  { family: 'beaute', label: 'Beauté, coiffure et esthétique', trades: ['Salons de coiffure', 'Barbiers', 'Instituts de beauté', 'Manucure et pédicure', 'Spas et massages', 'Maquillage', 'Tatouage et piercing'] },
  { family: 'sante', label: 'Santé et bien-être', trades: ['Cabinets médicaux', 'Cliniques privées', 'Cabinets dentaires', 'Kinésithérapie', 'Laboratoires', 'Opticiens', 'Nutrition', 'Psychologues'] },
  { family: 'sport', label: 'Sport et fitness', trades: ['Salles de sport', 'Coaching sportif', 'Yoga et Pilates', 'Clubs sportifs', 'Danse', 'Piscines'] },
  { family: 'formation', label: 'Éducation et formation', trades: ['Formation professionnelle', 'Écoles privées', 'Informatique', 'Langues', 'Auto-écoles', 'Soutien scolaire', 'Coaching professionnel'] },
  { family: 'pro', label: 'Agences et services professionnels', trades: ['Communication', 'Marketing et digital', 'Agences web', 'Conseil', 'Comptables', 'Juridique', 'Recrutement et RH', 'Bureaux d’études'] },
  { family: 'pro', label: 'Immobilier et habitat', trades: ['Agences immobilières', 'Gestion locative', 'Promoteurs', 'Architecture et décoration', 'Construction et rénovation', 'Aménagement intérieur'] },
  { family: 'vehicules', label: 'Automobile et maintenance', trades: ['Garages', 'Centres de lavage', 'Contrôle technique', 'Pneumatiques', 'Location et vente de véhicules', 'Maintenance informatique'] },
  { family: 'pro', label: 'Services à domicile et techniques', trades: ['Nettoyage', 'Plomberie', 'Électricité', 'Climatisation', 'Jardinage', 'Désinfection', 'Dépannage'] },
  { family: 'pro', label: 'Événementiel et prestations', trades: ['Photographes et vidéastes', 'Wedding planners', 'Décoration', 'Location de matériel', 'DJ et animation', 'Conférences'] },
];


// Les 5 secteurs du lancement du 15/12/2026 (décision du 23/11), dans l’ordre d’affichage.
// Retail mis de côté pour l’instant (23/11) : sa page /activites/commerce reste en ligne, hors menu.
export const launchSlugs = ['beaute', 'sport', 'pro', 'vehicules'];
export const launchFamilies: Family[] = launchSlugs.map((slug) => families.find((f) => f.slug === slug)!);

// Message clé de chaque secteur (plan marketing du 13/11).
export const launchPitch: Record<string, string> = {
  beaute: 'Plus de RDV, moins d’absences, des clientes qui reviennent.',
  sport: 'Plus de séances réservées, moins d’abonnés qui décrochent.',
  pro: 'Suivez chaque prospect jusqu’à la signature.',
  commerce: 'Analysez vos données clients, faites revenir vos acheteurs.',
  vehicules: 'Chaque demande relancée, chaque véhicule loué sans double réservation.',
};
