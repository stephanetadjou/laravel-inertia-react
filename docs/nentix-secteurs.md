# NENTIX par secteur — guide (09/11/2026)

Branche : `feat/nentix-secteurs` (partie de `feat/menentia-ui-pro`).
Démo : `php artisan migrate` puis `php artisan demo:nentix-secteurs` (mot de passe de tous les comptes : `Demo-Nentix-2026`).

| Secteur | Compte de démo | Commercial |
|---|---|---|
| Beauté et salons | `beaute@demo-nentix.test` | `beaute.commercial@demo-nentix.test` |
| Sport et bien-être | `sport@demo-nentix.test` | `sport.commercial@demo-nentix.test` |
| Services pro et agences | `pro@demo-nentix.test` | `pro.commercial@demo-nentix.test` |
| Boutiques | `boutique@demo-nentix.test` | — |

Pour tester les réponses automatiques sans compte Meta : `WHATSAPP_DRIVER=log` dans `.env` (les envois sont écrits dans les logs et apparaissent dans Discussions).

## 1. Inscription : le secteur seulement

`/register` ne demande plus que le nom, le secteur (4 cartes) et le compte. Le secteur déclenche `App\Support\SectorPresets::apply()` :

| | Étapes de suivi (renommables) | Modules actifs | En option (désactivés) |
|---|---|---|---|
| **Beauté** | Nouveau contact → RDV réservé → RDV confirmé → Venue → À relancer | Agenda, Catalogue, Suivi, Réponses auto, Rappel de la veille avec confirmation, Relance par cycle | Fidélité, Vente de produits |
| **Sport** | Prospect → Essai planifié → Essai fait → Abonné → À renouveler | Agenda (pour l’essai), Formules et abonnements, Séance d’essai, Cours et appel, Paiement en plusieurs fois, Catalogue, Suivi, Réponses auto | Fidélité, Personnes suivies, Plusieurs lieux |
| **Services pro** | À contacter → En discussion → Devis envoyé → Gagné / Perdu (raison obligatoire) | Suivi des prospects (+ commerciaux et objectifs), Relance du devis, Réponses auto | Agenda, Catalogue, Paiement en plusieurs fois |
| **Boutiques** | Étapes calculées du parcours client (Contact → Nouveau client → Client actif → À risque) | Analyse commerciale (familles), Fidélité, Plusieurs lieux, Réponses auto, import des ventes | Catalogue, Paiement en plusieurs fois |

Le préréglage crée aussi les réponses automatiques du secteur, les messages de relance de « À faire » et la liste « Pour bien démarrer » (Connecter WhatsApp → Vérifier les réponses automatiques → Suivre vos premiers prospects → …). Il se rejoue sans doublon (Paramètres › Réponses automatiques › « Réappliquer le préréglage ») : il ne touche plus aux modules ni aux étapes déjà réglés par l’entreprise. Paramètres › Modules reste modifiable.

## 2. Workflows

### Un client clique sur une pub « Click to WhatsApp »
1. Meta envoie le message au webhook (par établissement ou webhook unique NENTIX) → `ProcessWhatsAppWebhookJob`.
2. Numéro inconnu : contact + fiche client créés avec le nom du profil WhatsApp ; `messages[].referral` donne la source (**Pub Instagram** si l’URL de la pub est Instagram, sinon **Pub Facebook** ; sans referral : **Direct**). Données brutes (source_id, headline, ctwa_clid…) dans `customers.acquisition_data`.
3. Suivi des prospects actif : la fiche entre à la première étape.
4. Robot (`App\Services\AutoReplies`) : hors horaires → message d’absence (1 fois / 24 h) ; sinon accueil + menu (1 fois / 24 h), en boutons (3 choix) ou en liste (4–5 choix).
5. Choix du menu, ou mot-clé dans un message court (« prix », « horaires », « rdv », « rappel », « conseiller ») :
   - **Nos prix** : 10 premiers prix du catalogue ;
   - **Nos horaires** : horaires de l’établissement ;
   - **Prendre RDV** : lien de réservation en ligne (sans réservation en ligne ouverte, devient « Être rappelé ») ;
   - **Être rappelé** : tâche dans « À faire », discussion « à traiter » ;
   - **Parler à quelqu’un** : discussion « à traiter », tâche, robot coupé 24 h pour ce contact.
6. La source apparaît sur la fiche, dans la liste des fiches et dans Discussions ; Analyses › **Sources** compte prospects, RDV, nouveaux clients, ventes et CA par source et par mois ; le budget pub saisi donne le coût par client.

### Rappel de la veille (beauté)
`crm:appointment-reminders` (planifié à 17:00) : pour chaque RDV de demain pas encore rappelé, message avec **Je confirme / Je décale**. Fenêtre de 24 h ouverte → message interactif ; sinon le modèle Meta indiqué dans Paramètres › Réponses automatiques (3 variables : prénom, date, heure ; 2 boutons de réponse rapide) ; ni l’un ni l’autre → le rappel reste dans « À faire » (lien wa.me) comme avant. « Je confirme » → RDV confirmé (et étape « RDV confirmé ») ; « Je décale » → tâche « Rendez-vous à recaser », discussion à traiter.

### Agenda et étapes
Un RDV pris fait passer à « RDV réservé » (ou « Essai planifié » pour une séance d’essai), une confirmation à « RDV confirmé », un RDV honoré à « Venue » (ou « Essai fait »). Jamais de retour en arrière ; la première vente passe à l’étape gagnée.

