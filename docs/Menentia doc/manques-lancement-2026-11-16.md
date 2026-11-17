# ClientlyX : ce qu'il manque pour le lancement V1 (15/12, Libreville)

Analyse du code au 16/11/2026, sur la branche locale non commitée. Règle : WhatsApp seul canal en V1, renforcer le cycle client, pas d'ERP.
Classement : **BLOQUANT** (impossible de lancer sans), **IMPORTANT** (attendu par les clients du secteur), **PLUS TARD**. Effort en jours de développement.

## Transversal

| Manque | Constat dans le code | Classe | Effort |
|---|---|---|---|
| Sécurité des dépendances | `composer audit` : 25 alertes sur 7 paquets, dont 1 **critique** sur Laravel (contournement du TokenGuard). `npm audit` : 3 alertes hautes. | BLOQUANT | 0,5 |
| Déploiement de production | Azure App Service prévu mais aucun workflow ; `deploy.yml` (cPanel) est manuel. Il manque aussi la tâche planifiée `schedule:run`, le worker de file d'attente, HTTPS et des sauvegardes. | BLOQUANT | 2 à 3 |
| Paiement de l'abonnement ClientlyX | Aucune passerelle (Airtel Money, Moov Money, carte). L'essai de 14 jours existe, mais un essai expiré n'est ni bloqué ni relancé (il n'apparaît que dans /admin). La recharge des crédits se fait à la main. | BLOQUANT (au minimum une procédure manuelle : paiement mobile money, puis activation dans /admin et blocage à l'expiration) | 1 en manuel, 4 à 6 avec une passerelle |
| Mentions légales | Une politique de confidentialité par entreprise existe (/confidentialite/{slug}). Il manque les CGU / CGV de ClientlyX, les mentions légales et le traitement des données (APDPVP au Gabon). | BLOQUANT | 1 (+ relecture juridique) |
| WhatsApp hors fenêtre de 24 h | Le client et le webhook WhatsApp Cloud sont branchés, ainsi que l'envoi de modèles. Mais les rappels et relances automatiques (veille, tunnels, Location) ne partent que si le client a écrit dans les 24 h ou si un modèle Meta approuvé est réglé (aujourd'hui seulement pour le rappel de RDV). Il manque des modèles Meta prêts par usage (rappel, relance, confirmation) et leur choix dans chaque automatisation. | IMPORTANT (BLOQUANT pour vendre l'offre « automatique ») | 3 |
| Mode sans API comme socle | Toutes les relances existent en liens wa.me (À faire, Location, Tunnels). C'est utilisable dès le jour 1. | OK | — |
| Numéros hors Gabon | `PhoneNormalizer` n'accepte que +241 : les formulaires publics refusent +237, +33 (diaspora), +225, etc. | IMPORTANT (BLOQUANT pour les clients de la diaspora et le Cameroun) | 1 |
| Application mobile | Un manifeste PWA existe (installable), mais il n'y a ni service worker ni hors-ligne. Les écrans sont adaptés au téléphone, avec une barre du bas. | PLUS TARD (le PWA de base suffit) | 1 |
| Notifications à l'équipe | Les nouvelles demandes (lien public, course, tunnel, carte) n'arrivent que dans « À faire ». Aucune alerte WhatsApp ou e-mail n'est envoyée au gérant. | IMPORTANT | 1 |
| Multi-utilisateurs et rôles | Rôles, périmètre par établissement, journal d'audit : en place. | OK | — |
| Fuseau horaire | Africa/Libreville par défaut, réglable par entreprise. | OK | — |
| Suite de tests | 732 tests ; 1 en échec (`demo:nentix-secteurs` crée 7 entreprises au lieu de 6). | IMPORTANT (à corriger avant de livrer) | 0,2 |
| Onboarding | « Pour bien démarrer », modèles Excel, préréglages par secteur, démos : en place. | OK | — |

## Salon / beauté

| Manque | Constat | Classe | Effort |
|---|---|---|---|
| Acompte à la réservation en ligne | `/rdv` réserve sans paiement. Pas d'acompte demandé ni suivi, alors que c'est clé pour les fêtes et contre les absences. Version simple : acompte annoncé avec instructions mobile money, puis validation par le salon. | IMPORTANT | 1,5 |
| Carte cadeau | Elle est citée dans le tunnel « Beauté des fêtes », mais rien ne gère une carte cadeau (code, montant, utilisation). | IMPORTANT (décembre) | 1,5 |
| Liste d'attente / dernière minute | Les créneaux libres sont détectés (moteur d'occupation), mais il n'y a pas de page « dernière minute » publique. | PLUS TARD | 1 |
| Déjà en place | RDV en ligne par intervenante, pause et fermetures, rappel de la veille avec boutons, relance selon le rythme de la cliente, fidélité, catalogue, fiche client, tunnels « Première visite » et « Fêtes », carte digitale. | OK | — |

