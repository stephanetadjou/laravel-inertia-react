// Extraits publics du cahier de cadrage final (28/10/2026), repris par
// l’assistant du site. La base /assistant/kb.json est publique : on n’y met que
// ce qu’un prospect peut lire (pas de risques, de plan interne ni de chiffres
// d’objectifs). Les versions à venir restent sans date, comme sur le site.

export const steps: [title: string, text: string][] = [
  ['Inscription en 2 minutes', 'Essai gratuit de 14 jours, sans carte bancaire, sans engagement. Vous choisissez votre secteur.'],
  ['Vos modules à la carte', 'Quelques questions sur votre façon de travailler (rendez-vous, cours en groupe, abonnements, paiement en plusieurs fois, plusieurs lieux) : NENTIX active seulement ce dont vous avez besoin, avec des réglages par défaut qui marchent tout de suite.'],
  ['Vos clients importés', 'Vous importez votre fichier Excel ou vos contacts grâce au modèle fourni. La liste « Pour bien démarrer » vous guide pas à pas.'],
  ['Mise en route', 'Vous connectez votre WhatsApp en suivant le guide, puis commencez les relances en un clic depuis la page « À faire ».'],
  ['Choix de l’offre', 'Au bout de 14 jours, vous choisissez votre offre (Solo, Équipe ou Premium), payable par Mobile Money. Notre support répond sur WhatsApp si besoin.'],
];

export const daily: [title: string, text: string][] = [
  ['Le matin', 'La page « À faire » liste les rendez-vous à confirmer, les absents à reprogrammer, les clients à risque et les anniversaires, chacun avec son message WhatsApp prêt.'],
  ['Pendant la journée', 'L’agenda (Jour, Semaine, Mois) se remplit aussi tout seul grâce à la réservation en ligne. Un rendez-vous terminé s’encaisse en deux gestes et les points de fidélité s’ajoutent.'],
  ['Le soir', 'Le rapport quotidien arrive par email (21 h par défaut) : chiffre d’affaires, rendez-vous et absences du jour, programme du lendemain, clients à risque.'],
];

export const cahier = [
  '## Vision et positionnement (cahier de cadrage)',
  'NENTIX réunit dans un même outil la gestion de la relation client, la prise de rendez-vous, la communication WhatsApp, les prestations et ventes, les relances et l’analyse des données. Vision : devenir l’outil de référence des entreprises sur rendez-vous d’Afrique francophone pour connaître leurs clients, les faire revenir et piloter leur activité avec des chiffres.',
  'NENTIX se définit par un problème commun, pas par un secteur : rendez-vous oubliés, clients qui partent sans prévenir, fichier client éparpillé entre un cahier et un téléphone, clients qui répondent sur WhatsApp mais pas aux emails.',
  'Trois piliers : Communiquer (WhatsApp d’abord, puis SMS et email : rappels, confirmation, campagnes ciblées, STOP) ; Comprendre (segments RFM, score client, alerte de départ, VIP, puis valeur vie client) ; Suivre (fiche 360°, agenda, encaissement, fidélité, relances au bon moment selon le métier).',
  'Pour qui : l’entreprise (indépendant ou équipe, un ou plusieurs sites, au Gabon puis au Cameroun et dans le reste de l’Afrique francophone, d’abord les PME de services récurrents). Son client reçoit rappels et offres sur WhatsApp, confirme en un message et réserve en ligne sans installer d’application.',
  'Ce qui distingue NENTIX des logiciels d’agenda venus d’ailleurs et des CRM généralistes : toutes les entreprises de services, pas seulement la beauté ; WhatsApp d’abord plutôt que l’email ; analyse RFM, score et alerte de départ réglés sur le rythme du métier ; prix en FCFA, paiement Mobile Money, support en français sur WhatsApp.',
  'Proposition de valeur : transformer chaque échange avec un client en occasion de vente, de fidélisation et d’amélioration, avec des recommandations et des analyses vérifiables. Les gains (moins d’absences, plus de clients qui reviennent, moins de temps passé aux relances) se mesurent avant et après chez chaque entreprise ; ils ne sont pas promis à l’avance.',
  'Le produit est le même pour tous les métiers ; ce qui change, c’est le rythme des visites, le signal qui annonce un départ et un module propre au métier.',

  '## Comment démarrer avec NENTIX : les étapes pour une PME',
  ...steps.map(([t, d], i) => `${i + 1}. ${t} : ${d}`),

  '## Une journée avec NENTIX',
  ...daily.map(([t, d]) => `- ${t} : ${d}`),
  'Menu de l’application, volontairement simple : Accueil, À faire, Agenda, Clients, Ventes (onglets Ventes, Catalogue, Catégories), Campagnes (si l’API WhatsApp est activée), Paramètres. Le stock n’est pas géré.',

  '## Ce qui arrive ensuite (sans date annoncée)',
  '- Rappels WhatsApp automatiques (confirmation, veille, 2 h avant) avec réponse « 1 = je confirme, 2 = j’annule », dès la validation par Meta.',
  '- Modules métier : Beauté (« il est temps de revenir », forfaits et cartes de séances) en premier, puis Santé (notes privées chiffrées, rappel de contrôle), Sport (abonnement avec échéance, cours avec capacité), Services techniques (rappel d’entretien, devis sur WhatsApp), Services professionnels (suivi de dossier par étapes).',
  '- Boîte de réception WhatsApp, scénarios automatiques (anniversaire, avis, relance des inactifs), acompte et paiement Mobile Money, SMS de secours.',
  '- IA : rédaction des campagnes, assistant WhatsApp qui répond et réserve, groupes automatiques, valeur vie client, prévision d’affluence.',
  '- Marketplace des entreprises de Libreville avec avis vérifiés, puis ouverture du Cameroun (Douala, Yaoundé) et d’autres pays d’Afrique francophone.',

  '## Principes de l’IA chez NENTIX',
  '- Les chiffres viennent du moteur de calcul ; l’IA les explique et ne les invente jamais.',
  '- Tout envoi, réservation ou modification déclenché par l’IA suit une règle claire et, si besoin, une validation humaine.',
  '- L’assistant d’une entreprise répond à partir de son catalogue et de ses règles, n’invente aucune disponibilité et passe la main à un humain.',

  '## Sécurité et données (cahier de cadrage)',
  '- Chaque donnée est rattachée à une entreprise ; des tests automatiques vérifient qu’aucune entreprise ne voit celles d’une autre.',
  '- Consentement WhatsApp daté et sourcé, STOP respecté, politique de confidentialité publique par entreprise.',
  '- Secrets hors du code, jetons WhatsApp chiffrés, journal des actions sensibles (exports, suppressions, droits), sauvegardes testées.',
  '- Données de santé : notes chiffrées visibles du seul praticien, jamais envoyées dans un message.',
  '- Quotas de messages et d’appels IA par offre : pas de promesse « illimité ».',

  '## Pitch',
  '« Vous perdez des clients sans le savoir : des rendez-vous oubliés, des habituées qui ne reviennent plus, un fichier client dans un cahier. NENTIX réunit votre agenda, vos clients et vos ventes dans une seule application, vous montre chaque jour qui relancer et vous permet de leur écrire sur WhatsApp en un clic. Essai gratuit de 14 jours, sans carte bancaire, paiement par Mobile Money. »',
].join('\n');
