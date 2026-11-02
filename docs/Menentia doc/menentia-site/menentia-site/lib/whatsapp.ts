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

export const columns = ['Application WhatsApp Business', 'Menentia, sans l’API', 'Menentia, avec l’API'];
export const rows: { label: string; cells: [string, string, string] }[] = [
  { label: 'Qui répond', cells: ['Vous, à la main', 'Vous, avec le message déjà prêt', 'Vous, puis bientôt l’assistant IA'] },
  { label: 'Rappels de rendez-vous', cells: ['À taper un par un', 'Listés chaque jour, envoyés en un clic', 'Envoyés en nombre, statuts remis et lu'] },
  { label: 'Prise de rendez-vous', cells: ['À noter vous-même', 'Lien de réservation dans votre statut', 'Lien de réservation, et bientôt par l’assistant'] },
  { label: 'Clients à relancer', cells: ['Personne ne vous prévient', 'Alerte de départ et liste du jour', 'Campagnes ciblées par étape et groupe'] },
  { label: 'Suivi du client', cells: ['Rien de tracé', 'Chaque relance notée sur sa fiche', 'Historique des messages sur sa fiche'] },
  { label: 'Coût', cells: ['Gratuit', 'Compris dans votre offre Menentia', 'Offre Menentia + messages facturés par Meta'] },
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
  { q: 'Puis-je utiliser Menentia sans l’API WhatsApp ?', a: 'Oui, c’est même le mode par défaut à l’inscription. Menentia prépare les messages et vous les envoyez en un clic depuis le WhatsApp de votre établissement.' },
  { q: 'Quelle différence entre l’application et l’API ?', a: 'L’application WhatsApp Business est gratuite et s’utilise à la main sur un téléphone. L’API officielle de Meta permet à un logiciel comme Menentia d’envoyer des messages en nombre, de suivre leur statut et, bientôt, de répondre seul.' },
  { q: 'Combien coûte l’API WhatsApp ?', a: 'Meta facture les messages envoyés hors fenêtre de 24 heures, selon leur catégorie et le pays du client. Menentia affiche le coût estimé avant chaque campagne.' },
  { q: 'Le client doit-il donner son accord ?', a: 'Pour une promotion, oui. Menentia enregistre la date et la source de chaque accord (QR code, réservation en ligne, import) et n’envoie aucune campagne à un client sans accord.' },
  { q: 'Faut-il un numéro dédié ?', a: 'Sans l’API, vous gardez votre numéro actuel. Pour l’API, on conseille un numéro réservé à l’établissement ; selon votre situation, Meta permet parfois de garder aussi l’application sur le même numéro. Nous vérifions ce point avec vous à l’installation.' },
  { q: 'L’assistant IA peut-il se tromper ?', a: 'Il ne répond qu’à partir des informations de votre fiche et de votre agenda. Quand il ne sait pas, il le dit et vous transmet la question. Il arrive avec une prochaine version.' },
];
