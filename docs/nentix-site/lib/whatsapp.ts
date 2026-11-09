// Contenu du guide WhatsApp (page /whatsapp), repris aussi par l’assistant du site.

import type { Status } from './site';

export const questions = [
  { q: '« Vous êtes ouverts dimanche ? »', who: 'Tous les métiers', text: 'Horaires, jours fériés, pause de midi. La question la plus fréquente, et celle qui arrive le soir, quand vous êtes fermé.' },
  { q: '« Il vous reste une place samedi ? »', who: 'Salons, spas, coachs', text: 'Une demande de rendez-vous. Sans réponse dans l’heure, la cliente écrit au salon d’à côté.' },
  { q: '« C’est combien pour des tresses ? »', who: 'Beauté et soins', text: 'Les prix. Une liste claire évite dix allers-retours et fait venir la cliente avec la bonne attente.' },
  { q: '« Je peux décaler à 15 h ? »', who: 'Santé, garages, cabinets', text: 'Un rendez-vous à déplacer. Si personne ne met l’agenda à jour, le créneau reste bloqué pour rien.' },
  { q: '« Vous êtes où exactement ? »', who: 'Tous les métiers', text: 'Le quartier, le repère (« après la pharmacie »), la localisation. Simple, mais répété dix fois par jour.' },
  { q: '« Je peux payer par Airtel Money ? »', who: 'Tous les métiers', text: 'Les moyens de paiement. Mobile Money, espèces ou carte : à dire avant la visite.' },
];

export const columns = ['Application WhatsApp Business', 'NENTIX, sans l’API', 'NENTIX, avec l’API'];
export const rows: { label: string; cells: [string, string, string] }[] = [
  { label: 'Qui répond', cells: ['Vous, à la main', 'Vous, avec le message déjà prêt', 'Vous, puis bientôt l’assistant IA'] },
  { label: 'Rappels de rendez-vous', cells: ['À taper un par un', 'Listés chaque jour, envoyés en un clic', 'Envoyés en nombre, statuts remis et lu'] },
  { label: 'Prise de rendez-vous', cells: ['À noter vous-même', 'Lien de réservation dans votre statut', 'Lien de réservation, et bientôt par l’assistant'] },
  { label: 'Clients à relancer', cells: ['Personne ne vous prévient', 'Alerte de départ et liste du jour', 'Campagnes ciblées par étape et groupe'] },
  { label: 'Suivi du client', cells: ['Rien de tracé', 'Chaque relance notée sur sa fiche', 'Historique des messages sur sa fiche'] },
  { label: 'Coût', cells: ['Gratuit', 'Compris dans votre offre NENTIX', 'Offre NENTIX + messages facturés par Meta'] },
];

export const rules = [
  { t: 'La fenêtre de 24 heures', d: 'Quand un client vous écrit, vous pouvez lui répondre librement pendant 24 heures.' },
  { t: 'Le modèle validé', d: 'Pour écrire le premier, ou au-delà de 24 heures, l’API exige un modèle de message validé à l’avance par Meta.' },
  { t: 'Le consentement', d: 'Une promotion suppose l’accord du client, daté et noté. Un rappel de rendez-vous qu’il attend n’est pas une promotion.' },
  { t: 'Le mot « Stop »', d: 'Le client doit pouvoir arrêter les messages à tout moment, d’un simple mot. Il ne reçoit plus rien ensuite.' },
  { t: 'Les données sensibles', d: 'Une ordonnance ou un dossier médical ne doit pas traîner dans un téléphone, ni être gardé plus que nécessaire.' },
  { t: 'L’assistant se présente', d: 'Un assistant automatique dit qu’il en est un, et passe la main à une personne quand on le lui demande.' },
];

export const menentia: { title: string; text: string; status: Status }[] = [
  { title: 'Listes de relance du jour', text: 'Rendez-vous à rappeler, absents à reprogrammer, clients à risque, anniversaires : chacun avec son message prêt, modifiable.', status: 'dispo' },
  { title: 'Envoi en un clic depuis votre téléphone', text: 'Le message s’ouvre dans WhatsApp, déjà rempli. Aucun compte Meta nécessaire.', status: 'dispo' },
  { title: 'Lien de réservation', text: 'À mettre dans votre statut et votre profil. Le client réserve seul et reçoit son récapitulatif sur WhatsApp.', status: 'dispo' },
  { title: 'Consentement par QR code', text: 'Une affiche en caisse : le client scanne, écrit « OUI », son accord est daté et sourcé.', status: 'dispo' },
  { title: 'Campagnes par l’API officielle', text: 'Avec votre compte WhatsApp Business API : modèles Meta, envoi par lots, statuts remis et lu, coût estimé avant l’envoi.', status: 'dispo' },
  { title: 'Réponse « Stop » respectée', text: 'Le client qui répond « Stop » perd son accord automatiquement et ne reçoit plus de campagne.', status: 'dispo' },
  { title: 'Rappels automatiques', text: 'La veille et 2 h avant, avec réponse « 1 = je confirme, 2 = j’annule ».', status: 'bientot' },
  { title: 'Assistant IA sur WhatsApp', text: 'Il répond jour et nuit à partir de votre fiche (horaires, adresse, prix), propose vos vrais créneaux et vous passe la main quand il ne sait pas.', status: 'bientot' },
];

