// Base de connaissances de l’assistant du site, construite à partir du contenu
// du site lui-même : un prix ou une fonction changé dans lib/ change aussi les
// réponses. Publiée en /assistant/kb.json, elle sert à la fois au service IA
// (services/assistant) et aux réponses de secours du navigateur.

import { brand, featureGroups, families, links, objections, offers, plans, pricingNote, WHATSAPP_DISPLAY } from './site';
import { cahier, daily, steps } from './cahier';
import { faq as whatsappFaq, rules as whatsappRules } from './whatsapp';

export type Answer = { id: string; q: string; keys: string[]; a: string; links?: [string, string][] };

const tag = (s: 'dispo' | 'bientot') => (s === 'dispo' ? 'disponible' : 'bientôt, pas encore disponible');
const feature = (id: string) => featureGroups.find((g) => g.id === id)!;
const items = (id: string) => feature(id).items.map((i) => `${i.title}${i.status === 'bientot' ? ' (bientôt)' : ''}`).join(', ');

const planLine = (p: (typeof plans)[number]) =>
  `${p.name} : ${p.price} FCFA par mois au Gabon (${p.priceCm} FCFA au Cameroun), pour ${p.who.charAt(0).toLowerCase() + p.who.slice(1)}. Comprend : ${p.features.join(', ')}.`;

const demo: [string, string] = ['Réserver une démonstration', '/contact'];
const trial: [string, string] = ['Commencer l’essai gratuit', links.signup];

export const knowledge = [
  `# ${brand.name}`,
  brand.description,
  `Promesse : « ${brand.promise} » Signature : ${brand.signature}.`,
  'Menentia est une application web et mobile pour les PME d’Afrique francophone qui travaillent sur rendez-vous. Elle ne cible pas un secteur mais un problème : mieux communiquer avec ses clients (surtout par WhatsApp), mieux les comprendre (segments RFM, score, alerte de départ) et les suivre de bout en bout.',
  'Lancement à Libreville (Gabon) le 15 décembre 2026. Le Cameroun suit. Siège : Libreville, Gabon.',
  `Liens : essai gratuit ${links.signup} · connexion ${links.login} · démonstration https://menentia.com/contact · tarifs https://menentia.com/tarifs · guide WhatsApp https://menentia.com/whatsapp · fonctionnalités https://menentia.com/fonctionnalites · marketplace https://menentia.com/marketplace. WhatsApp commercial : ${WHATSAPP_DISPLAY.includes('X') ? 'numéro pas encore publié, passer par le formulaire de démonstration' : WHATSAPP_DISPLAY}.`,

  '## Tarifs',
  ...plans.map((p) => `- ${planLine(p)}`),
  ...offers.map(([, t, d]) => `- ${t} : ${d}.`),
  `- ${pricingNote}`,
  '- Pas d’engagement. Paiement par Mobile Money (Airtel Money, Moov Money, MTN, Orange Money).',

  '## Fonctionnalités (statut réel dans l’application)',
  ...featureGroups.flatMap((g) => [
    `### ${g.title}`,
    g.lead,
    ...g.items.map((i) => `- ${i.title} [${tag(i.status)}] : ${i.text}`),
  ]),

  `## Métiers servis (${families.length} familles)`,
  'Menentia sert aussi le commerce et la distribution, sans rendez-vous : l’agenda et la réservation en ligne n’y servent pas. On y utilise l’import de la base clients et de l’historique d’achats, les segments RFM et le score, l’alerte de départ, la fidélité, les campagnes WhatsApp ciblées par groupe et le rapport du soir.',
  ...families.flatMap((f) => [
    `### ${f.name} (/activites/${f.slug})`,
    `Métiers : ${f.trades.join(', ')}. Rythme de retour typique : ${f.rhythm}. Signal d’alerte : ${f.signal}. Module métier : ${f.module}.`,
    `Problème : ${f.problem.headline} ${f.problem.text}`,
    ...f.difficulties.map((d, i) => `- Difficulté « ${d.title} » : ${d.text} Réponse Menentia : ${f.solutions[i].title} [${tag(f.solutions[i].status)}] : ${f.solutions[i].text}`),
  ]),

  '## WhatsApp',
  'Deux modes. Sans l’API (mode par défaut) : Menentia prépare le message et le gérant l’envoie en un clic depuis le WhatsApp de son téléphone. Avec l’API officielle WhatsApp Business de Meta (compte du client relié à Menentia) : campagnes par lots, modèles validés par Meta, statuts remis et lu, coût estimé avant l’envoi.',
  ...whatsappRules.map((r) => `- Règle « ${r.t} » : ${r.d}`),
  ...whatsappFaq.map((f) => `- Q : ${f.q} R : ${f.a}`),

  '## Objections fréquentes',
  ...objections.map((o) => `- « ${o.q} » ${o.a}`),

  '## Démonstration et accompagnement',
  'Démonstration de 10 minutes sur le téléphone du prospect, avec ses propres prestations, à Libreville ou en visio. Installation faite sur place à Libreville.',
  cahier,
  'Marketplace : en préparation. Elle présentera les entreprises Menentia de Libreville par activité et par quartier, avec réservation en ligne. Elle ouvre avec le lancement.',
].join('\n');

