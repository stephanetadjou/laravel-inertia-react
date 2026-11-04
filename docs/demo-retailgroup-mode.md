# Démo RETAILGROUP MODE : fidélité par enseigne

Entreprise et données fictives. Le chargement prend environ 2 minutes, car les tickets passent par le vrai moteur d'import.

```bash
php artisan migrate
php artisan demo:retailgroup-mode --fresh
```

`--fresh` supprime cette entreprise de démonstration, et elle seule.

**Connexion** : `admin.mode@retailgroup-demo.test` / `Demo-Retail-2026`

## Contenu

- 3 enseignes et 6 magasins : Adidas (Glass, Mbolo), Mango (Centre-ville, Akanda), Carré (Centre-ville, Glass).
- Environ 300 clients et 3 700 tickets, de mai 2025 à aujourd'hui, en XAF.
- Une partie des tickets est anonyme et une partie n'a pas de détail d'articles.
- Programme **Fidélité 2026** (01/01/2026 → 31/12/2026) :

| Enseigne | Seuil | Tranche | Bonus | Paliers | Validité |
|---|---|---|---|---|---|
| Adidas | 400 000 | 10 000 = 1 pt | 50 | 300 → 10 %, 350 → 15 % | 12 mois |
| Mango | 400 000 | 10 000 = 1 pt | 50 | 350 → 10 %, 400 → 15 % | 12 mois |
| Carré | 650 000 | 15 000 = 1 pt | 75 | 450 → 10 %, 550 → 15 % | 12 mois |

- Programme **Fidélité 2025** (clôturé), avec les mêmes règles. Il n'était pas dans le plan : avec 12 mois de validité, aucune carte du programme 2026 ne peut déjà être expirée. Il porte donc les cartes expirées et celles qui expirent sous 30 jours.

## Situations à montrer

Elles se retrouvent toutes dans **Clients › Fiches clients**, avec les filtres de fidélité :

| Situation | Filtre |
|---|---|
| Sous le seuil | Non membres |
| Proche du seuil | Proches du seuil |
| Juste activé | Membres actifs, dernier achat récent |
| Proche d'un palier | Proches d'un palier |
| Palier 10 % ou 15 % | Palier 10 % ou Palier 15 % |
| Remise utilisée puis recommence | Fiche client, historique de la carte |
| Expiré | Cartes expirées |
| Expire bientôt | Expirent sous 30 jours |
| Plusieurs enseignes | Plusieurs blocs sur la fiche client |
| Retour | Fiche client, historique de la carte |

Ces filtres se croisent avec les segments RFM, et « Créer un groupe / relancer » enregistre le résultat comme groupe de campagne.

## Faire bouger les points

1. Ouvrez **Imports › Ventes** et déposez `docs/exemples/exemple-retailgroup-mode.xlsx`. Le fichier correspond à la démo telle que générée par le dépôt.
2. Vérifiez l'association des colonnes, puis confirmez.
3. Lancez le worker s'il ne tourne pas : `php artisan queue:work --stop-when-empty`.

Le fichier fait passer des seuils, atteindre des paliers, utilise deux remises et enregistre deux retours. Si vous le déposez deux fois, le second dépôt est refusé. Les mêmes tickets dans un autre fichier sont signalés comme doublons et ne comptent pas.

Sur une fiche avec une remise disponible, le bouton **Remise utilisée** fait la même chose qu'un ticket portant `remise_fidelite`.

## Paramétrage

**Paramètres › Programmes de fidélité** : tout se règle sans développement, et les cartes sont recalculées à l'enregistrement.

Le modèle de fichier à donner aux caisses est `docs/exemples/modele-import-ventes.xlsx`. Sa feuille « Colonnes » décrit chaque colonne.
