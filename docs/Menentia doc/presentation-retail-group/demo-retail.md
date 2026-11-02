# Démonstration Menentia Retail — Retail Group (mode)

Ce guide raconte, écran par écran, comment une direction utilise Menentia Retail pour **connaître ses clients et décider** de ses prochaines campagnes ou de ses ajustements d'offre. Il s'appuie sur l'entreprise fictive chargée par :

```bash
php artisan demo:retail          # première fois
php artisan demo:retail --fresh  # pour la recharger (ne supprime que cette entreprise de démonstration)
```

Les données sont **fictives** mais construites pour que chaque constat se lise dans les écrans : 4 enseignes, 4 boutiques à Libreville (**Adidas Glass**, **Grand Store Glass**, **Mango Centre-ville**, **Carré Centre-ville**), environ 4 000 tickets sur 12 mois importés par le vrai moteur d'import, 840 fiches clients. Les chiffres ci-dessous sont ceux affichés le 30/10/2026 ; les dates étant calculées à partir du jour du chargement, ils peuvent varier légèrement d'un chargement à l'autre.

## Comptes de démonstration

Mot de passe de tous les comptes : `Demo-Retail-2026` (ou la valeur de `DEMO_PASSWORD`).

| Compte | Rôle | Ce qu'il voit |
|---|---|---|
| `direction.mode@menentia-demo.test` | Direction | Tout le groupe, tableaux de bord et exports, sans les paramètres |
| `admin.mode@menentia-demo.test` | Admin groupe | Tout, paramètres compris (fidélité, segments RFM, utilisateurs) |
| `marketing.mode@menentia-demo.test` | Marketing | Clients, segments, groupes, exports ; pas d'import |
| `adidas.mode@`, `mango.mode@`, `carre.mode@`, `grandstore.mode@menentia-demo.test` | Responsable de marque | Sa marque uniquement (chiffres, clients, exports) |
| `boutique.mode@menentia-demo.test` | Responsable de boutique | Adidas Glass uniquement |
| `import.mode@menentia-demo.test` | Opérateur d'import | Dépôt et suivi des fichiers de caisse ; aucun export |

## Le principe en une ligne

**Fichiers de caisse → tickets → fiche client unique → indicateurs → actions.** Chaque magasin exporte ses ventes depuis sa caisse ; Menentia les importe, range chaque ticket sur la fiche unique du client (repérée par son téléphone, pour tout le groupe), calcule les indicateurs (CA, panier, réachat, segments RFM par marque…) et transforme chaque constat en liste de clients à relancer.

## Vue d'ensemble : le tableau de bord Direction

**Menu : Analyses › Tableaux de bord › Direction** (période « 12 mois »).

| Indicateur | Valeur |
|---|---|
| CA net (ventes − retours − remises) | 312,8 M FCFA |
| Tickets | 3 955 |
| Panier moyen | 80 238 FCFA |
| Clients identifiés | 840 (81 % des tickets) |
| Clients actifs (90 j) | 550 |
| Retours / remises | 4,5 M / 7,5 M FCFA |

L'encart **« À retenir »** liste automatiquement les constats principaux, calculés par des règles simples (pas d'IA), chacun avec une décision suggérée, un lien « Voir et agir » et les boutons **Clients ↓** (export Excel) et **Relancer** (groupe + Actions du jour).

Comparaison des marques (12 mois) : Adidas 106,5 M (34 %), Carré 81,6 M (26 %), Mango 68,0 M (22 %), Grand Store 56,7 M (18 %).

---

## Les huit histoires

### 1. Mango : de bonnes clientes ne viennent plus → reconquête

- **Constat** : un groupe de très bonnes clientes Mango (5 à 8 achats, gros paniers) n'a plus rien acheté depuis 4 mois.
- **Où le voir** : « À retenir » → *« Mango : 57 bons clients n'achètent plus »* (30,6 M FCFA dépensés chez Mango) ; **Analyses › Multimarque › Segments RFM par marque**, colonne Mango : 32 « À risque » et 25 « À ne pas perdre » ; **Clients › Fiches clients**, filtre marque Mango + segment.
- **Chiffre clé** : 57 clientes, 30,6 M FCFA de CA historique.
- **Décision** : campagne de reconquête ciblée (nouvelle collection, attention en boutique).
- **Exporter / relancer** : bouton **Clients ↓** du constat, ou groupe déjà préparé **« Reconquête Mango »** (57 clientes) → **À faire › Groupe de clients**, un lien WhatsApp prérempli par cliente ; campagne en brouillon du même nom (active quand l'API WhatsApp l'est).

### 2. Adidas → Grand Store : la vente croisée à deux pas