### Abonnement avec planning des séances (sport, et tout secteur avec agenda)
À la création d’un abonnement (Ventes › Formules ou fiche client › Abonnements), le bloc « Séances dans l’agenda » demande les jours et heures (ex. mardi 18h et jeudi 7h30), la durée, et si la séance est **suivie par un coach** (lequel) ou **en autonomie**. Chaque séance devient un rendez-vous dans l’agenda (avec le coach), sur 8 semaines glissantes prolongées chaque nuit (`crm:subscription-planning`, 03:00). Le bouton « Planning » d’un abonnement modifie les jours, heures et le suivi : les séances à venir non honorées sont remplacées. Arrêter l’abonnement retire ses séances à venir. Limite : pas de contrôle de capacité du créneau ni des horaires du coach (l’agenda montre les chevauchements).

### « À faire »
Nouvelles listes : **Demandes à traiter** (tâches : parler à quelqu’un, être rappelé, RDV à recaser), **Clientes à faire revenir** (champ « Revenir après N jours » de chaque prestation du catalogue), **Devis à relancer** (étape « Devis envoyé » depuis 3 jours, message prêt). Sport : « Essai fait » a un délai de relance d’1 jour (liste « Prospects à relancer », message pour proposer l’abonnement). Chaque étape garde son délai de relance réglable (Paramètres › Prospects).

### Discussions (ordinateur)
Menu **Discussions** : liste des clients qui ont écrit (dernier message, source, à traiter, attribution), fil (reçus, réponses, robot, rappels, campagnes), réponse libre tant que le client a écrit il y a moins de 24 h, sinon un modèle approuvé ({{1}} = prénom), attribution à un utilisateur, filtres **À traiter** et **Les miennes**, bouton **Marquer traitée** (ferme les tâches, relance le robot).

### Prospects
Vue **Tableau** : glisser-déposer natif HTML5 entre les colonnes (« Perdu » demande la raison) ; la vue **Liste** reste.

### Crédits de messages
`organizations.message_credits` (FCFA ; `null` = sans limite pour les entreprises historiques ; une nouvelle inscription démarre à 0). Coût d’un message marketing : `/admin/organizations/{id}` › Crédits de messages (défaut `NENTIX_MARKETING_MESSAGE_COST`, 50 FCFA). Avant une campagne : estimation (destinataires × coût, solde) ; envoi refusé si le solde est insuffisant ; le coût est débité au lancement (`credit_movements`). Messages de session, réponses automatiques, rappels et modèles utilitaires : gratuits.

## 3. Décisions prises (sans validation, propriétaire absent)
- **Boutiques = clé `commerce`** : les 10 contrôles existants (`sector === 'commerce'`) restent valables ; `boutique` est accepté comme alias à l’inscription. Les anciennes valeurs (sante, technique, autre) restent valides pour les comptes existants mais ne sont plus proposées.
- **Ancien formulaire accepté** : un POST `/register` avec `answers` garde l’ancien comportement (modules du secteur ajustés par les réponses), sans préréglage.
- **Boutiques sans Kanban** : leurs étapes sont celles, calculées, du parcours client existant (pas de module Prospects).
- **Sport avec agenda** : la séance d’essai est un rendez-vous (type « Séance d’essai »), donc le module Agenda est actif.
- **Horaires du robot** = horaires de réservation de l’établissement (`stores.booking_hours`, réglés dans Paramètres › Réservation en ligne ; défaut lun.–sam.). Fuseau : celui de l’entreprise.
- **Tâches** : nouvelle table `tasks`, affichée comme liste « Demandes à traiter » de « À faire » (le bouton « Fait » ferme la tâche).
- **Crédits débités au lancement**, sans remboursement automatique des échecs (une correction se fait par un mouvement manuel dans /admin).
- Nouveaux modules (désactivables) : `reponses_auto`, `rappels_rdv`, `relance_cycle`, `essai`, `relance_devis`.
- Discussions visibles même sans API (avec une explication) ; l’envoi exige l’API activée.

## 4. Limites connues
- Pas de captures d’écran dans ce guide : Playwright n’est pas installé sur le PC de développement.
- Le rappel par modèle suppose un modèle Meta à 3 variables et 2 boutons de réponse rapide.
- Réponse par modèle dans Discussions : toutes les variables reçoivent le prénom.
- Pas de remboursement automatique des crédits en cas d’échec d’envoi.
- La « Relance par cycle » lit les prestations des rendez-vous honorés (pas les ventes importées sans rendez-vous).
- Reporté : paiement Mobile Money intégré, Embedded Signup / 360Dialog, Instagram et Messenger, application mobile.

## 5. Fichiers principaux
`app/Support/SectorPresets.php`, `app/Support/AutoReplyDefaults.php`, `app/Services/{AutoReplies,WhatsAppConversation,Acquisition,SourceReport}.php`, `app/Jobs/ProcessWhatsAppWebhookJob.php`, `app/Console/Commands/{SendAppointmentReminders,SeedNentixSectorsDemo}.php`, `app/Http/Controllers/{ConversationController,AutoReplySettingsController,SourceReportController}.php`, pages `Discussions/Index`, `Settings/AutoReplies`, `Analytics/Sources`, migration `2026_11_09_100000_nentix_secteurs.php`, tests `tests/Feature/NentixSecteurs/`.

## 6. Site vitrine
La dernière version du site NENTIX (`docs/Menentia doc/nentix-site.zip`, du 05/11) est désormais suivie dans `docs/nentix-site/` (Next.js, même `package.json` que l’ancien site). Mises à jour : les 4 secteurs (Beauté et salons, Sport et bien-être, Services pro et agences, Boutiques ; pages Santé et Services techniques retirées), solutions de chaque secteur réécrites autour des nouveaux workflows, fonctionnalités désormais disponibles (fiche au premier message et source de la pub, réponses automatiques, rappel à boutons, boîte de réception partagée, crédits de messages, tableau par source). `npx tsc` et `next build` passent. Lancement : `cd docs/nentix-site && npm install && npm run dev`.
