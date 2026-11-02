# Démo sport — « Club Étoile Libreville »

Club sportif **fictif** pour montrer les modules à la carte (cours de groupe, formules et abonnements, paiement en plusieurs fois, personnes suivies). Inspiré d'une école de sport pour enfants de Libreville ; aucune donnée réelle.

```bash
php artisan migrate
php artisan demo:sport
```

Rejouable : seule l'entreprise `demo-sport` est supprimée puis recréée. Refusé en production sans `--force`.

## Comptes

| Compte | Rôle |
|---|---|
| `directeur@demo-sport.test` | Super administrateur (tout le club) |
| `coach-judo@demo-sport.test`, `coach-football@…`, `coach-danse@…`, `coach-natation@…` | Responsable de boutique (son lieu) ; voit la note de santé des enfants de ses cours |

Mot de passe : variable `DEMO_PASSWORD`, sinon `Demo-Sport-2026`.

## Ce que contient la démo

- **Modules** : cours et séances de groupe, formules et abonnements, paiement en plusieurs fois, personnes suivies (« adhérents »), plusieurs lieux, catalogue, fidélité (2 points par présence). Pas de rendez-vous individuels : l'Agenda montre les cours.
- **Lieux** : Sacré-Cœur, Akaé Beach, INJS, Oloumi.
- **Cours** (saison à partir du 7 octobre 2026) : Danse lundi 14h-15h, Natation mercredi 15h-16h, Football vendredi 15h-16h30, Judo samedi 10h30-11h30 (5-7 ans) et 11h30-12h30 (8-12 ans).
- **Formules** : mensualités 13 000 (football), 15 000 (judo, danse), 20 000 FCFA (natation) ; inscription 10 000 et assurance 17 000 FCFA au début ; options transport 15 000 FCFA / mois et kimono 30 000 FCFA (une fois).
- **~120 enfants** et leurs parents (fratries de 2 ou 3), chacun avec sa fiche ; quelques notes de santé.
- **Paiements** : inscription, assurance et octobre réglés par la plupart des familles (espèces, Airtel Money, Moov Money) ; une famille sur sept environ n'a pas payé octobre → tranches en retard ; novembre arrive le 5.
- **Présences** : appel fait pour toutes les séances passées (~85 % de présence) ; quelques enfants décrochent (absences répétées).
- **Pluie** : séances de football des vendredis 23 et 30 octobre annulées (terrain inondé) ; rattrapage programmé le samedi 7 novembre à 15h.

## Parcours de démonstration (10 minutes)

1. **Accueil** : « Pour bien démarrer » (étapes cochées toutes seules), puis le tableau de bord.
2. **Agenda** : semaine du 26 octobre, cours en vert, football du 30 barré (annulé).
3. **Agenda › Cours** → Football : inscrits, présences, séances annulées et rattrapage du 7 novembre.
4. **Appel** d'une séance de judo : un geste par enfant, note de santé visible du coach.
5. **Ventes › Formules** : formules et abonnements (payé / total, options).
6. **Ventes › Échéances** › En retard : « Rappeler » (WhatsApp prérempli) et « Encaisser » (crée la vente, reçu imprimable).
7. **Fiche d'un parent** › Personnes suivies, puis la fiche d'un enfant › Cours et présences, Échéances.
8. **À faire** : Échéances à rappeler, Rattrapages à annoncer, Absences répétées — le message part au parent.
9. **Paramètres › Modules** : décocher un module le fait disparaître du menu et des fiches.
10. **Clients › Importer** : modèles Excel et import des personnes suivies.

Captures 1440 px : [`docs/apercu-modules/`](apercu-modules/).

## Choix faits pendant le développement (à valider)

- **Ventes d'un abonnement sur la fiche de l'enfant** : une tranche encaissée devient une vente de la personne suivie (son historique, son segment, sa fidélité) ; le parent est le payeur de l'échéancier et reçoit les messages. Conséquence : la fiche du parent reste « Prospect / pas encore d'achat » ; ses enfants sont dans l'onglet « Personnes suivies ».
- **Alerte de départ des abonnés** : pour un abonné, le rythme d'achat ne compte pas (les paiements suivent l'échéancier) ; l'alerte vient de l'assiduité (3 absences en 30 j = moyen, 5 = élevé) et des impayés (tranche en retard = moyen, plus de 30 j = élevé).
- **Rattrapage** : proposé quand le nombre d'annulations pour intempérie non rattrapées atteint le seuil (2 par défaut) ; un rattrapage couvre ce lot. Seul le motif « Intempérie » compte.
- **Annonce aux parents** : liste dans « À faire » (lien wa.me) ; si l'API WhatsApp est active, un groupe « Annulation … » / « Rattrapage … » est aussi préparé pour une campagne (pas d'envoi automatique : il faut un modèle Meta approuvé).
- **Numéro facultatif** pour une personne suivie : jamais rapprochée d'une autre fiche par le numéro.
- **Paiement en plusieurs fois** : tranches mensuelles égales (la dernière absorbe l'arrondi) ; pas de paiement partiel d'une tranche.
- **Plusieurs lieux décoché** : un seul établissement, l'ajout de lieux et de marques est bloqué.
- **Commerce + catalogue** : correspondance par code (référence) puis par libellé exact (sans accents ni majuscules) ; sans correspondance, la famille du fichier s'applique comme avant.
