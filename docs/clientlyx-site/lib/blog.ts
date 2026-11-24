// Articles du blog. Textes originaux ClientlyX (sujets inspirés d’une veille sur
// WhatsApp Business, aucun texte repris). Ajouter un article = ajouter un objet ici.

export type Block =
  | { h: string }
  | { p: string }
  | { ul: string[] }
  | { tip: string }
  | { ol: string[] } // liste numérotée (étapes)
  | { see: string } // encadré « Ce que vous devez voir à l’écran »
  | { table: { head: string[]; rows: string[][] } };

export type Post = {
  slug: string;
  title: string;
  excerpt: string;
  category: string;
  date: string; // AAAA-MM-JJ
  icon: string;
  sectors: string[];
  body: Block[];
};

export const blogCategories = ['Bien démarrer', 'API WhatsApp', 'Fidéliser', 'Choisir un outil'];

export const posts: Post[] = [
  {
    slug: 'whatsapp-business-application-ou-api',
    title: 'Application WhatsApp Business ou API : laquelle choisir pour votre PME ?',
    excerpt: 'L’application gratuite suffit pour commencer. L’API devient utile quand plusieurs personnes répondent ou que vous écrivez à des centaines de clients. Voici comment savoir où vous en êtes.',
    category: 'Bien démarrer',
    date: '2026-11-10',
    icon: 'mobile',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'À Libreville, Douala ou Abidjan, vos clients vous écrivent d’abord sur WhatsApp. Pour une entreprise, il existe deux façons officielles de l’utiliser : l’application WhatsApp Business, gratuite, et l’API WhatsApp Business (appelée aussi « plateforme WhatsApp Business »), que l’on branche à un logiciel comme ClientlyX CRM. Les deux ne répondent pas au même besoin.' },
      { h: 'L’application WhatsApp Business : simple et gratuite' },
      { p: 'Elle s’installe sur un téléphone comme WhatsApp classique. Vous y ajoutez un profil d’entreprise (horaires, adresse, catalogue), des réponses rapides, un message d’absence et des étiquettes pour classer les conversations.' },
      { ul: [
        'Idéale pour une gérante de salon qui répond elle-même, un coach indépendant ou une petite boutique.',
        'Les listes de diffusion n’atteignent que les clients qui ont enregistré votre numéro.',
        'Tout repose sur un téléphone : si l’employée qui le tient part, l’historique part avec elle.',
        'Aucune statistique fiable : vous ne savez pas qui a lu, qui a réservé, qui n’est jamais revenu.',
      ] },
      { h: 'L’API WhatsApp : pour travailler en équipe et à grande échelle' },
      { p: 'L’API n’a pas d’écran : elle relie votre numéro à un logiciel. C’est ce logiciel qui affiche les conversations, envoie les rappels et mesure les résultats.' },
      { ul: [
        'Plusieurs personnes répondent depuis le même numéro, chacune sur son ordinateur ou son téléphone.',
        'Les rappels de rendez-vous, confirmations de paiement et relances partent tout seuls.',
        'Les campagnes atteignent tous les clients qui ont donné leur accord, même s’ils n’ont pas enregistré votre numéro.',
        'Chaque message est rattaché à la fiche du client : visites, achats, montant dépensé.',
      ] },
      { p: 'En contrepartie, Meta facture une partie des messages envoyés à votre initiative (promotions, rappels) et demande que votre entreprise soit vérifiée. Les réponses aux clients qui vous ont écrit restent gratuites.' },
      { h: 'Comment décider ?' },
      { ul: [
        'Vous êtes seul(e) et avez moins de 200 clients actifs : gardez l’application, et notez vos clients dans un fichier dès maintenant.',
        'Deux personnes ou plus répondent aux messages : passez à l’API, sinon vous perdrez des demandes.',
        'Vous voulez relancer automatiquement les clients absents depuis 60 jours : il vous faut l’API.',
        'Vous avez plusieurs salons, salles ou boutiques : l’API, avec un numéro par établissement ou un numéro commun.',
      ] },
      { tip: 'Avec ClientlyX CRM, l’offre Solo fonctionne avec votre WhatsApp actuel : le message de relance est préparé, vous l’envoyez d’un clic depuis votre téléphone. Les offres Équipe et Premium se branchent sur l’API officielle quand vous êtes prêt.' },
    ],
  },
  {
    slug: '10-usages-whatsapp-pme-services',
    title: '10 façons d’utiliser WhatsApp pour remplir votre agenda et faire revenir vos clients',
    excerpt: 'Rappels, relances, anniversaires, avis, fidélité : dix usages concrets, classés par métier, que vous pouvez lancer dès cette semaine.',
    category: 'Fidéliser',
    date: '2026-11-10',
    icon: 'send',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'WhatsApp n’est pas qu’un outil pour répondre. Bien utilisé, il devient votre meilleur commercial : il rappelle, relance et remercie à votre place. Voici dix usages qui marchent dans les PME de services en Afrique centrale et de l’Ouest.' },
      { h: 'Pour réduire les absences' },
      { ul: [
        '1. Le rappel la veille : « Bonjour Awa, nous vous attendons demain à 10 h pour vos tresses. Répondez 1 pour confirmer, 2 pour décaler. » Les rendez-vous oubliés chutent nettement.',
        '2. La confirmation immédiate : dès qu’un rendez-vous ou un abonnement est enregistré, le client reçoit le récapitulatif (date, prestation, prix).',
        '3. L’annulation météo : une salle de sport en plein air ou une école de natation prévient tous les inscrits du créneau en une fois quand la pluie tombe, et propose un rattrapage.',
      ] },
      { h: 'Pour faire revenir les clients' },
      { ul: [
        '4. La relance des absents : un client qui venait chaque mois et ne vient plus depuis 45 jours reçoit un message personnel, avec une offre si besoin.',
        '5. Le renouvellement d’abonnement : sept jours avant la fin d’une formule de sport ou d’un contrat de maintenance, un rappel avec le lien de paiement Mobile Money.',
        '6. L’anniversaire : un message et un petit cadeau (une pose de vernis, une séance offerte). C’est le message qui obtient le plus de réponses.',
        '7. Le suivi après prestation : « Comment trouvez-vous votre coupe après une semaine ? » Il montre que vous vous souciez du résultat et ouvre la porte au prochain rendez-vous.',
      ] },
      { h: 'Pour vendre plus' },
      { ul: [
        '8. La campagne ciblée : une boutique annonce l’arrivée d’une nouvelle collection uniquement aux clientes qui ont déjà acheté cette marque.',
        '9. Le programme de fidélité : après chaque passage, le client reçoit son solde de points et ce qu’il lui manque pour sa récompense.',
        '10. La demande d’avis et de parrainage : un client satisfait reçoit un lien pour recommander l’entreprise à un proche, avec un avantage pour les deux.',
      ] },
      { h: 'Les trois règles à respecter' },
      { ul: [
        'Écrire seulement aux clients qui ont donné leur accord, et respecter le mot STOP.',
        'Personnaliser : le prénom, la dernière prestation, le bon établissement.',
        'Mesurer : combien ont répondu, combien sont revenus, combien ont payé.',
      ] },
      { tip: 'ClientlyX CRM prépare ces dix scénarios pour vous : il repère les clients concernés chaque matin et vous propose le message prêt à partir. Vous gardez la main sur ce qui est envoyé.' },
    ],
  },
  {
    slug: 'tarifs-api-whatsapp-comprendre',
    title: 'Combien coûte l’API WhatsApp ? Comprendre la facturation de Meta',
    excerpt: 'Messages marketing, utilitaires, d’authentification, réponses gratuites : la logique de facturation expliquée simplement, avec des exemples pour une PME.',
    category: 'API WhatsApp',
    date: '2026-11-10',
    icon: 'wallet',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'Beaucoup de dirigeants hésitent à passer à l’API WhatsApp par peur d’une facture imprévisible. En réalité, la règle est assez simple une fois qu’on a compris qui paie quoi.' },
      { h: 'Deux coûts à distinguer' },
      { ul: [
        'Le coût de Meta : ce que WhatsApp facture pour certains messages. Il est le même quel que soit le logiciel utilisé.',
        'Le coût du logiciel : l’abonnement à l’outil qui affiche les conversations et envoie les messages (par exemple ClientlyX CRM).',
      ] },
      { h: 'Ce que Meta facture' },
      { p: 'Depuis 2025, Meta facture à l’unité les messages « modèles », c’est-à-dire les messages que vous envoyez à votre initiative et qu’il a validés au préalable. Ils sont classés en trois catégories :' },
      { ul: [
        'Marketing : promotions, nouveautés, relance d’un client absent. C’est la catégorie la plus chère.',
        'Utilitaire : rappel de rendez-vous, confirmation de paiement, suivi de commande. Beaucoup moins cher.',
        'Authentification : codes de connexion. Peu utile pour une PME de services.',
      ] },
      { h: 'Ce qui reste gratuit' },
      { ul: [
        'Toutes vos réponses quand un client vous écrit, pendant 24 heures après son dernier message.',
        'Les messages utilitaires envoyés pendant cette même fenêtre de 24 heures.',
        'Les conversations ouvertes depuis une publicité Facebook ou Instagram « Cliquer pour envoyer un message », pendant une période prolongée.',
      ] },
      { h: 'Un exemple concret' },
      { p: 'Un institut de beauté à Libreville envoie chaque mois 400 rappels de rendez-vous (utilitaires) et une campagne à 300 clientes (marketing). Les échanges où les clientes posent des questions ne coûtent rien. Le budget Meta reste de quelques milliers de francs CFA par mois, à comparer avec le prix d’un seul rendez-vous manqué.' },
      { tip: 'Les prix varient selon le pays du destinataire, et Meta les révise régulièrement. Avant de lancer une grosse campagne, consultez la grille officielle de Meta pour votre pays. Dans ClientlyX CRM, le nombre de messages envoyés par catégorie est affiché dans les campagnes, pour garder la maîtrise du budget.' },
      { h: 'Nos conseils pour garder la facture basse' },
      { ul: [
        'Ciblez : une campagne envoyée à 80 clientes fidèles rapporte plus qu’un envoi à 2 000 numéros.',
        'Classez bien vos modèles : un rappel de rendez-vous est un message utilitaire, pas marketing.',
        'Invitez les clients à vous écrire (affiche QR code en caisse) : la conversation qui suit est gratuite.',
      ] },
    ],
  },
  {
    slug: 'configurer-api-whatsapp-etapes',
    title: 'Brancher l’API WhatsApp officielle à ClientlyX : le guide pas à pas',
    excerpt: 'Vous n’avez qu’un compte Facebook personnel et vous n’êtes pas informaticien ? Ce guide vous fait connecter votre numéro WhatsApp à ClientlyX et envoyer votre premier message test, étape par étape. Avec le bon choix de numéro, neuf ou déjà utilisé.',
    category: 'API WhatsApp',
    date: '2026-11-19',
    icon: 'play',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'L’API WhatsApp officielle permet à ClientlyX d’envoyer vos rappels, vos relances et vos campagnes depuis le numéro de votre entreprise, et de recevoir les réponses de vos clients dans l’écran Discussions. Elle se branche une fois, en 30 à 45 minutes. Ce guide suppose que vous n’avez jamais touché à un outil Meta : chaque étape correspond à une seule action, suivie de ce que vous devez voir à l’écran pour savoir que tout s’est bien passé.' },
      { tip: 'Deux façons de faire. Vous pouvez tout faire vous-même en suivant ce guide. Ou bien vous pouvez laisser l’équipe ClientlyX s’en charger : votre numéro est alors « géré par ClientlyX » (c’est le libellé affiché dans Paramètres › WhatsApp), et ClientlyX s’occupe du token et du webhook à votre place. Écrivez-nous sur WhatsApp pour choisir cette option.' },

      { h: 'Avant de commencer (5 minutes)' },
      { p: 'Rassemblez ces éléments avant de vous lancer, de préférence sur un ordinateur.' },
      { ul: [
        'Votre compte Facebook personnel, avec son mot de passe. Il sert seulement à vous connecter aux outils de Meta : rien n’est publié sur votre profil, et vos amis ne voient rien.',
        'Un numéro de téléphone pour l’entreprise, capable de recevoir un SMS ou un appel venant de l’étranger, avec le téléphone à portée de main. Lisez la section suivante avant de choisir ce numéro.',
        'Une adresse e-mail professionnelle que vous consultez (celle de l’entreprise, ou à défaut la vôtre).',
        'Le nom officiel de l’entreprise, écrit exactement comme sur vos documents (RCCM, NIF) et sur votre page Facebook si vous en avez une.',
        'Une carte bancaire, pour payer à Meta les conversations au-delà de ce qui est gratuit (les campagnes marketing surtout). Vous pourrez l’ajouter à la fin.',
        'Environ 30 à 45 minutes sans interruption.',
      ] },
      { p: 'Sécurisez d’abord votre compte Facebook. Meta bloque facilement les comptes qui lui paraissent suspects, et un compte bloqué bloque aussi tout ce qui en dépend.' },
      { ol: [
        'Vérifiez que votre profil Facebook porte votre vrai nom et qu’il est actif depuis un certain temps.',
        'Activez la double authentification : Facebook › Paramètres › Espace comptes › Mot de passe et sécurité › Authentification à deux facteurs.',
        'Ne créez jamais de faux profil « au nom de l’entreprise » pour l’occasion : Meta le détecte et peut bloquer le profil et le compte WhatsApp qui lui est rattaché.',
      ] },

      { h: 'Quel numéro utiliser ? Trois cas' },
      { p: 'C’est la question qui bloque le plus d’entreprises. Un numéro ne peut pas être à la fois dans l’application WhatsApp classique et dans l’API, sauf dans le cas C ci-dessous. Choisissez votre cas avant l’étape 1.' },
      { p: 'Cas A : un numéro neuf (recommandé pour débuter). C’est une puce neuve, qui n’a jamais été utilisée sur WhatsApp ni sur WhatsApp Business. C’est le plus simple : rien à supprimer, rien à migrer. Vous communiquerez ensuite ce numéro à vos clients (cartes, statut, affiches).' },
      { p: 'Cas B : un numéro déjà sur WhatsApp ou WhatsApp Business, que vous voulez passer entièrement sur l’API. Vous devez d’abord supprimer le compte WhatsApp de ce numéro, sinon Meta refusera de l’ajouter.' },
      { ol: [
        'Sauvegardez vos discussions importantes : dans l’application, Paramètres › Discussions › Sauvegarde, ou exportez les discussions une par une.',
        'Supprimez le compte : WhatsApp › Paramètres › Compte › Supprimer mon compte, puis confirmez avec le numéro. Attention : supprimer le compte n’est pas la même chose que désinstaller l’application. Désinstaller ne suffit pas.',
        'Attendez quelques minutes avant de passer à l’étape 4 du guide.',
      ] },
      { p: 'Ensuite, ce numéro ne fonctionne plus dans l’application WhatsApp sur le téléphone : vous l’utilisez seulement depuis ClientlyX (écran Discussions). Vos clients continuent de vous écrire au même numéro, sans rien changer de leur côté.' },
      { p: 'Cas C : vous gérez déjà vos clients sur WhatsApp Business et voulez garder le même numéro ET l’application. Meta propose pour cela la « coexistence » : le numéro reste dans l’application WhatsApp Business de votre téléphone et il est aussi connecté à l’API. Vous continuez à répondre depuis le téléphone, et ClientlyX envoie les messages automatiques.' },
      { ul: [
        'Conditions : une application WhatsApp Business à jour, un numéro actif dans cette application depuis un certain temps, et une connexion faite par l’inscription intégrée de Meta, en scannant un QR code depuis l’application (le menu s’appelle Appareils connectés ou Compte de plateforme professionnelle, selon la version de l’application). L’historique récent des discussions peut être importé.',
        'Disponibilité : elle dépend du pays. Vérifiez au moment de la connexion si l’option est ouverte au Gabon ; si elle n’apparaît pas, passez au cas A ou au cas B.',
        'Ce qui change dans l’application : certaines fonctions sont limitées ou ne se synchronisent pas (messages éphémères, listes de diffusion, groupes), et les messages automatiques partent par l’API.',
        'Avec ClientlyX aujourd’hui : l’écran Paramètres › WhatsApp n’a pas encore de bouton d’inscription intégrée Meta. Pour la coexistence, l’équipe ClientlyX fait la connexion avec vous : écrivez-nous sur WhatsApp avant de commencer.',
      ] },
      { table: {
        head: ['Votre situation', 'Ce que vous faites', 'L’application sur le téléphone', 'Difficulté'],
        rows: [
          ['A. Numéro neuf', 'Vous suivez ce guide tel quel', 'Non utilisée', 'La plus simple'],
          ['B. Numéro déjà sur WhatsApp, passage à l’API', 'Vous sauvegardez, supprimez le compte WhatsApp du numéro, puis suivez le guide', 'Ne marche plus pour ce numéro', 'Moyenne'],
          ['C. Garder le numéro et l’application', 'Coexistence de Meta, connexion faite avec l’équipe ClientlyX', 'Continue de marcher', 'Selon la disponibilité au Gabon'],
        ],
      } },

      { h: 'Étape 1 : créer le portefeuille d’entreprise Meta' },
      { p: 'Le portefeuille (anciennement « Business Manager ») est le dossier de votre entreprise chez Meta. Tout ce qui suit sera rangé dedans.' },
      { ol: [
        'Allez sur business.facebook.com et connectez-vous avec votre compte Facebook personnel.',
        'Cliquez sur « Créer un compte » (ou « Créer un portefeuille », selon l’affichage).',
        'Saisissez le nom de l’entreprise (le nom officiel), votre nom et votre adresse e-mail professionnelle, puis validez.',
        'Ouvrez l’e-mail envoyé par Meta et cliquez sur le lien de confirmation.',
      ] },
      { see: 'la page d’accueil de Meta Business Suite avec le nom de votre entreprise en haut à gauche.' },

      { h: 'Étape 2 : créer l’application Meta' },
      { p: 'L’« application » est la porte par laquelle ClientlyX parlera à WhatsApp. Pas d’inquiétude : vous n’écrivez aucune ligne de code.' },
      { ol: [
        'Allez sur developers.facebook.com et cliquez sur « Commencer » pour vous inscrire comme développeur avec le même compte Facebook (on vous demande de confirmer votre téléphone ou votre e-mail).',
        'Ouvrez « Mes apps », puis « Créer une app ».',
        'Choisissez le cas d’usage « Se connecter avec les clients via WhatsApp » ou, selon l’affichage, le type « Entreprise ».',
        'Donnez un nom à l’app (par exemple « ClientlyX + nom de votre entreprise ») et rattachez-la au portefeuille créé à l’étape 1.',
      ] },
      { see: 'le tableau de bord de votre app, avec en haut son identifiant (App ID), une suite de chiffres.' },

      { h: 'Étape 3 : ajouter WhatsApp et faire un premier essai avec le numéro de test de Meta' },
      { p: 'Meta fournit un numéro de test gratuit. Il permet de vérifier que tout marche avant d’ajouter votre vrai numéro.' },
      { ol: [
        'Dans l’app, ajoutez le produit WhatsApp (bouton « Configurer » sur la carte WhatsApp). Meta crée alors votre compte WhatsApp Business.',
        'Ouvrez WhatsApp › Configuration de l’API (ou « Démarrage rapide », selon l’affichage).',
        'Dans la zone « À », ajoutez votre numéro WhatsApp personnel comme destinataire et saisissez le code reçu sur ce numéro.',
        'Cliquez sur « Envoyer un message » : Meta envoie le modèle « hello_world ».',
      ] },
      { see: 'un message « Hello World » reçu sur votre WhatsApp personnel, envoyé par le numéro de test de Meta.' },
      { p: 'Le jeton (token) affiché sur cette page est temporaire : il expire au bout de 24 heures. Ne l’utilisez pas dans ClientlyX, vous créerez un jeton permanent à l’étape 5.' },

      { h: 'Étape 4 : ajouter le vrai numéro de l’entreprise' },
      { p: 'Reprenez le cas choisi plus haut : numéro neuf (cas A) ou compte WhatsApp supprimé (cas B). Pour le cas C, arrêtez-vous ici et contactez l’équipe ClientlyX.' },
      { ol: [
        'Toujours dans WhatsApp › Configuration de l’API, cliquez sur « Ajouter un numéro de téléphone ».',
        'Saisissez le nom d’affichage : le nom de l’entreprise que vos clients connaissent, identique à votre page Facebook ou à votre enseigne. Évitez un mot générique seul (« Salon », « Boutique »), Meta le refuse.',
        'Choisissez la catégorie de votre activité et, si on vous le demande, une courte description.',
        'Saisissez le numéro et choisissez la vérification par SMS ou par appel vocal, puis entrez le code reçu.',
      ] },
      { see: 'votre numéro dans la liste des numéros de la page Configuration de l’API, à côté du numéro de test.' },

      { h: 'Étape 5 : créer le jeton d’accès permanent' },
      { p: 'Le jeton est le mot de passe que ClientlyX utilisera pour envoyer vos messages. Celui de l’étape 3 expire en 24 heures ; celui-ci n’expire jamais.' },
      { ol: [
        'Allez dans Paramètres de l’entreprise (business.facebook.com › Paramètres) › Utilisateurs › Utilisateurs système, puis cliquez sur « Ajouter ».',
        'Donnez-lui un nom (par exemple « ClientlyX ») et le rôle Admin.',
        'Cliquez sur « Ajouter des éléments » (ou « Attribuer des éléments ») : cochez votre app, puis votre compte WhatsApp, chaque fois avec le contrôle total.',
        'Cliquez sur « Générer un jeton », choisissez votre app, l’expiration « Jamais », et cochez les deux autorisations whatsapp_business_messaging et whatsapp_business_management.',
        'Copiez le jeton tout de suite et gardez-le dans un endroit sûr : Meta ne le réaffiche pas.',
      ] },
      { see: 'une longue suite de lettres et de chiffres (le jeton), avec un bouton pour le copier.' },
      { tip: 'Ne partagez jamais ce jeton : ni par WhatsApp, ni par e-mail, ni en capture d’écran. Collez-le uniquement dans ClientlyX. Si vous pensez qu’il a fuité, supprimez-le dans Meta et générez-en un autre.' },

      { h: 'Étape 6 : noter les identifiants et la clé secrète' },
      { p: 'ClientlyX a besoin de quatre informations en plus du jeton. Ouvrez un bloc-notes et recopiez-les.' },
      { ol: [
        'Dans WhatsApp › Configuration de l’API, sélectionnez votre vrai numéro et notez l’« Identifiant du numéro de téléphone » : c’est le Phone Number ID demandé par ClientlyX.',
        'Sur la même page, notez l’« Identifiant du compte WhatsApp Business » : c’est le WABA ID demandé par ClientlyX.',
        'Notez l’App ID, affiché en haut du tableau de bord de l’app.',
        'Dans l’app › Paramètres de l’app › Général, cliquez sur « Afficher » à côté de la Clé secrète : c’est l’App Secret demandé par ClientlyX.',
      ] },
      { see: 'trois suites de chiffres (Phone Number ID, WABA ID, App ID) et une clé secrète de lettres et de chiffres dans votre bloc-notes.' },

      { h: 'Étape 7 : tout saisir dans ClientlyX' },
      { ol: [
        'Connectez-vous à ClientlyX. Si le menu Paramètres › WhatsApp n’apparaît pas, activez d’abord « Envoi automatique par l’API WhatsApp » dans Organisation.',
        'Ouvrez Paramètres › WhatsApp. Chaque établissement a son bloc, marqué « Non connecté » tant que rien n’est saisi.',
        'Remplissez les champs : WABA ID, Phone Number ID et App ID (étape 6), Token d’accès permanent (étape 5), App Secret (étape 6).',
        'Cliquez sur « Enregistrer », puis sur « Tester ».',
      ] },
      { see: 'dans « Dernier test », le Nom vérifié de votre entreprise, votre Numéro, la Qualité et la Limite d’envoi. Le token et l’App Secret sont chiffrés : ClientlyX ne les réaffiche jamais.' },

      { h: 'Étape 8 : configurer le webhook dans Meta' },
      { p: 'Le webhook permet à Meta de prévenir ClientlyX quand un client vous écrit ou qu’un message est lu. Sans lui, vous pouvez envoyer, mais vous ne recevez rien.' },
      { ol: [
        'Dans ClientlyX, Paramètres › WhatsApp, repérez la zone Webhook : elle affiche une URL de rappel et un Jeton de vérification, chacun avec un bouton pour le copier.',
        'Dans Meta, ouvrez votre app › WhatsApp › Configuration, puis cliquez sur « Modifier » dans la partie Webhook.',
        'Collez l’URL de rappel de ClientlyX dans le champ « URL de rappel », et le Jeton de vérification de ClientlyX dans le champ « Vérifier le token » (ou « Jeton de vérification »).',
        'Cliquez sur « Vérifier et enregistrer ».',
        'Dans la liste des champs du webhook, abonnez-vous au champ « messages » (bouton « S’abonner »).',
      ] },
      { see: 'le webhook enregistré sans message d’erreur, et le champ « messages » marqué comme abonné.' },

      { h: 'Étape 9 : enregistrer le numéro et faire votre premier test' },
      { ol: [
        'Dans ClientlyX, partie « Enregistrer le numéro », choisissez un code PIN de 6 chiffres et cliquez sur « Enregistrer le numéro ». C’est obligatoire une fois avant le premier envoi ; notez ce PIN, il protège votre numéro.',
        'Partie « Envoyer un message de test » : saisissez votre numéro WhatsApp personnel et cliquez sur « Envoyer ». Vous recevez le modèle hello_world de Meta, en anglais.',
        'Depuis un autre téléphone, écrivez « Bonjour » au numéro de l’entreprise.',
        'Ouvrez l’écran Discussions de ClientlyX et répondez au message.',
      ] },
      { see: 'le badge « Numéro enregistré » dans Paramètres › WhatsApp, le message « Bonjour » dans Discussions, et votre réponse reçue sur l’autre téléphone.' },
      { p: 'La règle des 24 heures : vous pouvez répondre librement à un client pendant 24 heures après son dernier message. Au-delà, WhatsApp n’accepte plus que des modèles de messages approuvés par Meta (rappel de rendez-vous, relance, campagne). ClientlyX vous propose ces modèles au moment de l’envoi.' },

      { h: 'Étape 10 (recommandé ensuite) : vérifier l’entreprise et ajouter un moyen de paiement' },
      { ul: [
        'Vérification de l’entreprise : dans Paramètres de l’entreprise › Centre de sécurité, lancez la vérification et envoyez vos documents officiels (RCCM, NIF ou équivalent, facture au nom de l’entreprise). Meta répond en général en quelques jours. Elle augmente vos limites d’envoi et rassure vos clients.',
        'Moyen de paiement : dans le Gestionnaire WhatsApp, ajoutez votre carte bancaire. Meta facture les conversations au-delà de ce qui est gratuit, en particulier les messages marketing.',
      ] },

      { h: 'Problèmes fréquents' },
      { p: 'Le code SMS de Meta n’arrive pas (fréquent sur les numéros gabonais). Choisissez la vérification par appel vocal, attendez quelques heures entre deux essais, ou demandez à votre opérateur si votre ligne reçoit les SMS internationaux.' },
      { p: 'Meta indique que le numéro est déjà utilisé sur WhatsApp. Le compte WhatsApp du numéro existe encore : suivez le cas B (sauvegarde puis Supprimer mon compte, pas seulement désinstaller), ou le cas C avec l’équipe ClientlyX.' },
      { p: '« Limite atteinte pour le nombre d’entreprises ». Un profil Facebook ne peut créer qu’un nombre limité de portefeuilles. Ne supprimez pas un portefeuille existant pour en recréer un : la limite reste la même. Réutilisez ou renommez celui qui existe, ou demandez à un associé réel de créer le portefeuille et de vous y ajouter comme administrateur.' },
      { p: 'Le webhook est refusé à l’étape 8. Recopiez l’URL de rappel et le Jeton de vérification avec les boutons de copie de ClientlyX (sans espace avant ou après), et vérifiez que l’App Secret est bien enregistré dans ClientlyX : sans lui, le webhook refuse tous les appels. L’adresse de ClientlyX doit être en ligne et commencer par https.' },
      { p: '« Tester » échoue dans ClientlyX. Vérifiez le Phone Number ID (celui du vrai numéro, pas du numéro de test), que le jeton a bien les deux autorisations et que le compte WhatsApp est attribué à l’utilisateur système. Si le jeton ne marche plus le lendemain, c’était le jeton temporaire de l’étape 3 : refaites l’étape 5 avec l’expiration « Jamais ».' },
      { p: 'Le client ne reçoit pas votre message après 24 heures. La fenêtre de 24 heures est fermée : envoyez un modèle de message approuvé par Meta.' },
      { p: 'Le nom d’affichage est refusé. Utilisez le nom de votre page Facebook ou de votre enseigne, sans mot générique seul, et identique à vos documents.' },
      { tip: 'Besoin d’aide ? L’équipe ClientlyX configure avec vous, par écran partagé ou sur place à Libreville : cliquez sur « Me faire aider par ClientlyX » dans Paramètres › WhatsApp, ou écrivez-nous sur WhatsApp. Pas encore de compte ? Créez-le gratuitement, puis revenez à ce guide.' },
    ],
  },
  {
    slug: 'badge-verifie-whatsapp-business',
    title: 'Badge vérifié sur WhatsApp : à quoi il sert et comment l’obtenir',
    excerpt: 'Le badge rassure vos clients et limite les arnaques à votre nom. Voici les conditions, les démarches et ce qu’il faut faire en attendant.',
    category: 'API WhatsApp',
    date: '2026-11-10',
    icon: 'shield',
    sectors: ['Services pro', 'Boutique', 'Beauté'],
    body: [
      { p: 'Les faux comptes qui se font passer pour une boutique ou une agence connue se multiplient. Le badge de vérification indique à vos clients qu’ils parlent bien à votre entreprise.' },
      { h: 'Ce que le badge change' },
      { ul: [
        'Votre nom d’entreprise s’affiche à la place du numéro, même si le client ne l’a pas enregistré.',
        'Vos messages inspirent confiance, en particulier les demandes de paiement.',
        'Il devient plus difficile pour un escroc d’imiter votre marque.',
      ] },
      { h: 'Les deux voies possibles' },
      { p: 'Meta propose aujourd’hui un abonnement « Meta Verified » pour les entreprises dans de nombreux pays, et un statut de compte professionnel officiel que l’on demande depuis le gestionnaire WhatsApp quand on utilise l’API. Les conditions et la disponibilité évoluent : vérifiez ce qui est ouvert dans votre pays au moment de la demande.' },
      { h: 'Ce que Meta regarde' },
      { ul: [
        'Une entreprise vérifiée dans le portefeuille business Meta.',
        'Une marque connue du public : articles de presse, site web, réseaux sociaux actifs. Les liens payants ou promotionnels ne comptent pas.',
        'Un nom affiché identique à la marque.',
        'La double authentification activée sur le compte.',
      ] },
      { h: 'En attendant le badge' },
      { ul: [
        'Affichez votre numéro officiel partout : vitrine, cartes, site, réseaux sociaux.',
        'Prévenez vos clients que vous ne demandez jamais de paiement vers un numéro personnel.',
        'Utilisez toujours le même nom et le même logo sur WhatsApp, Facebook et votre site.',
      ] },
      { tip: 'Un refus n’est pas définitif : vous pouvez refaire une demande quand votre présence en ligne s’est renforcée. Ce qui compte d’abord pour vos clients, c’est un numéro unique, connu et qui répond vite.' },
    ],
  },
  {
    slug: 'whatsapp-non-officiel-risques',
    title: 'WhatsApp modifié, envois en masse, extensions : pourquoi votre numéro risque d’être bloqué',
    excerpt: 'Les applications modifiées et les outils d’envoi en masse promettent des milliers de messages gratuits. Le prix réel : un numéro bloqué et des clients perdus.',
    category: 'Choisir un outil',
    date: '2026-11-10',
    icon: 'bell',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'On les trouve partout : versions modifiées de WhatsApp, extensions de navigateur, logiciels qui envoient des messages en boucle depuis un téléphone. Ils sont tentants pour une PME qui veut écrire à tous ses clients sans payer. Ils sont aussi la première cause de numéros bloqués.' },
      { h: 'Trois façons d’utiliser WhatsApp, un seul vrai choix' },
      { ul: [
        'WhatsApp personnel : pour la famille et les amis. Pas d’outils professionnels, et mélanger vie privée et clients fait perdre des messages.',
        'WhatsApp Business (application) et l’API officielle : les deux voies prévues par Meta pour les entreprises.',
        'Les outils non officiels : ils pilotent un compte WhatsApp ordinaire à la place d’un humain. Meta les détecte et bloque les numéros.',
      ] },
      { h: 'Ce que vous risquez' },
      { ul: [
        'Le blocage du numéro, parfois définitif, avec tous les contacts et l’historique.',
        'Des clients qui signalent vos messages comme indésirables, ce qui accélère le blocage.',
        'Des données clients copiées sur des serveurs inconnus.',
        'Aucune mesure fiable : impossible de savoir qui a lu ou qui est revenu.',
      ] },
      { h: 'La bonne méthode' },
      { ul: [
        'Recueillir l’accord du client (en caisse, à l’inscription, par QR code) et le noter.',
        'Écrire peu mais au bon moment : un rappel, une relance, un anniversaire.',
        'Passer par l’API officielle dès que les volumes augmentent.',
      ] },
      { tip: 'ClientlyX CRM n’utilise que les voies officielles : l’envoi en un clic depuis votre propre WhatsApp, ou l’API WhatsApp de Meta. Le consentement de chaque client est daté et le mot STOP est respecté automatiquement.' },
    ],
  },
  {
    slug: 'choisir-outil-whatsapp-pme-afrique',
    title: 'Choisir un outil WhatsApp pour sa PME en Afrique : les 8 critères qui comptent',
    excerpt: 'Il existe des dizaines de plateformes WhatsApp. Pour une PME gabonaise ou ivoirienne, le prix affiché ne suffit pas. Voici la grille pour comparer.',
    category: 'Choisir un outil',
    date: '2026-11-10',
    icon: 'grid',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'La plupart des plateformes WhatsApp ont été pensées pour l’Inde, l’Europe ou l’Amérique : prix en dollars, paiement par carte, interface en anglais, fonctions conçues pour le e-commerce. Avant de choisir, posez ces huit questions.' },
      { h: 'La grille de comparaison' },
      { ul: [
        '1. Est-ce l’API officielle de Meta ? Sinon, votre numéro est en danger.',
        '2. Le prix est-il en francs CFA, payable par Mobile Money ? Une carte bancaire internationale bloque beaucoup de PME.',
        '3. L’outil connaît-il vos clients ? Une simple boîte d’envoi ne sait pas qui est absent depuis deux mois ni qui a dépensé le plus.',
        '4. Gère-t-il vos rendez-vous, abonnements ou ventes ? Sinon, il faut ressaisir les données dans un autre logiciel.',
        '5. Plusieurs personnes peuvent-elles répondre depuis le même numéro, avec des droits différents ?',
        '6. Le support parle-t-il français, sur WhatsApp, à vos heures ?',
        '7. Pouvez-vous l’installer seul, sans payer un intégrateur ?',
        '8. Vos données restent-elles à vous, exportables à tout moment ?',
      ] },
      { h: 'Les pièges fréquents' },
      { ul: [
        'Un abonnement bas, mais les messages revendus beaucoup plus cher que le tarif de Meta.',
        'Des fonctions utiles (campagnes, plusieurs agents) réservées à l’offre la plus chère.',
        'Un engagement annuel obligatoire.',
      ] },
      { h: 'Par métier, ce qu’il faut en plus' },
      { ul: [
        'Beauté : agenda par intervenante et rappel la veille.',
        'Sport : formules, paiements en plusieurs fois, annulation de cours et rattrapages.',
        'Services pro : suivi des prospects, devis et relances de paiement.',
        'Boutique : historique d’achats par marque et programme de fidélité.',
      ] },
      { tip: 'ClientlyX CRM a été conçu pour cette grille : API officielle, prix en FCFA dès 9 900 par mois, Mobile Money, support en français sur WhatsApp, et un fichier client qui sait qui relancer. Essai de 14 jours sans carte bancaire.' },
    ],
  },
  {
    slug: 'sms-ou-whatsapp-relances-clients',
    title: 'SMS ou WhatsApp : quel canal pour vos rappels et relances clients ?',
    excerpt: 'Le SMS touche tout le monde, WhatsApp obtient plus de réponses. Comparaison honnête et méthode pour combiner les deux.',
    category: 'Bien démarrer',
    date: '2026-11-10',
    icon: 'message',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'Pendant longtemps, le SMS était le seul moyen de joindre un client. Aujourd’hui, la majorité de vos clients citadins ont WhatsApp. Faut-il abandonner le SMS ? Pas forcément.' },
      { h: 'Ce que WhatsApp fait mieux' },
      { ul: [
        'Le client répond directement : « je confirme », « je décale à jeudi ».',
        'Photos, catalogue, localisation du salon, lien de paiement dans le même message.',
        'Vous voyez si le message a été lu.',
        'Le ton est plus proche, plus personnel.',
      ] },
      { h: 'Ce que le SMS fait mieux' },
      { ul: [
        'Il arrive même sans connexion internet ni forfait data.',
        'Il touche les clients plus âgés ou ceux qui n’ont pas de smartphone.',
        'Aucune validation préalable du message.',
      ] },
      { h: 'La méthode qui marche' },
      { ul: [
        'WhatsApp par défaut pour les rappels, relances, campagnes et anniversaires.',
        'Le SMS en secours pour les messages importants (rappel de rendez-vous, échéance de paiement) quand le client n’a pas WhatsApp.',
        'Un seul message par sujet : ne doublez pas SMS et WhatsApp, vous agaceriez le client.',
        'Demandez au client son canal préféré dès sa première visite, et notez-le sur sa fiche.',
      ] },
      { tip: 'Dans ClientlyX CRM, la fiche client indique si le client a donné son accord pour WhatsApp. Les segments vous montrent d’un coup d’œil ceux qui restent à joindre autrement.' },
    ],
  },
];

export const postBySlug = (slug: string) => posts.find((p) => p.slug === slug);

export function readingMinutes(post: Post) {
  const text = post.body.map((b) => ('p' in b ? b.p : 'h' in b ? b.h : 'tip' in b ? b.tip : 'see' in b ? b.see : 'ol' in b ? b.ol.join(' ') : 'table' in b ? b.table.rows.flat().join(' ') : b.ul.join(' '))).join(' ');
  return Math.max(2, Math.round(text.split(/\s+/).length / 200));
}

export const formatDate = (d: string) =>
  new Date(`${d}T12:00:00Z`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