- **Constat** : les deux boutiques sont dans le même quartier (Glass) mais les fidèles d'Adidas ne vont presque jamais chez Grand Store.
- **Où le voir** : « À retenir » → *« 246 clients fidèles de Adidas ne sont jamais venus chez Grand Store »* (79 % des clients Adidas ayant au moins 2 achats) ; **Analyses › Multimarque**, matrice des clients communs (ligne Adidas, colonne Grand Store) ; **À faire › Vente croisée entre marques**, filtre Adidas.
- **Chiffre clé** : 246 clients, 79 %.
- **Décision** : offre croisée (10 % sur le premier achat chez Grand Store Glass, message prérempli avec `{autre_marque}`).
- **Exporter / relancer** : groupe préparé **« Vente croisée Adidas → Grand Store »** (246 clients), export de la liste d'actions (**Exporter la liste**, avec les liens wa.me), campagne en brouillon.

### 3. Les clients multimarques : peu nombreux, très précieux → VIP groupe

- **Constat** : les clients qui achètent dans au moins deux enseignes pèsent plus du double de leur poids.
- **Où le voir** : « À retenir » → *« Les clients multimarques : 18 % des clients, 42 % du CA identifié »* ; **Analyses › Multimarque** (150 clients multimarques, CA moyen comparé au mono-marque) ; **Analyses › Clientèle** (ligne « Multimarques »).
- **Chiffre clé** : 150 clients = 18 % des clients, 42 % du CA.
- **Décision** : programme VIP groupe (avant-premières, avantages dans les quatre enseignes, palier de fidélité).
- **Exporter / relancer** : bouton **Clients ↓** « Multimarques » de l'écran Multimarque, groupe préparé **« VIP multimarques »** (150), campagne en brouillon.

### 4. Carré : beaucoup de nouveaux clients… qui ne reviennent pas → bienvenue + relance J+30

- **Constat** : Carré recrute, mais seuls 2 nouveaux clients sur 10 reviennent dans les 90 jours.
- **Où le voir** : « À retenir » → *« Carré : seulement 21 % des nouveaux clients reviennent dans les 90 jours »* (166 nouveaux clients entre 9 et 3 mois) ; **Analyses › Rétention** (cohortes, filtre marque Carré) ; segments RFM Carré : 73 « Nouveaux clients ».
- **Chiffre clé** : 21 % de réachat à 90 jours.
- **Décision** : message de bienvenue au premier achat et relance à J+30 (retouches offertes, conseils).
- **Exporter / relancer** : groupe préparé **« Bienvenue Carré J+30 »** (61 nouveaux clients des 4 derniers mois), liste **À faire › Nouveaux clients à fidéliser** filtrée sur Carré, export par cohorte (**Clients ↓** sur chaque mois de premier achat dans Rétention).

### 5. Saisonnalité : rentrée et fêtes → calendrier de campagnes

- **Constat** : les familles enfant et sneakers explosent à la rentrée (août-septembre) et aux fêtes (décembre).
- **Où le voir** : **Analyses › Tableaux de bord › Familles** avec un filtre de période (août-septembre, décembre), **Analyses › Direction › Évolution mensuelle** avec le filtre Famille.
- **Chiffres clés** : Mode enfant 0,4 M/mois en moyenne → 1,3 M en août, 1,8 M en septembre, 1,3 M en décembre ; Jouets 1,8 M en décembre ; Sneakers 12,5 M en août et 13,5 M en septembre (contre ~1 M/mois au printemps).
- **Décision** : calendrier de campagnes (fin juillet : rentrée enfant et sneakers ; fin novembre : fêtes), stock et mise en avant en conséquence.
- **Exporter / relancer** : dans **Familles**, bouton **Clients ↓** / **Relancer** sur Mode enfant ou Sneakers pour cibler leurs acheteurs avant la saison suivante.

### 6. Costumes en baisse, sneakers en hausse → ajustement de l'offre

- **Constat** : les costumes Carré reculent fortement, les sneakers Adidas progressent.
- **Où le voir** : « À retenir » → *« Costumes en baisse : −75 % sur 90 jours »* (2,6 M contre 10,4 M) et *« Sneakers en hausse »* (33,0 M contre 5,3 M) ; **Analyses › Familles**.
- **Décision** : revoir l'offre et le réassort costumes (gamme, prix, mise en avant), renforcer stock et visibilité sneakers.
- **Exporter / relancer** : le constat « Costumes en baisse » exporte les clients qui achetaient des costumes et n'en achètent plus depuis 90 jours (enquête, offre dédiée).

### 7. Retours élevés sur les chaussures femme → alerte qualité

