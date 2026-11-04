# Fidélité : cas pédagogiques

Clients fictifs de la démo RETAILGROUP MODE, un par règle à comprendre. La commande pose le programme, crée les tickets, recalcule et compare chaque carte au résultat attendu. Elle peut être relancée sans créer de doublon.

```bash
php artisan demo:fidelite-cas
```

**Connexion** : `admin.mode@retailgroup-demo.test` / `Demo-Retail-2026`

## Programme : Fidélité 2026-2027 (01/10/2026 → 30/09/2027)

| Enseigne | Seuil d'entrée | Tranche | Bonus d'activation | Paliers | Validité |
|---|---|---|---|---|---|
| Adidas | 400 000 | 10 000 = 1 pt | 50 | 300 pts → 10 %, 350 pts → 15 % | 12 mois |
| Mango | 400 000 | 10 000 = 1 pt | 50 | 350 pts → 10 %, 400 pts → 15 % | 12 mois |
| Carré | 650 000 | 15 000 = 1 pt | 75 | 450 pts → 10 %, 550 pts → 15 % | 12 mois |

**Le calcul** : points = cumul ÷ tranche, arrondi à l'inférieur, plus le bonus. Le cumul compte tous les magasins de l'enseigne.

## Les cas

| Cas | Achats | Calcul attendu | Points | Récompense |
|---|---|---|---|---|
| 01 Sous le seuil | Adidas : 150 000 + 100 000 | 250 000 < 400 000 : non membre, reste 150 000 | 0 | Aucune |
| 02 Pile au seuil Adidas | Adidas : 250 000 + 150 000 | 400 000 = seuil : 40 + 50 | 90 | Aucune |
| 03 Exemple Adidas 43+50 | Adidas : 430 000 | 43 + 50 | 93 | Aucune |
| 04 Carré 43+75 | Carré : 400 000 + 250 000 | 650 000 ÷ 15 000 = 43, + 75 | 118 | Aucune |
| 05 Tranches sur le cumul | Adidas : 400 000 + 7 000 + 7 000 | 414 000 : 41 + 50. Ticket par ticket, ce serait 40 | 91 | Aucune |
| 06 Proche du seuil | Mango : 350 000 | Non membre, reste 50 000 | 0 | Aucune |
| 07 Proche palier 10 % | Adidas : 1 200 000 + 1 200 000 | 240 + 50, il manque 10 points | 290 | Aucune |
| 08 Palier 10 % atteint | Adidas : 1 300 000 + 1 200 000 | 250 + 50 | 300 | Remise de 10 % |
| 09 Palier 15 % atteint | Mango : 2 000 000 + 1 500 000 | 350 + 50 | 400 | Remise de 15 % |
| 10 Récompense utilisée puis recommence | Adidas : 2 500 000, puis 120 000 avec remise de 10 % | 300 points remis à 0, le cumul repart de ce ticket, sans nouveau bonus | 12 | Aucune |
| 11 Retour qui retire des points | Adidas : 500 000, puis retour de 60 000 | 440 000 : 44 + 50 (6 points retirés) | 94 | Aucune |
| 12 Multi-enseignes compteurs séparés | Adidas : 500 000. Mango : 200 000 | Adidas 50 + 50. Mango sous le seuil | Adidas 100, Mango 0 | Aucune |
| 13 Deux magasins même enseigne | Adidas Glass : 250 000. Adidas Mbolo : 250 000 | 500 000 cumulés : 50 + 50 | 100 | Aucune |
| 14 Achat avant le programme | Adidas : 900 000 le 20/09, 100 000 le 20/10 | Seuls 100 000 comptent : non membre | 0 | Aucune |
| 15 Vente anonyme | Adidas : 900 000 sans téléphone | Gardée dans le CA, sans fiche client ni points | Aucun | Aucune |

L'expiration n'a pas de cas ici : avec 12 mois de validité, elle ne peut pas encore arriver sur ce programme. Elle se voit sur les cartes de « Fidélité 2025 ».

## Où regarder dans l'application

- **Clients › Fiches clients** : cherchez « Cas » pour retrouver ces clients. Sur chaque fiche, le bloc de l'enseigne montre les points, la frise des récompenses et l'historique.
- **Clients › Fidélité** : cas 08 et 09 dans « Récompense atteinte », cas 06 et 07 dans « Proche ».
- **Ventes** : cherchez les tickets `CAS01-1` à `CAS15-1`.
- **Paramètres › Fidélité** : les règles du programme.