// Mots que les visiteurs emploient pour leur métier, en plus des noms de la page.
const familyWords: Record<string, string[]> = {
  beaute: ['salon', 'coiffeuse', 'ongles', 'manucure', 'institut', 'beaute', 'soins'],
  sante: ['clinique', 'cabinet', 'medical', 'medecin', 'dentiste', 'kine', 'opticien', 'optique', 'patient', 'sante'],
  sport: ['salle', 'fitness', 'coach', 'gym', 'yoga', 'sport', 'abonnes'],
  technique: ['garage', 'mecanicien', 'mecanique', 'voiture', 'auto', 'reparation', 'vidange', 'technique'],
  pro: ['comptable', 'avocat', 'consultant', 'agence', 'assurance', 'juridique', 'professionnel'],
  commerce: ['commerce', 'commercial', 'commerciale', 'retail', 'distribution', 'distributeur', 'magasin', 'boutique', 'supermarche', 'grossiste', 'revendeur', 'enseigne', 'quincaillerie', 'achats', 'base'],
};

const familyAnswers: Answer[] = families.map((f) => ({
  id: `famille-${f.slug}`,
  q: `Menentia pour ${f.name.toLowerCase()}`,
  keys: [f.short, ...f.trades, ...(familyWords[f.slug] ?? [])].map((s) => s.toLowerCase()),
  a: `${f.intro} Concrètement : ${f.solutions.map((s) => s.title.charAt(0).toLowerCase() + s.title.slice(1)).join(' ; ')}.`,
  links: [[`Voir la page ${f.name}`, `/activites/${f.slug}`], demo],
}));

