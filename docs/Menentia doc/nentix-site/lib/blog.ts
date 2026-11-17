// Articles du blog. Textes originaux NENTIX (sujets inspirés d’une veille sur
// WhatsApp Business, aucun texte repris). Ajouter un article = ajouter un objet ici.

export type Block =
  | { h: string }
  | { p: string }
  | { ul: string[] }
  | { tip: string };

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
      { p: 'À Libreville, Douala ou Abidjan, vos clients vous écrivent d’abord sur WhatsApp. Pour une entreprise, il existe deux façons officielles de l’utiliser : l’application WhatsApp Business, gratuite, et l’API WhatsApp Business (appelée aussi « plateforme WhatsApp Business »), que l’on branche à un logiciel comme NENTIX CRM. Les deux ne répondent pas au même besoin.' },
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
      { tip: 'Avec NENTIX CRM, l’offre Solo fonctionne avec votre WhatsApp actuel : le message de relance est préparé, vous l’envoyez d’un clic depuis votre téléphone. Les offres Équipe et Premium se branchent sur l’API officielle quand vous êtes prêt.' },
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
      { tip: 'NENTIX CRM prépare ces dix scénarios pour vous : il repère les clients concernés chaque matin et vous propose le message prêt à partir. Vous gardez la main sur ce qui est envoyé.' },
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
        'Le coût du logiciel : l’abonnement à l’outil qui affiche les conversations et envoie les messages (par exemple NENTIX CRM).',
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
      { tip: 'Les prix varient selon le pays du destinataire, et Meta les révise régulièrement. Avant de lancer une grosse campagne, consultez la grille officielle de Meta pour votre pays. Dans NENTIX CRM, le nombre de messages envoyés par catégorie est affiché dans les campagnes, pour garder la maîtrise du budget.' },
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
    title: 'Brancher l’API WhatsApp officielle : le guide étape par étape',
    excerpt: 'Compte Meta, vérification de l’entreprise, numéro, modèles de messages : ce qu’il faut préparer et dans quel ordre, sans être informaticien.',
    category: 'API WhatsApp',
    date: '2026-11-10',
    icon: 'play',
    sectors: ['Beauté', 'Sport', 'Services pro', 'Boutique'],
    body: [
      { p: 'Connecter l’API WhatsApp fait peur, mais c’est surtout une affaire de préparation. Comptez une à deux heures de manipulation, puis quelques jours d’attente pour les validations de Meta.' },
      { h: 'Avant de commencer, préparez' },
      { ul: [
        'Un document officiel de l’entreprise (RCCM, NIF ou équivalent) et une facture récente au nom de l’entreprise.',
        'Un numéro de téléphone qui recevra un code par SMS ou appel. S’il est déjà utilisé sur WhatsApp, il faudra le libérer ou utiliser la coexistence quand elle est disponible dans votre pays.',
        'Un site web ou une page Facebook de l’entreprise, avec le même nom et la même adresse que sur vos documents.',
        'Un moyen de paiement accepté par Meta pour les messages facturés.',
      ] },
      { h: 'Les étapes' },
      { ul: [
        '1. Créer un portefeuille business Meta (business.facebook.com) au nom exact de l’entreprise.',
        '2. Lancer la vérification de l’entreprise et envoyer les documents. Meta répond en général en quelques jours.',
        '3. Créer un compte WhatsApp Business dans le gestionnaire WhatsApp, choisir le nom affiché (il doit correspondre à votre marque).',
        '4. Ajouter le numéro et le valider avec le code reçu.',
        '5. Relier le compte à votre logiciel : dans NENTIX CRM, Paramètres › WhatsApp vous guide et vérifie chaque information.',
        '6. Rédiger vos premiers modèles de messages (rappel, relance, anniversaire) et les soumettre à Meta.',
        '7. Envoyer un message test à votre propre numéro, puis activer les envois automatiques.',
      ] },
      { h: 'Les erreurs qui bloquent le plus souvent' },
      { ul: [
        'Un nom d’entreprise différent entre les documents, le site et le compte Meta.',
        'Un nom affiché trop générique (« Salon de beauté ») : Meta le refuse.',
        'Des modèles marketing classés en utilitaire : Meta les reclasse ou les rejette.',
        'Un numéro encore actif sur un téléphone, ce qui empêche l’enregistrement.',
      ] },
      { tip: 'NENTIX CRM détaille ces sept étapes avec des captures sur la page WhatsApp du site, et le support répond sur WhatsApp si vous bloquez. Vous pouvez tout faire seul, sans technicien.' },
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
      { tip: 'NENTIX CRM n’utilise que les voies officielles : l’envoi en un clic depuis votre propre WhatsApp, ou l’API WhatsApp de Meta. Le consentement de chaque client est daté et le mot STOP est respecté automatiquement.' },
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
      { tip: 'NENTIX CRM a été conçu pour cette grille : API officielle, prix en FCFA dès 9 900 par mois, Mobile Money, support en français sur WhatsApp, et un fichier client qui sait qui relancer. Essai de 14 jours sans carte bancaire.' },
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
      { tip: 'Dans NENTIX CRM, la fiche client indique si le client a donné son accord pour WhatsApp. Les segments vous montrent d’un coup d’œil ceux qui restent à joindre autrement.' },
    ],
  },
];

export const postBySlug = (slug: string) => posts.find((p) => p.slug === slug);

export function readingMinutes(post: Post) {
  const text = post.body.map((b) => ('p' in b ? b.p : 'h' in b ? b.h : 'tip' in b ? b.tip : b.ul.join(' '))).join(' ');
  return Math.max(2, Math.round(text.split(/\s+/).length / 200));
}

export const formatDate = (d: string) =>
  new Date(`${d}T12:00:00Z`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
