# Fidélité par enseigne : écart entre l'existant et le besoin

Lecture du code au 04/11/2026, branche `feat/menentia-fidelite-enseignes`.

| Sujet | Existe | Manque | Décision |
|---|---|---|---|
| Programme de fidélité | `organizations.loyalty_settings` : un seul programme par entreprise, sans date de fin ni enseigne | Plusieurs programmes datés, une ou plusieurs enseignes | Nouvelles tables `loyalty_programs` et `loyalty_program_rules`. Le réglage historique reste pour les secteurs sur rendez-vous |
| Calcul des points | `App\Services\Loyalty` : points ticket par ticket, solde = somme des mouvements | Points sur le cumul, seuil d'entrée, bonus, expiration, remise à zéro | Nouveau `App\Services\LoyaltyEngine`, qui rejoue les tickets. `Loyalty` n'est pas modifié |
| Compteurs | Un solde par client | Un compteur par client, enseigne et programme | Table `loyalty_cards`, une ligne par client / enseigne / programme |
| Journal | `loyalty_transactions`, sert aussi de source de vérité | Journal reconstructible : activation, gain, bonus, palier, remise, retour, expiration | Table `loyalty_movements`, entièrement recalculée |
| Paliers | Nom + points, sur 12 mois glissants | Points → % de remise, nombre illimité | Colonne JSON `tiers` par règle d'enseigne |
| Récompenses | `loyalty_rewards` échangées depuis la fiche | Remise utilisée à la caisse | Table `loyalty_redemptions` (bouton de la fiche) et `purchases.loyalty_discount_percent` (import) |
| Enseignes et magasins | `brands`, `stores.brand_id`, `stores.code` | Rien | Réutilisés : enseigne = `brand`, magasin = `store` |
| Tickets | `purchases` + `purchase_items`, retours négatifs, annulations, `source_key` unique | Marquage de la remise fidélité | Une colonne ajoutée. L'idempotence de l'import reste celle de `source_key` |
| Import des ventes | `SalesImport` : dépôt, mapping, vérification, file d'attente, lots, annulation | Colonne enseigne, prénom, remise_fidelite, sexe, date de naissance, quartier, e-mail ; ticket sans famille | Champs ajoutés à `SalesImport::FIELDS` |
| Fiche client | `customers.gender`, `birthdate` | E-mail, quartier | Colonnes `customers.email`, `customers.district` |
| RFM | `customers.rfm_segment`, RFM par marque (`customer_brand_metrics`), paramétrable | Rien | Réutilisé : les filtres fidélité se croisent avec les segments |
| Filtres et groupes | `CustomerSearch` partagé par la liste, l'export, les groupes et les campagnes | Critères programme / enseigne / statut / palier | Quatre clés ajoutées à `CustomerSearch::FILTER_KEYS` |
| Recalcul | `RefreshBrandMetricsJob`, `crm:refresh-lifecycle` | Recalcul fidélité ciblé et quotidien | `RecalculateLoyaltyJob`, `fidelite:recalculer` |
| Tableaux de bord | `RetailAnalytics`, `/analytics/*`, `/multibrand` | Pages à onglets, filtrage croisé, N-1, indicateurs fidélité | Étape 8 |
| Rapports PDF | Vues `reports/*`, PDF seulement si dompdf est installé (il ne l'est pas) | Rapports A4 paginés, envoi mensuel | Étape 9 |

## Hypothèses retenues

Ces points ne sont pas tranchés par le plan validé. Ils sont faciles à changer si besoin.

1. **Ticket où la remise est utilisée** : les points repassent à 0 et le montant net de ce ticket compte dans le nouveau cumul.
2. **Bouton « Remise utilisée »** : le cumul repart du jour indiqué. Les tickets de ce jour-là comptent dans le nouveau cumul. Le montant saisi est celui de la remise accordée : il sert au coût des remises, pas au cumul. Le ticket importé ensuite n'est donc pas compté deux fois.
3. **Bouton et import le même jour** : une seule utilisation est comptée.
4. **Remise signalée sur un client non membre actif** : aucune remise à zéro, une ligne « remise ignorée » apparaît au journal.
5. **Remise signalée alors qu'aucun palier n'est atteint** : la remise à zéro s'applique, car le ticket dit qu'elle a été donnée.
6. **Validité** : la carte reste valable jusqu'à la date d'expiration incluse.
7. **Retour supérieur au cumul en cours** : le cumul s'arrête à 0, il ne devient pas négatif.
8. **Retour qui repasse sous le seuil** : le client reste membre.
9. **Après la fin du programme** : aucun gain, mais la carte reste valable jusqu'à son expiration et la remise reste utilisable.
10. **Une enseigne ne peut pas être dans deux programmes aux dates qui se chevauchent**, sinon un même ticket compterait deux fois.
11. **Téléphone ou numéro de ticket manquant** : la règle actuelle de l'import reste valable. Sans téléphone, la vente est gardée comme vente anonyme, sans points. Sans numéro de ticket, un ticket est formé par client, jour et magasin.
12. **Famille** : facultative dès qu'un programme existe (« ticket sans détail accepté »).
13. **« oui » dans `remise_fidelite`** : compte comme une utilisation, au palier de la carte.
14. **Remise sur un ticket quand deux programmes sont actifs** : elle s'applique à la carte du programme le plus récent, et à l'ancienne carte seulement si la récente n'est pas active.
15. **« Proches du seuil » et « proches d'un palier »** : il reste moins de 20 % du seuil à acheter, ou 30 points au plus avant le palier. Un programme terminé n'y figure plus. Ces deux réglages sont des constantes de `CustomerSearch`.
16. **Clients perdus** : ceux qui ont acheté sur la période précédente de même durée et pas sur la période affichée.
