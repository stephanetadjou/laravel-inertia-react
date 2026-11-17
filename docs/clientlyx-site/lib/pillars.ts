// Les 4 piliers de ClientlyX (accueil) : une page chacun, /piliers/<slug>.
// Uniquement des fonctions réellement présentes dans l'application.

export type PillarPage = {
  slug: string;
  tag: string;
  icon: string;
  title: string;
  hook: string;
  problem: { headline: string; text: string };
  features: { title: string; text: string }[];
  sectors: { slug: string; label: string }[];
};

export const pillarPages: PillarPage[] = [
  {
    slug: 'ai-powered-crm',
    tag: 'AI-Powered CRM',
    icon: 'users',
    title: 'Connaître chaque client',
    hook: 'Une seule fiche par client, qui se remplit toute seule à chaque rendez-vous, message et achat. ClientlyX vous dit qui revient, qui part et qui mérite une attention.',
    problem: {
      headline: 'Vos clients sont éparpillés entre le téléphone, le cahier et les tableurs.',
      text: 'Personne ne sait qui est un bon client, qui n’est plus venu depuis trois mois ni qui a déjà été relancé. Les clients partent sans bruit, et on s’en rend compte trop tard.',
    },
    features: [
      { title: 'La fiche client unique', text: 'Une fiche par personne pour toute l’entreprise, même avec plusieurs établissements ou enseignes : coordonnées, historique des visites, achats, rendez-vous, messages et consentement WhatsApp.' },
      { title: 'Le parcours client automatique', text: 'Contact, prospect, nouveau client, client actif, à risque, inactif : l’étape se calcule toute seule chaque nuit, sans saisie, avec l’historique des changements.' },
      { title: 'Les segments RFM', text: 'Récence, fréquence et montant : vos clients sont rangés en segments clairs (champions, fidèles, à réveiller…), avec des règles que vous pouvez ajuster.' },
      { title: 'Le score et l’alerte de départ', text: 'Chaque fiche reçoit un score de 0 à 100 et un risque de départ. Les clients qui décrochent remontent dans « À faire » avant qu’il ne soit trop tard.' },
      { title: 'Les clients VIP', text: 'Les meilleurs acheteurs de chaque établissement sont repérés automatiquement, selon des seuils que vous choisissez.' },
      { title: 'Groupes et ciblage', text: 'Filtrez par étape, segment, établissement, achats, famille de produits ou critère métier, puis enregistrez le groupe pour une relance ou une campagne.' },
      { title: 'Import et doublons', text: 'Importez votre fichier Excel : les numéros sont normalisés, les doublons détectés, et deux fiches de la même personne peuvent être fusionnées.' },
    ],
    sectors: [
      { slug: 'beaute', label: 'Beauté et salons' },
      { slug: 'sante', label: 'Santé et bien-être' },
      { slug: 'pro', label: 'Services pro et agences' },
      { slug: 'vehicules', label: 'Location et vente de véhicules' },
    ],
  },
  {
    slug: 'dynamic-booking',
    tag: 'Dynamic Booking',
    icon: 'calendar',
    title: 'Remplir votre agenda',
    hook: 'Vos clients réservent seuls, 24 h sur 24, et reçoivent leur rappel WhatsApp la veille. Vous voyez les créneaux vides à remplir avant qu’ils ne soient perdus.',
    problem: {
      headline: 'Les rendez-vous se prennent par message, se notent à la main… et certains clients ne viennent pas.',
      text: 'Les doubles réservations, les oublis et les créneaux vides coûtent chaque semaine du chiffre d’affaires, sans que personne ne le mesure.',
    },
    features: [
      { title: 'La réservation en ligne', text: 'Une page de réservation à votre nom, sans compte à créer : le client choisit la prestation, l’intervenant (ou « peu importe ») et un créneau vraiment libre.' },
      { title: 'L’agenda par établissement et par intervenant', text: 'Horaires de chacun, pause du midi, jours fériés et congés : seuls les créneaux disponibles sont proposés, et jamais deux fois le même.' },
      { title: 'Le rappel WhatsApp de la veille', text: 'Un message la veille avec les boutons « Je confirme » et « Je décale », pour réduire les rendez-vous manqués.' },
      { title: 'Cours et séances de groupe', text: 'Cours réguliers avec places, inscrits et appel des présences, annulations et rattrapages.' },
      { title: 'Salles, matériel et véhicules', text: 'Une ressource n’est jamais réservée deux fois en même temps. Pour les loueurs : réservations par véhicule, acompte, caution et état des lieux au départ et au retour.' },
      { title: 'Les créneaux à remplir', text: 'Le moteur d’occupation repère les heures creuses, les cours peu remplis et les véhicules immobilisés, et les met dans « À faire ».' },
    ],
    sectors: [
      { slug: 'beaute', label: 'Beauté et salons' },
      { slug: 'sante', label: 'Santé et bien-être' },
      { slug: 'sport', label: 'Sport et fitness' },
      { slug: 'formation', label: 'Éducation et formation' },
      { slug: 'vehicules', label: 'Location et vente de véhicules' },
    ],
  },
  {
    slug: 'growth-marketing',
    tag: 'Growth Marketing',
    icon: 'message',
    title: 'Faire revenir vos clients',
    hook: 'Chaque jour, ClientlyX vous dit qui relancer et avec quel message. Sur WhatsApp, le canal que vos clients lisent vraiment.',
    problem: {
      headline: 'Vos clients satisfaits ne reviennent pas… parce que personne ne les relance.',
      text: 'Les campagnes partent à tout le monde, sans ciblage ni consentement, et les clients endormis ne sont jamais réveillés.',
    },
    features: [
      { title: 'Les actions du jour', text: 'La liste des clients à relancer aujourd’hui (absents depuis longtemps, devis sans réponse, échéances à payer), avec un message prêt à envoyer par lien WhatsApp.' },
      { title: 'Les campagnes WhatsApp ciblées', text: 'Avec l’API officielle de WhatsApp : un modèle approuvé, un groupe ciblé, un envoi par lots, les statuts remis et lus. Seuls les clients qui ont donné leur accord reçoivent les messages marketing.' },
      { title: 'La relance au bon rythme', text: 'Rappel du prochain entretien, du contrôle ou de la prochaine coupe selon le délai de chaque prestation ; devis relancés à 24 h puis 72 h.' },
      { title: 'Le programme de fidélité', text: 'Des points à chaque achat, des paliers et des récompenses échangées depuis la fiche client, par enseigne si besoin.' },
      { title: 'Les réponses automatiques', text: 'Message d’accueil, message hors horaires et menu à boutons (prix, horaires, rendez-vous, parler à quelqu’un). En option, des agents IA répondent aux questions.' },
      { title: 'Le suivi de vos publicités', text: 'Chaque client venu d’une publicité « Click to WhatsApp » garde sa source : vous voyez ce que rapporte chaque campagne.' },
    ],
    sectors: [
      { slug: 'beaute', label: 'Beauté et salons' },
      { slug: 'sport', label: 'Sport et fitness' },
      { slug: 'pro', label: 'Services pro et agences' },
      { slug: 'vehicules', label: 'Location et vente de véhicules' },
    ],
  },
  {
    slug: 'advanced-analytics',
    tag: 'Advanced Analytics',
    icon: 'chart',
    title: 'Décider avec vos chiffres',
    hook: 'Vos chiffres utiles, sans tableur : ce qui rapporte, ce qui baisse et les clients à surveiller. Et un rapport qui arrive tout seul chaque soir.',
    problem: {
      headline: 'Le chiffre d’affaires se découvre en fin de mois, sans savoir d’où il vient.',
      text: 'Impossible de comparer deux établissements, de savoir quelle prestation rapporte vraiment ni quels clients sont en train de partir.',
    },
    features: [
      { title: 'L’accueil en 4 chiffres', text: 'Les chiffres clés de votre métier dès l’ouverture : rendez-vous du jour, clients à relancer, chiffre d’affaires, et ce qu’il faut optimiser.' },
      { title: 'Le rapport du soir', text: 'Chaque soir, le dirigeant reçoit le bilan de la journée : ventes, rendez-vous, nouveaux clients et relances faites.' },
      { title: 'Le pilotage', text: 'Six tableaux filtrables par période, établissement, segment ou famille, avec la comparaison à la période précédente et à l’année dernière.' },
      { title: 'Les rapports PDF', text: 'Rapport de direction, fidélité, établissement, relance par segment et fiche client, à télécharger ou à recevoir chaque mois.' },
      { title: 'L’analyse commerciale', text: 'Importez les ventes de votre caisse : chiffre d’affaires net, panier moyen, familles de produits, clients multi-enseignes et vente croisée.' },
      { title: 'Les tableaux métier', text: 'Occupation et chiffre d’affaires par véhicule pour les loueurs, suivi des apprenants pour les écoles, abonnés actifs pour le sport.' },
    ],
    sectors: [
      { slug: 'pro', label: 'Services pro et agences' },
      { slug: 'vehicules', label: 'Location et vente de véhicules' },
      { slug: 'formation', label: 'Éducation et formation' },
      { slug: 'sante', label: 'Santé et bien-être' },
    ],
  },
];
