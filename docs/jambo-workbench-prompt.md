# Jambo Workbench — Prompt de conception

> **But du document** : prompt complet à donner à un agent de code IA (Claude Code, Cursor, etc.) ou à une équipe, pour concevoir **Jambo Workbench**, une plateforme **autonome** de création de sites/apps web par IA, **inspirée de [bolt.diy](https://github.com/stackblitz-labs/bolt.diy)**, et connectée au CMS **Jambo** via son API.
>
> **Dépôt cible** : `https://github.com/dahovitech/jambo-workbench.git`
> **Stack imposée** : Vite + React + TypeScript + Tailwind CSS.

---

## 1. Vision

Jambo Workbench est un **atelier de développement web piloté par IA**, dans le navigateur. L'utilisateur décrit en langage naturel le site qu'il veut ; l'agent IA génère le code, l'exécute en direct (WebContainer), et l'utilisateur prévisualise, édite, itère, puis déploie.

La différence clé avec bolt.diy : **les sites sont alimentés par les collections d'un projet Jambo**. L'utilisateur connecte son projet Jambo (URL d'API + token), l'agent reçoit le **schéma des collections** et génère un site **data-driven** qui consomme l'API Jambo (REST/GraphQL).

**Public visé** : créateurs, agences, développeurs qui ont déjà structuré leur contenu dans Jambo et veulent un front rapidement.

**Principe directeur** : *rapide et fonctionnel*. Tout ce qui freine l'itération (builds lents, crashs, friction) est un bug.

---

## 2. Ce qu'on emprunte à bolt.diy (et ce qui diffère)

### À reprendre
- **Agent conversationnel** qui écrit/modifie des fichiers en streaming, avec un **parser d'artefacts** (actions « écrire fichier », « lancer commande shell »).
- **WebContainer** (`@webcontainer/api`) : exécuter Node **dans le navigateur** → install + dev server + preview, sans backend.
- **Layout Workbench** : chat à gauche, espace de travail à droite avec onglets **Code / Preview / Terminal**, panneaux **redimensionnables**.
- **Multi-LLM** via le **Vercel AI SDK** (`ai` + `@ai-sdk/*`) : OpenAI, Anthropic, Google, Mistral, DeepSeek, Ollama, OpenRouter…
- **Éditeur CodeMirror 6**, **terminal xterm.js**, **diff de fichiers**, **export ZIP**, **import/export Git (GitHub)**.
- **Snapshots / historique** de versions, **rollback**.

### Ce qui diffère (valeur Jambo)
- **Connexion Jambo** : sélection d'un projet, lecture du **schéma des collections**, injection dans le system prompt de l'agent.
- L'app générée **consomme l'API Jambo** (client généré : `lib/jambo.ts`) — listes, fiches, pagination, filtres, i18n.
- **Templates de départ** orientés data : blog, catalogue, vitrine, portfolio, landing.
- Possibilité (v2) d'un **mode SSR** où le rendu interroge Jambo côté serveur.

---

## 3. Stack technique (mapping depuis bolt.diy)

| Domaine | bolt.diy | Jambo Workbench (cible) |
|---|---|---|
| Build / framework | Remix + Vite | **Vite + React 18 + TypeScript** (Remix optionnel ; sinon React Router) |
| Styles | UnoCSS | **Tailwind CSS** + `tailwind-merge` + `clsx` + `class-variance-authority` |
| IA | Vercel AI SDK | **`ai` + `@ai-sdk/openai|anthropic|google|mistral|deepseek` + `ollama-ai-provider` + `@openrouter/ai-sdk-provider`** |
| Exécution | `@webcontainer/api` | **`@webcontainer/api`** (identique) |
| Éditeur | CodeMirror 6 | **CodeMirror 6** (`@codemirror/*`, thème vscode) |
| Terminal | xterm.js | **`@xterm/xterm` + addons fit/web-links** |
| État | nanostores + zustand | **nanostores** (atoms/maps) + **zustand** si besoin |
| Panneaux | react-resizable-panels | **`react-resizable-panels`** |
| Animations | framer-motion | **`framer-motion`** |
| Icônes | lucide / phosphor | **`lucide-react`** (+ `@phosphor-icons/react`) |
| Markdown | react-markdown + shiki | **`react-markdown` + `remark-gfm` + `rehype-raw` + `rehype-sanitize` + `shiki`** |
| Git | isomorphic-git + octokit | **`isomorphic-git` + `@octokit/rest`** |
| Divers | jszip, file-saver, diff, zod, use-debounce | **idem** |
| UI primitives | Radix UI | **Radix UI** (`@radix-ui/react-*`) + **shadcn/ui** sur Tailwind |
| Toasts | react-toastify | **`sonner`** (ou react-toastify) |

> Node ≥ 18.18. Gestionnaire de paquets : **pnpm** recommandé.

---

## 4. Architecture

```
jambo-workbench/
├─ index.html
├─ vite.config.ts            # plugins: react, node-polyfills, tsconfig-paths ; headers COOP/COEP pour WebContainer
├─ tailwind.config.ts
├─ src/
│  ├─ main.tsx               # bootstrap React + router
│  ├─ app/                   # pages / routes (Home, Workbench, Settings)
│  ├─ components/
│  │  ├─ chat/               # ChatPanel, Message, MessageParser UI, PromptInput
│  │  ├─ workbench/          # FileTree, CodeEditor, Preview, Terminal, Tabs, ResizableLayout
│  │  ├─ jambo/              # ProjectPicker, CollectionsBrowser, JamboConnectDialog
│  │  └─ ui/                 # shadcn/ui (Button, Dialog, Tabs, Switch, …)
│  ├─ lib/
│  │  ├─ ai/                 # providers, streamText, system prompts, artifact/message parser
│  │  ├─ webcontainer/       # boot, mount, run, writeFile, terminal binding
│  │  ├─ jambo/              # client API Jambo (auth token, getProject, getCollections, getEntries)
│  │  ├─ git/                # isomorphic-git + octokit (import/export GitHub)
│  │  └─ stores/             # nanostores: chat, files, workbench, jambo, settings
│  ├─ runtime/               # templates de départ (Next/Astro/Vite-SPA) + générateur lib/jambo.ts
│  └─ styles/
└─ docs/
```

### Flux de données
1. **Connexion Jambo** → token + `apiUrl` stockés (settings). Le client `lib/jambo` récupère projets, collections (schéma), exemples d'entrées.
2. **Prompt utilisateur** → on construit un **system prompt** enrichi du schéma des collections + framework cible → `streamText` (AI SDK) vers le provider choisi.
3. **Streaming** → le **parser d'artefacts** extrait les actions (`file` / `shell`) et les applique : `writeFile` (store + WebContainer), exécution de commandes.
4. **WebContainer** → `npm install` + `npm run dev` → URL de preview dans une iframe.
5. **Itération** → édition manuelle (CodeMirror) re-synchronise le WebContainer ; snapshots automatiques.
6. **Sortie** → export ZIP, ou push GitHub (octokit), ou déploiement (Netlify/Vercel/Cloudflare via token).

---

## 5. Fonctionnalités

### MVP
- [ ] Connexion à un projet **Jambo** (apiUrl + token) ; lecture du **schéma des collections**.
- [ ] **Chat IA** multi-provider en streaming, avec sélecteur de provider/modèle.
- [ ] **Parser d'artefacts** (fichiers + commandes shell) appliqués en direct.
- [ ] **WebContainer** : install + dev server + **preview** iframe.
- [ ] **Workbench** : arbre de fichiers, **CodeMirror**, **terminal xterm**, onglets redimensionnables.
- [ ] **Templates de départ** (au moins : Vite+React SPA, Astro, Next) avec `lib/jambo.ts` généré.
- [ ] **Snapshots auto** + rollback ; **export ZIP**.

### v1
- [ ] Import/Export **GitHub** (octokit), clone d'un repo.
- [ ] **Déploiement** 1-clic (Netlify/Vercel/Cloudflare Pages).
- [ ] **Détection d'erreurs de build** + bouton « Corriger » (auto-fix).
- [ ] **Multimodal** (images dans le prompt) si le provider le supporte.
- [ ] **Settings** : clés API providers, thème, préférences.

### v2
- [ ] Mode **SSR/edge** (le site interroge Jambo côté serveur).
- [ ] **Composants Jambo** prêts (listes, fiches, filtres, pagination, i18n).
- [ ] App **desktop Electron** (comme bolt.diy).

---

## 6. UX & Layout

- **Écran principal** façon bolt : **panneau de chat à gauche** (~38%), **workbench à droite** avec onglets **Aperçu / Code / Terminal**, séparateurs `react-resizable-panels`.
- **Loader de génération inline** en bas du fil (pas dans l'en-tête).
- **Barre d'actions collante** (erreurs de build → « Corriger ») juste au-dessus du composer.
- **État vide** : suggestions de prompts (cartes), sélection du template + du projet Jambo.
- **Thème sombre** par défaut, bascule clair/sombre. Animations sobres (framer-motion) sur les transitions de panneaux et l'apparition des messages.

---

## 7. Intégration Jambo (cœur de la valeur)

### Client `lib/jambo`
- Auth : header `Authorization: Bearer <API_TOKEN>` (token de projet Jambo).
- Endpoints à couvrir : liste des projets, schéma d'un projet (collections + champs + types), entrées d'une collection (pagination, locale, filtres), entrée par slug.
- Exposer un client typé : `getCollections()`, `getEntries(slug, {page, perPage, locale})`, `getEntry(slug, entrySlug)`.

### Injection dans l'IA
Le **system prompt** doit inclure :
- Le **schéma** des collections (slug, champs, types, requis).
- Le **framework** cible et le **format de sortie** d'artefacts (balises fichiers + commandes).
- La **consigne** d'utiliser `lib/jambo.ts` (jamais d'URL en dur) et de gérer pagination/i18n.

### Générateur `lib/jambo.ts`
Inséré dans chaque template de départ : un client prêt-à-l'emploi qui lit `import.meta.env.JAMBO_API_URL` et le token, et expose les fonctions ci-dessus côté app générée.

---

## 8. Sécurité

- **Tokens** : stockés côté client (localStorage chiffré ou cookie httpOnly via un mini-proxy) ; jamais committés.
- **WebContainer** : sandbox navigateur ; l'app générée tourne isolée. Iframe de preview **sans `allow-same-origin`** vis-à-vis de l'app hôte si du JS arbitraire peut s'y exécuter.
- **Clés API providers** : saisies par l'utilisateur, stockées localement ou proxifiées ; ne jamais les exposer dans le bundle.
- **CORS Jambo** : prévoir un mode proxy si l'API n'autorise pas l'origine du Workbench.

---

## 9. Design system

- **Typo** : un display caractériel (ex. *Space Grotesk*, *General Sans*, *Clash Display*) + un corps lisible (*Inter* déconseillé — préférer *Geist*, *IBM Plex*, *Satoshi*). Mono : *JetBrains Mono* / *Geist Mono* pour le code.
- **Couleurs** : base sombre (charbon/anthracite), accent franc (violet électrique ou vert acide), à committer en variables CSS.
- **Composants** : shadcn/ui sur Tailwind (Button, Tabs, Dialog, Switch, Tooltip, ScrollArea, Resizable).
- **Motion** : apparition échelonnée des messages, transition fluide des panneaux, micro-interactions sur les boutons d'action.

---

## 10. Roadmap

1. **Scaffolding** : Vite + React + TS + Tailwind + shadcn/ui ; layout Workbench + panneaux redimensionnables.
2. **WebContainer** : boot/mount/run + preview + terminal.
3. **IA** : AI SDK multi-provider + streaming + parser d'artefacts → écriture fichiers + commandes.
4. **Jambo** : connexion, schéma, injection prompt, `lib/jambo.ts`.
5. **Itération** : éditeur, snapshots, auto-fix, export ZIP.
6. **Sortie** : GitHub + déploiement.
7. **Polish** : design system, multimodal, Electron.

---

## 11. Commandes de démarrage

```bash
# Scaffolding
pnpm create vite@latest jambo-workbench -- --template react-ts
cd jambo-workbench
pnpm add ai @ai-sdk/openai @ai-sdk/anthropic @ai-sdk/google @ai-sdk/mistral @ai-sdk/deepseek ollama-ai-provider @openrouter/ai-sdk-provider
pnpm add @webcontainer/api @xterm/xterm @xterm/addon-fit @xterm/addon-web-links
pnpm add @codemirror/state @codemirror/view @codemirror/lang-javascript @codemirror/lang-html @codemirror/lang-css @codemirror/lang-json @uiw/codemirror-theme-vscode
pnpm add nanostores @nanostores/react zustand react-resizable-panels framer-motion lucide-react
pnpm add react-markdown remark-gfm rehype-raw rehype-sanitize shiki
pnpm add isomorphic-git @octokit/rest jszip file-saver diff zod use-debounce sonner
pnpm add -D tailwindcss postcss autoprefixer vite-plugin-node-polyfills vite-tsconfig-paths
npx tailwindcss init -p
```

**Important Vite** : pour WebContainer (SharedArrayBuffer), servir l'app avec les en-têtes :
```
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Embedder-Policy: require-corp
```
(plugin de headers dans `vite.config.ts` en dev + config d'hébergement en prod).

---

## 12. Critères d'acceptation (MVP)

- Connecter un projet Jambo et voir ses collections.
- Décrire un site en langage naturel → l'agent génère les fichiers → preview live en < 30 s après install.
- Éditer un fichier manuellement → la preview se met à jour.
- Le site généré affiche **de vraies données** issues d'une collection Jambo.
- Export ZIP fonctionnel.

---

*Rédigé comme brief de conception pour `dahovitech/jambo-workbench`. Adapter librement ; garder le cap : un atelier IA rapide, inspiré de bolt.diy, dont la valeur unique est la connexion native aux collections Jambo.*