## Santé / bien-être

| Manque | Constat | Classe | Effort |
|---|---|---|---|
| Confidentialité des données de santé | Une note de santé chiffrée existe (personnes suivies). Pas de restriction fine par rôle sur l'historique, ni de consentement explicite au traitement. | IMPORTANT | 1 |
| Rappel du contrôle (6 mois, 1 an) | En place via le délai de retour par prestation. | OK | — |
| Téléconsultation, ordonnances, dossier médical | Hors périmètre (ce serait un ERP médical). | PLUS TARD / non | — |
| Acompte en ligne | Même manque que la beauté. | IMPORTANT | (commun) |

## Salle de sport

| Manque | Constat | Classe | Effort |
|---|---|---|---|
| Paiement des abonnements en ligne | Abonnements, échéances, relances d'échéance, fin d'abonnement et présences : en place. Mais le paiement se fait uniquement à l'accueil (pas de lien de paiement). | IMPORTANT | (lié à la passerelle) |
| Séance d'essai jusqu'à l'inscription | Tunnel « Séance d'essai gratuite » et suivi des prospects en place. Il manque une relance automatique J+1 / J+3 après la séance d'essai elle-même (aujourd'hui, seule la séquence du tunnel existe). | IMPORTANT | 0,5 |
| Réservation des cours en ligne | `/rdv` réserve des prestations, pas une place dans un cours collectif. | IMPORTANT | 2 |
| Contrôle d'accès (badge, QR à l'entrée) | Absent. | PLUS TARD | — |

## Location / vente de véhicules

| Manque | Constat | Classe | Effort |
|---|---|---|---|
| Paiement de l'acompte et de la caution | Factures d'acompte et de solde, caution suivie et reçu WhatsApp : en place. L'encaissement reste manuel (pas de lien mobile money). | IMPORTANT | (lié à la passerelle) |
| Numéros de la diaspora (+33, +32, +1) | Refusés : clientèle clé pour les transferts aéroport. | BLOQUANT pour ce segment | (commun, 1) |
| Déjà en place | Demandes et devis relancés, réservations sans double location, courses et transferts, états des lieux avec photos et accessoires, contrat PDF, km en plus, ventes en plusieurs fois, comptes entreprise, dépenses et bénéfice par véhicule, lien public, tunnels, démo Yamoo Drive. | OK | — |
| Pièces de location (permis, CNI en photo) | Champ texte sur le contrat ; pas de dépôt de documents du client. | PLUS TARD | 1 |

## Auto-école

| Manque | Constat | Classe | Effort |
|---|---|---|---|
| Réservation des leçons par l'élève | Les heures de conduite sont des RDV saisis par l'équipe. L'élève ne peut pas réserver sa leçon en ligne avec un moniteur et un véhicule. | IMPORTANT | 2 |
| Planning des moniteurs et véhicules | Les intervenants et les ressources (véhicule unique par leçon) existent. Pas de vue d'ensemble « semaine par moniteur ». | IMPORTANT | 1 |
| Déjà en place | Formations, parcours de l'apprenant (heures, progression), examens et résultats, alerte de décrochage, frais en plusieurs fois, démo Auto-école Horizon. | OK | — |
| Code de la route en ligne | Hors périmètre. | PLUS TARD / non | — |

## Ordre recommandé d'ici le 15/12

1. **Bloquants** (environ 5 à 7 jours) : mises à jour de sécurité, déploiement Azure et tâches planifiées, mentions légales, procédure de paiement de l'abonnement (manuelle d'abord), numéros internationaux.
2. **Important commun** (environ 5 jours) : modèles Meta hors 24 h branchés sur les automatisations, alerte au gérant pour chaque nouvelle demande, acompte en ligne (version simple), correction du test en échec.
3. **Important par secteur** (environ 5 à 7 jours) : carte cadeau (beauté, avant les fêtes), réservation des cours (sport), réservation des leçons (auto-école).
4. **Plus tard** : passerelle de paiement complète, PWA hors-ligne, dernière minute, documents des locataires.