export const faq = [
  { q: 'Puis-je utiliser NENTIX sans l’API WhatsApp ?', a: 'Oui, c’est même le mode par défaut à l’inscription. NENTIX prépare les messages et vous les envoyez en un clic depuis le WhatsApp de votre établissement.' },
  { q: 'Quelle différence entre l’application et l’API ?', a: 'L’application WhatsApp Business est gratuite et s’utilise à la main sur un téléphone. L’API officielle de Meta permet à un logiciel comme NENTIX d’envoyer des messages en nombre, de suivre leur statut et, bientôt, de répondre seul.' },
  { q: 'Combien coûte l’API WhatsApp ?', a: 'Meta facture les messages envoyés hors fenêtre de 24 heures, selon leur catégorie et le pays du client. NENTIX affiche le coût estimé avant chaque campagne.' },
  { q: 'Le client doit-il donner son accord ?', a: 'Pour une promotion, oui. NENTIX enregistre la date et la source de chaque accord (QR code, réservation en ligne, import) et n’envoie aucune campagne à un client sans accord.' },
  { q: 'Faut-il un numéro dédié ?', a: 'Sans l’API, vous gardez votre numéro actuel. Pour l’API, on conseille un numéro réservé à l’établissement ; selon votre situation, Meta permet parfois de garder aussi l’application sur le même numéro. Nous vérifions ce point avec vous à l’installation.' },
  { q: 'L’assistant IA peut-il se tromper ?', a: 'Il ne répond qu’à partir des informations de votre fiche et de votre agenda. Quand il ne sait pas, il le dit et vous transmet la question. Il arrive avec une prochaine version.' },
];

// Section « Connecter l’API WhatsApp » : le parcours d’une entreprise qui branche son propre compte Meta.
export const connectPrereqs = [
  'Un numéro de téléphone dédié, qui n’est pas déjà utilisé dans l’application WhatsApp (ou que vous en retirez)',
  'Un compte Facebook pour se connecter à Meta Business',
  'Le nom de votre entreprise tel que vos clients le connaissent',
  'Un compte NENTIX (l’essai gratuit suffit)',
];

export const connectSteps: { where: 'Meta' | 'NENTIX'; t: string; d: string }[] = [
  { where: 'Meta', t: 'Créer le compte Meta Business', d: 'Sur business.facebook.com, créez le portefeuille de votre entreprise. C’est lui qui portera votre compte WhatsApp.' },
  { where: 'Meta', t: 'Créer l’application et ajouter WhatsApp', d: 'Sur developers.facebook.com, créez une application de type Business et ajoutez-lui le produit WhatsApp.' },
  { where: 'Meta', t: 'Ajouter et vérifier votre numéro', d: 'Saisissez votre numéro, recevez le code par SMS ou appel, puis choisissez le nom affiché à vos clients.' },
  { where: 'Meta', t: 'Générer un jeton permanent', d: 'Créez un utilisateur système, donnez-lui accès à l’application et au compte WhatsApp, puis générez son jeton d’accès.' },
  { where: 'NENTIX', t: 'Coller vos identifiants dans NENTIX', d: 'Dans Paramètres › WhatsApp, collez l’identifiant du numéro, celui du compte WhatsApp, le jeton et la clé secrète de l’application, puis cliquez sur « Tester ».' },
  { where: 'Meta', t: 'Brancher le webhook', d: 'Copiez l’adresse et le code de vérification affichés par NENTIX dans la configuration WhatsApp de Meta, et abonnez le champ « messages ». NENTIX reçoit alors les statuts et les réponses.' },
  { where: 'NENTIX', t: 'Valider vos modèles et envoyer', d: 'Synchronisez vos modèles de message (rappel, bienvenue, promotion). Une fois validés par Meta, envoyez votre première campagne.' },
];