- **Constat** : près d'une paire de sandales Mango sur quatre revient.
- **Où le voir** : « À retenir » → *« Retours élevés sur Chaussures femme : 23,8 % des ventes »* (1,8 M FCFA retournés) ; **Ventes**, filtre « Retours seulement » ; chaque retour est relié à son ticket d'origine.
- **Décision** : alerte qualité (fournisseur, guide des tailles, contrôle en boutique).
- **Exporter / relancer** : clients concernés via **Clients ↓** (enquête de satisfaction).

### 8. Grand Store : près d'un ticket sur deux est anonyme → collecte du téléphone en caisse

- **Constat** : à Grand Store Glass, 46 % des tickets n'ont pas de numéro client (moins de 10 % ailleurs) : ces clients ne peuvent être ni suivis ni relancés.
- **Où le voir** : « À retenir » → *« Grand Store Glass : 46 % de ventes anonymes »* (507 tickets) ; **Analyses › Qualité des imports** ; **Ventes**, filtre « Ventes anonymes ».
- **Décision** : demander le téléphone en caisse (objectif par vendeur, avantage fidélité à l'inscription).
- **Suivi** : le taux d'identification par point de vente est dans **Analyses › Marques & points de vente** (colonne « Tickets identifiés »).

---

## Paramétrer et utiliser la fidélité

1. **Paramètres › Fidélité** (compte admin) : activer le programme, fixer **1 point pour X FCFA** (démo : 1 point / 1 000 FCFA), la **date de lancement**, et les **paliers** sur 12 mois (démo : Bronze 0, Argent 300, Or 1 000 points).
2. **Récompenses** (même écran) : démo — Bon d'achat 5 000 FCFA (150 pts), Retouche offerte (80 pts), Bon d'achat 15 000 FCFA (400 pts), Accès VIP aux soldes privées (600 pts).
3. **Au quotidien** : les points sont crédités automatiquement à chaque ticket importé (retirés si le ticket est annulé ou retourné). Sur la **fiche client**, le panneau Fidélité affiche le solde, le palier, la progression vers le palier suivant et l'historique ; le bouton d'échange déduit les points (démo : 20 échanges déjà faits).
4. **Relancer par palier** : l'export **Clients ↓** contient le palier et les points de chaque client (par exemple pour inviter les clients « Or » aux soldes privées).

## Relancer les clients par segment

1. **Choisir le segment** : **Clients › Fiches clients**, filtre « Segment RFM » (groupe) ; avec un filtre Marque, ce sont les segments **de la marque** (un client peut être Champion chez Adidas et À risque chez Mango). Les segments par marque se lisent aussi dans **Analyses › Multimarque › Segments RFM par marque** (un bouton Clients ↓ / Relancer par cellule).
2. **Exporter** : **Exporter les clients** (Excel : nom, type, téléphone, code client, marques, point de vente principal, segment, étape, dernier achat, tickets, CA, consentement WhatsApp, palier et points de fidélité). Exports dans le périmètre du rôle et journalisés (**Paramètres › Journal**).
3. **Relancer** : **Créer un groupe / relancer** → le groupe s'ouvre dans **À faire › Groupe de clients** : un lien WhatsApp prérempli par client, envoi depuis le téléphone de la boutique, relance marquée « Fait ». Les messages se modifient dans **À faire › Modifier les messages**.
4. **Campagne** : si l'API WhatsApp est activée (**Paramètres › Entreprise**), le groupe sert de cible à une campagne ; seuls les clients ayant donné leur accord WhatsApp la reçoivent.
5. **Ajuster les règles** : **Paramètres › Segments RFM** (période d'analyse, seuils ; chaque changement crée une nouvelle version des règles, gardée dans l'historique des segments).

Le même couple **Clients ↓ / Relancer** existe sur chaque ligne des tableaux de bord (marques, points de vente, mois, familles, segments de clientèle, cohortes), sur les chiffres clés (clients identifiés, nouveaux, actifs), sur chaque constat « À retenir » et dans l'écran Multimarque (cellules de la matrice, multimarques, mono-marque).

## Autres écrans à montrer

- **Imports › Ventes** : historique des fichiers de caisse, dont un import avec 5 lignes rejetées (rapport d'erreurs CSV / Excel par n° de ligne) et un import annulé ; fichier d'exemple prêt à importer : `docs/exemples/exemple-import-ventes-mode.xlsx`.
- **Clients › À vérifier** : numéros connus arrivés avec un autre nom (téléphone partagé), jamais rattachés d'office.
- **Paramètres › Rapports** : rapport du soir envoyé à la direction (compte direction), objectif mensuel 12 M FCFA.
- **Paramètres › Journal** : imports, exports, annulations, fusions, tout est tracé.
