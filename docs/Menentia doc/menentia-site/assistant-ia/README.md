# Assistant Menentia (service IA)

Petit service Python/FastAPI derrière la bulle « Une question ? » du site menentia.com.
Il répond aux visiteurs avec Claude, à partir de la base de connaissances que le site
publie lui-même (`/assistant/kb.json`, générée depuis `lib/` au build). Changer un prix
ou une fonction dans le site change donc aussi les réponses, au plus tard une heure après.

## Ce qu'il fait

- `POST /v1/assistant` : `{ "messages": [{ "role": "user", "content": "…" }], "page": "/tarifs" }`.
  Réponse en flux SSE : `data: {"text": "…"}` à chaque morceau, puis `event: done`.
  En cas de problème : `event: error`, et la bulle répond alors avec ses réponses de secours.
- `GET /health` : état du service.
- Modèle `claude-opus-5-5`, effort `low` (réponses courtes de discussion), consignes et base
  de connaissances en cache de prompt, repli automatique si le modèle décline une question.
- Limites : 8 questions par minute et 80 par jour par adresse IP, questions de 600 caractères
  au plus, 24 messages d'historique au plus, CORS limité à menentia.com.
- Garde-fous du prompt : il se présente comme assistant automatique, ne répond que sur
  Menentia, n'invente ni prix ni fonction, dit « bientôt » pour ce qui n'est pas encore fait,
  ne demande aucune donnée sensible, et renvoie vers la démonstration quand il ne sait pas.

## Lancer en local

```bash
python -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt
export ANTHROPIC_API_KEY=…            # votre clé, jamais commitée
export KB_PATH=../menentia-site/out/assistant/kb.json   # après « npm run build » du site
export ALLOWED_ORIGINS=http://localhost:3000
uvicorn app.main:app --reload --port 8000
pytest -q
```

Côté site : `NEXT_PUBLIC_ASSISTANT_URL=http://localhost:8000/v1/assistant npm run dev`.
Sans service (variable vide), la bulle répond seule à partir de `kb.json`.

## Mise en ligne (Azure France Central)

Image Docker (`Dockerfile`) sur Azure Container Apps, domaine `ia.menentia.com`.
Secrets : `ANTHROPIC_API_KEY` dans les secrets de l'application, jamais dans le dépôt.
Le site appelle `https://ia.menentia.com/v1/assistant` par défaut (`lib/site.ts`, `ASSISTANT_URL`).
Les limites par IP sont en mémoire : avec plusieurs instances, passer à Redis.
