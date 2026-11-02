# Démo « Menentia Solutions digitales »

Entreprise **fictive** (Libreville, services professionnels) pour montrer le suivi des prospects et des commerciaux.

```bash
php artisan migrate
php artisan demo:menentia
```

Rejouable (seule l'entreprise `demo-menentia` est recréée), refusé en production sans `--force`.

| Compte | Rôle |
|---|---|
| `directeur@demo-menentia.test` | Directeur (voit tout, fixe les objectifs) |
| `commercial1@demo-menentia.test` (Patricia Mintsa) | Commercial : ses fiches et ses chiffres |
| `commercial2@demo-menentia.test` (Junior Obiang) | Commercial |

Mot de passe : `DEMO_PASSWORD`, sinon `Demo-Menentia-2026`.

## Contenu

- Modules : rendez-vous, catalogue, suivi des prospects, formules/abonnements, échéances.
- Catalogue par catégories : Abonnements SaaS (Solo 9 900, Équipe 25 000, Premium 50 000 FCFA/mois, mise en route 25 000), Développement, Marketing digital, Analyse de données, Formations.
- 40 prospects (instituts, salles de sport, clubs, cabinets, garages… noms fictifs) sur Prospect, Visité, Démo faite, En essai, Client, Perdu (avec raison) ; visites et démos dans l'agenda, certains en retard de relance.
- 12 clients abonnés : paiements, retards, abonnements à renouveler, quelques prestations vendues.
- Objectifs du mois par commercial : 60 démos, 24 essais, 7 clients payants.

## Parcours

1. **Clients › Prospects** : tableau par étape, retards en rouge, changer d'étape (« Perdu » demande la raison), WhatsApp.
2. **À faire › Prospects à relancer** (connecté en commercial : seulement ses fiches).
3. **Clients › Objectifs** : progression par indicateur, objectif à date, classement ; « Primes du mois ».
4. **Paramètres › Prospects** : renommer les étapes, délais de relance, primes, codes commerciaux.
5. Agenda : une démo marquée « Honoré » fait passer le prospect à « Démo faite » ; la première vente le fait passer « Client ».

## Prévu en V2 (cahier des charges)

- Avis et enquêtes de satisfaction clients.
- Marketplace : fiche publique par établissement (bouton WhatsApp, réservation, recherche par activité et quartier).
- Captures d'écran du suivi des prospects.
- Déjà livrés en avance (à valider en V2) : primes et export paie, taux de passage, code de parrainage, rapport du soir et rappel du matin des commerciaux, position GPS des visites.
