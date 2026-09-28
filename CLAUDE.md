# CLAUDE.md

Contexte pour Claude Code (et toute IA assistante) travaillant sur ce dépôt.

## Le projet

`mercure_crm` est un CRM WhatsApp interne pour **RETAIL GROUP**, une entreprise de prêt-à-porter contemporain basée à Libreville (Gabon), positionnée moyen-haut de gamme (pas de la haute couture). Le groupe exploite 4 enseignes, chacune avec son propre numéro WhatsApp Business à terme :

| Boutique | Segment |
|---|---|
| **Adidas** | Sport, sneakers, streetwear |
| **Carré** | Prêt-à-porter masculin |
| **Mango** | Mode féminine contemporaine |
| **Grand Store** | Grand magasin généraliste multi-marques |

Chaque boutique gère aujourd'hui WhatsApp de façon artisanale (téléphone personnel, pas de fiche client unifiée, campagnes envoyées sans segmentation ni consentement documenté). L'objectif du projet est de centraliser cette relation client : connexion officielle à l'API WhatsApp Cloud, boîte de réception partagée par boutique, chatbot de qualification, segmentation et score RFM des clients, campagnes ciblées, automatisations.

**Spécification complète** : [`docs/Cahier des charges - CRM WhatsApp Retail Group.docx`](docs/Cahier%20des%20charges%20-%20CRM%20WhatsApp%20Retail%20Group.docx) — analyse fonctionnelle, périmètre par module (connexion WhatsApp, chat, chatbot, gestion des contacts, segmentation RFM, campagnes, automatisations, analytics), architecture, modèle de données, MVP détaillé. Se référer à ce document avant d'ajouter une fonctionnalité non listée ici.

## Stack technique

- Backend : Laravel 13 (PHP ^8.3)
- Frontend : React 19 + TypeScript, via Inertia.js 3
- Build : Vite 8 + `laravel-vite-plugin`
- UI : Tailwind CSS 4
- Tests : Pest 4 (+ `pest-plugin-laravel`)
- Style de code : Laravel Pint
- Queue : Laravel Queue (driver `database`) — tout appel à l'API WhatsApp déclenché par une action utilisateur (campagne) doit passer par un Job, jamais un appel synchrone dans le contrôleur HTTP
- API externe : **WhatsApp Business Platform / Cloud API officielle (Meta Graph API) uniquement** — jamais de connexion non officielle par QR code ou session WhatsApp Web (décision produit actée dans le cahier des charges)

## Setup local (première fois sur une machine)

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate        # obligatoire même en tout début de projet : SESSION_DRIVER,
                            # CACHE_STORE et QUEUE_CONNECTION valent "database", donc
                            # les tables sessions/cache/jobs doivent exister avant le
                            # premier accès web, sinon "no such table: sessions".
```

## Commandes courantes

```bash
composer dev              # serveur + queue worker + logs (pail) + Vite, en parallèle
php artisan test          # suite Pest/PHPUnit
vendor/bin/pint           # formatage du code PHP
php artisan whatsapp:test-send {numero}   # vérifie la connexion API WhatsApp et envoie un template de test
```

## MVP en cours

Périmètre strict de la semaine (cahier des charges §7) :
1. Connexion à l'API WhatsApp par boutique (formulaire admin, test de connexion).
2. Import de contacts par boutique (CSV/Excel, mapping, normalisation E.164, détection de doublons).
3. Envoi de campagne à une liste de contacts importés (template Meta approuvé, envoi par lot en file d'attente).

**Explicitement hors scope pour l'instant** : chatbot, calcul RFM automatique et segmentation dynamique, automatisations/workflows, analytics avancés, store/e-commerce, Embedded Signup Meta. Ne pas anticiper ces fonctionnalités dans le code tant qu'elles ne sont pas explicitement demandées.

## Décisions d'architecture à respecter

- **Multi-boutique dès la conception.** Toute donnée métier (contact, conversation, campagne) est rattachée à une boutique (`store_id`), même quand le MVP n'affiche qu'une vue simple.
- **Jamais de secret en dur dans le code.** Les identifiants WhatsApp (token, `phone_number_id`) se lisent via `config('services.meta_whatsapp.*')` (donc `.env`), jamais codés en dur ni committés. Historique du projet : un access token Meta a déjà fuité dans d'anciens scripts PHP autonomes conservés à titre de référence dans `whatsapp_service/` (`test.php`, `whatsapp.php`, `whatsapp_single.php`) — **ne jamais reproduire ce pattern**, et ne jamais committer de nouveau token en clair.
- **Le module WhatsApp est un service applicatif, pas un script.** Toute la logique d'appel à l'API Graph passe par `App\Services\WhatsAppClient` (basé sur `Illuminate\Support\Facades\Http`, donc Guzzle) et son exception dédiée `App\Services\WhatsAppApiException`. Les scripts de `whatsapp_service/` sont des prototypes de référence, pas du code destiné à s'exécuter en production.
- **Envoi de messages toujours asynchrone.** Tout envoi de campagne doit passer par un Job Laravel en file d'attente (jamais un appel direct dans le contrôleur HTTP), pour ne jamais bloquer une requête sur un appel réseau externe.
- **Consentement WhatsApp obligatoire avant toute campagne marketing.** Un contact sans statut `opt-in` documenté ne doit jamais recevoir de template de catégorie Marketing.

## Déploiement

Déploiement via SSH/cPanel (`.github/workflows/deploy.yml`), sur le même serveur qu'un projet précédent — le workflow est réutilisé et adapté pour ce projet. Le secret GitHub `DEPLOY_PATH` doit pointer vers le répertoire dédié à **mercure_crm** sur ce serveur ; vérifier/mettre à jour ce secret (ainsi que `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`, `SSH_PASSPHRASE`, `SSH_PORT`) dans les paramètres du dépôt GitHub avant le premier déploiement.

Règle absolue, quel que soit le projet : ne jamais utiliser `migrate:fresh` ou `migrate:refresh` en déploiement — uniquement `migrate --force`, pour ne jamais perdre de données clients, de conversations ou de campagnes en production.

## CI

- `.github/workflows/tests.yml` : exécute le style de code (Pint) puis les tests (Pest) sur chaque push/PR vers `staging`/`main`. Réutilisable via `workflow_call`.
- `.github/workflows/deploy.yml` : après succès de `tests.yml`, déploie automatiquement `main` sur le serveur.