// Réponses de secours, quand le service IA n’est pas joignable.
export const answers: Answer[] = [
  {
    id: 'presentation',
    q: 'C’est quoi Menentia ?',
    keys: ['menentia', 'quoi', 'presentation', 'logiciel', 'application', 'crm', 'sert', 'faire'],
    a: `${brand.description} Elle fonctionne sur ordinateur et sur téléphone, sans rien à installer pour vos clients.`,
    links: [['Toutes les fonctionnalités', '/fonctionnalites'], demo],
  },
  {
    id: 'etapes',
    q: 'Comment ça marche, quelles sont les étapes pour démarrer ?',
    keys: ['marche', 'etape', 'etapes', 'demarrer', 'demarrage', 'utiliser', 'fonctionne', 'fonctionnement', 'debuter', 'procedure', 'processus', 'mettre', 'place', 'installation', 'configurer', 'adopter', 'commencer', 'ou'],
    a: `Voici les étapes pour une PME :\n${steps.map(([t, d], i) => `${i + 1}. **${t}** : ${d}`).join('\n')}`,
    links: [demo, trial],
  },
  {
    id: 'quotidien',
    q: 'À quoi ressemble une journée avec Menentia ?',
    keys: ['journee', 'quotidien', 'jour', 'matin', 'concretement', 'exemple', 'menu', 'ecran', 'interface', 'simple', 'facile'],
    a: `${daily.map(([t, d]) => `- **${t}** : ${d}`).join('\n')}\nLe menu reste court : Accueil, À faire, Agenda, Clients, Ventes, Campagnes et Paramètres.`,
    links: [['Toutes les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'difference',
    q: 'Qu’est-ce qui distingue Menentia ?',
    keys: ['difference', 'different', 'distingue', 'pourquoi', 'avantage', 'fresha', 'hubspot', 'concurrent', 'concurrence', 'autre', 'mieux', 'excel'],
    a: 'Menentia est pensé pour les entreprises sur rendez-vous d’Afrique francophone : WhatsApp d’abord plutôt que l’email, une analyse client (segments, score, alerte de départ) réglée sur le rythme de votre métier, des prix en FCFA payables par Mobile Money et un accompagnement en français, sur place à Libreville.',
    links: [['Toutes les fonctionnalités', '/fonctionnalites'], demo],
  },
  {
    id: 'pour-qui',
    q: 'Pour quelles entreprises ?',
    keys: ['qui', 'cible', 'metier', 'metiers', 'secteur', 'secteurs', 'activite', 'activites', 'entreprises', 'independant', 'taille', 'petite'],
    a: `Pour toute entreprise qui travaille sur rendez-vous, de l’indépendant à l’entreprise à plusieurs sites, ainsi que le commerce et la distribution qui veulent analyser leur base clients, regroupés en ${families.length} familles : ${families.map((f) => f.name.toLowerCase()).join(', ')}. Le produit est le même pour tous ; ce qui change, c’est le rythme des visites et le signal qui annonce un départ.`,
    links: [['Voir les activités', '/activites'], demo],
  },
  {
    id: 'avenir',
    q: 'Qu’est-ce qui arrive ensuite ?',
    keys: ['bientot', 'futur', 'avenir', 'prochaine', 'prochaines', 'version', 'versions', 'nouveaute', 'nouveautes', 'quand', 'prevu', 'feuille', 'route', 'sms', 'acompte'],
    a: 'Sont prévus, sans date annoncée : les rappels WhatsApp automatiques dès la validation par Meta, des modules par métier (Beauté d’abord), une boîte de réception WhatsApp, l’acompte Mobile Money, le SMS de secours, puis des fonctions d’IA (rédaction des campagnes, assistant WhatsApp qui répond et réserve, prévision d’affluence) et la marketplace.',
    links: [['Toutes les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'tarifs',
    q: 'Combien ça coûte ?',
    keys: ['prix', 'tarif', 'cout', 'combien', 'cher', 'abonnement', 'offre', 'fcfa', 'solo', 'equipe', 'premium', 'mois'],
    a: `${plans.map((p) => `${p.name} : ${p.price} FCFA par mois (${p.who.toLowerCase()})`).join(' · ')}. Essai de 14 jours sans carte bancaire, sans engagement, paiement par Mobile Money.`,
    links: [['Comparer les offres', '/tarifs'], trial],
  },
  {
    id: 'essai',
    q: 'Comment fonctionne l’essai gratuit ?',
    keys: ['essai', 'gratuit', 'tester', 'test', 'carte', 'bancaire', 'inscription', 'inscrire', 'commencer', 'engagement'],
    a: 'L’essai dure 14 jours, sans carte bancaire et sans engagement. Nous importons vos clients avec vous et faisons le point après 2 semaines.',
    links: [trial, demo],
  },
  {
    id: 'paiement',
    q: 'Comment payer l’abonnement ?',
    keys: ['payer', 'paiement', 'mobile', 'money', 'airtel', 'moov', 'mtn', 'orange', 'annuel', 'facture'],
    a: 'L’abonnement se paie par Mobile Money (Airtel Money, Moov Money, MTN, Orange Money). En paiement annuel, 2 mois sont offerts. Dans l’application, vous notez aussi le moyen de paiement de chaque client : espèces, Mobile Money, carte ou virement.',
    links: [['Voir les tarifs', '/tarifs']],
  },
  {
    id: 'promo',
    q: 'Y a-t-il une offre de lancement ?',
    keys: ['fondateur', 'lancement', 'promo', 'promotion', 'reduction', 'remise', 'parrainage', 'parrain', 'offert'],
    a: `${offers.map(([, t, d]) => `${t} : ${d}`).join('. ')}.`,
    links: [['Voir les tarifs', '/tarifs'], trial],
  },
  {
    id: 'pays',
    q: 'Dans quels pays ?',
    keys: ['pays', 'gabon', 'cameroun', 'libreville', 'douala', 'yaounde', 'ville', 'afrique', 'disponible', 'ou'],
    a: 'Menentia démarre à Libreville, au Gabon, avec un lancement le 15 décembre 2026. Le Cameroun suit, avec des prix environ 25 % plus bas. Les démonstrations se font sur place à Libreville ou en visio.',
    links: [demo],
  },
  {
    id: 'whatsapp',
    q: 'Comment Menentia utilise WhatsApp ?',
    keys: ['whatsapp', 'message', 'relance', 'relancer', 'rappel', 'rappeler', 'envoyer', 'api', 'meta', 'business'],
    a: 'Sans configuration, Menentia prépare chaque jour vos listes de relance (rendez-vous à rappeler, absents, clients à risque, anniversaires) avec le message déjà rempli : un clic, et il part depuis votre WhatsApp. Si vous reliez votre compte WhatsApp Business API, vous pouvez aussi envoyer des campagnes par lots avec les statuts remis et lu. Les rappels entièrement automatiques arrivent bientôt.',
    links: [['Lire le guide WhatsApp', '/whatsapp']],
  },
  {
    id: 'api-cout',
    q: 'Combien coûte l’API WhatsApp ?',
    keys: ['api', 'whatsapp', 'meta', 'cout', 'coute', 'facture', 'credit', 'credits', 'pack'],
    a: `${whatsappFaq[2].a} Menentia propose des packs de crédits payables par Mobile Money.`,
    links: [['Lire le guide WhatsApp', '/whatsapp']],
  },
  {
    id: 'consentement',
    q: 'Mes clients doivent-ils donner leur accord ?',
    keys: ['accord', 'consentement', 'stop', 'desinscription', 'rgpd', 'spam', 'opt'],
    a: `${whatsappFaq[3].a} Une affiche avec QR code en caisse permet de recueillir l’accord, et un client qui répond « Stop » ne reçoit plus de campagne.`,
    links: [['Lire le guide WhatsApp', '/whatsapp']],
  },
  {
    id: 'agenda',
    q: 'Comment marche l’agenda ?',
    keys: ['agenda', 'rendez', 'rdv', 'planning', 'calendrier', 'creneau', 'intervenant', 'employe', 'pause', 'conge', 'ferie', 'double'],
    a: `${feature('agenda').lead} Au programme : ${items('agenda')}.`,
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'reservation',
    q: 'Mes clients peuvent-ils réserver en ligne ?',
    keys: ['reserver', 'reservation', 'ligne', 'lien', 'statut', 'nuit', 'annuler'],
    a: feature('agenda').items[1].text + ' Le lien se met dans votre statut et votre profil WhatsApp.',
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'fichier',
    q: 'Puis-je importer mes clients ?',
    keys: ['import', 'importer', 'excel', 'csv', 'cahier', 'contacts', 'fichier', 'fiche', 'saisir', 'saisie', 'historique'],
    a: `${feature('clients').items[0].text} Chaque client a ensuite sa fiche complète : visites, achats, dépenses, préférences, consentement et notes.`,
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'segments',
    q: 'Comment Menentia analyse mes clients ?',
    keys: ['rfm', 'segment', 'segmentation', 'analyse', 'score', 'meilleurs', 'vip', 'depart', 'risque', 'perdu', 'churn', 'comprendre'],
    a: `Chaque client est classé dans un des 8 segments RFM (Champions, Clients fidèles, Potentiels, Nouveaux clients, À ne pas perdre, À risque, Hibernants, Perdus), recalculés chaque nuit, avec un score de 0 à 100. L’alerte de départ vous prévient quand un client dépasse son délai habituel entre deux visites.`,
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'fidelite',
    q: 'Y a-t-il un programme de fidélité ?',
    keys: ['fidelite', 'points', 'palier', 'recompense', 'carte', 'cadeau', 'fideliser'],
    a: `Oui, il est disponible : ${feature('fidelite').items.map((i) => i.text).join(' ')}`,
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'ventes',
    q: 'Puis-je encaisser avec Menentia ?',
    keys: ['vente', 'encaisser', 'encaissement', 'caisse', 'catalogue', 'produit', 'prestation', 'chiffre', 'stock'],
    a: `${feature('ventes').lead} Un rendez-vous terminé devient une vente en deux gestes, et le chiffre d’affaires est détaillé par prestation et par produit. Le stock n’est pas géré pour l’instant.`,
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'rapport',
    q: 'Est-ce qu’il y a un rapport quotidien ?',
    keys: ['rapport', 'quotidien', 'soir', 'tableau', 'bord', 'statistique', 'chiffres', 'dirigeant', 'patron', 'etablissements', 'sites'],
    a: `${feature('pilotage').items[1].text} Le tableau de bord montre aussi le chiffre d’affaires et les clients à traiter, et plusieurs établissements peuvent être suivis séparément.`,
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'ia',
    q: 'Que fait l’intelligence artificielle ?',
    keys: ['ia', 'intelligence', 'artificielle', 'chatbot', 'robot', 'automatique', 'prevision'],
    a: `Les fonctions IA de l’application arrivent dans une prochaine version : ${items('ia')}. L’IA explique et propose, vous gardez la main.`,
    links: [['Voir les fonctionnalités', '/fonctionnalites']],
  },
  {
    id: 'donnees',
    q: 'Mes données sont-elles protégées ?',
    keys: ['donnees', 'securite', 'confidentialite', 'protection', 'acces', 'role', 'droits', 'vol'],
    a: `${feature('securite').lead} ${feature('securite').items.map((i) => i.text).join(' ')}`,
  },
  {
    id: 'marketplace',
    q: 'C’est quoi la marketplace ?',
    keys: ['marketplace', 'visible', 'trouver', 'annuaire', 'nouveaux', 'clients', 'quartier'],
    a: 'La marketplace Menentia est en préparation : elle présentera les entreprises de Libreville par activité et par quartier, avec leurs prestations, leurs prix et leurs créneaux libres, et elle ouvre avec le lancement.',
    links: [['Voir la marketplace', '/marketplace']],
  },
  {
    id: 'demo',
    q: 'Comment avoir une démonstration ?',
    keys: ['demo', 'demonstration', 'contact', 'contacter', 'appeler', 'parler', 'humain', 'conseiller', 'rencontrer', 'telephone', 'numero'],
    a: 'Laissez vos coordonnées sur la page de démonstration : nous vous montrons Menentia en 10 minutes sur votre téléphone, avec vos propres prestations, à Libreville ou en visio.',
    links: [demo],
  },
  {
    id: 'mobile',
    q: 'Faut-il installer une application ?',
    keys: ['installer', 'telecharger', 'android', 'iphone', 'ios', 'mobile', 'ordinateur', 'internet'],
    a: 'Menentia s’utilise sur ordinateur et sur téléphone. Vos clients, eux, n’ont rien à installer : tout passe par WhatsApp et une page de réservation qui s’ouvre dans le navigateur.',
    links: [trial],
  },
  ...familyAnswers,
  {
    id: 'assistant',
    q: 'Qui êtes-vous ?',
    keys: ['qui', 'es', 'tu', 'robot', 'humain', 'assistant'],
    a: 'Je suis l’assistant automatique du site Menentia. Je réponds aux questions sur l’application, ses prix et ses fonctions. Pour parler à une personne de l’équipe, demandez une démonstration.',
    links: [demo],
  },
];

export const suggestions = ['Comment ça marche pour démarrer ?', 'Combien ça coûte ?', 'Comment marche WhatsApp avec Menentia ?', 'Puis-je importer mes clients depuis Excel ?', 'Est-ce adapté à mon salon ?'];

// Réponse quand aucune fiche ne correspond : un résumé plutôt qu’un « je ne sais pas ».
export const unknown = `Je n’ai pas de réponse précise à cette question. En bref : ${brand.description} Vous pouvez me demander les étapes pour démarrer, les prix, le fonctionnement avec WhatsApp ou ce que Menentia fait pour votre métier. Pour le reste, l’équipe vous répond lors d’une courte démonstration.`;

export const assistantKb = { version: 2, knowledge, answers, suggestions, unknown };
