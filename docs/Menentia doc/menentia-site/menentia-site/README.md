# Site vitrine Menentia (menentia.com)

Next.js 15 (App Router) + Tailwind CSS, exporté en site 100 % statique pour Azure Static Web Apps.
Dans le monorepo, ce dossier devient `apps/site`.

## Démarrer en local

```bash
npm install
npm run dev        # http://localhost:3000
npm run build      # génère le site statique dans out/
```

Variable facultative : `NEXT_PUBLIC_APP_URL` (par défaut `https://app.menentia.com`), utilisée pour
« Se connecter », « Essai gratuit » et l'envoi du formulaire de démo (`POST /api/public/v1/leads`).

## Où changer quoi

| Contenu | Fichier |
| --- | --- |
| Prix, familles, fonctionnalités, FAQ, promesse, numéro WhatsApp | `lib/site.ts` |
| En-tête et pied de page | `components/Header.tsx`, `components/Footer.tsx` |
| Maquettes de l'application (agenda, fiche client, WhatsApp…) | `components/mockups.tsx` |
| Sections réutilisées (tarifs, FAQ, bandeau d'offre) | `components/sections.tsx` |
| Couleurs et polices | `tailwind.config.ts`, `app/layout.tsx` |

Chaque fonctionnalité porte un statut `dispo` ou `bientot` dans `lib/site.ts` : ne passer à `dispo`
qu'une fois la fonction en service (règle du cahier de cadrage, section 9).

## À compléter avant la mise en ligne

- Vrai numéro WhatsApp (`WHATSAPP_DISPLAY` dans `lib/site.ts`).
- Route `POST /api/public/v1/leads` côté Laravel pour le formulaire de démo.
- Pages Mentions légales et Confidentialité.
- Image de partage (Open Graph) en bleu-vert.

## Déploiement

Azure Static Web Apps, dossier de sortie `out`. Exemple de workflow GitHub Actions :
`app_location: apps/site`, `output_location: out`, build `npm run build`.
