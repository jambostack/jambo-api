# Jambo Sites — Design Spec

> Date : 2026-05-29
> Statut : Validé (brainstorming) — en attente de revue du spec écrit

---

## Vision

Faire de Jambo un **tout-en-un** : une app construite dans le Workbench est **hébergée directement par Jambo** et accessible sur un **nom de domaine custom**, sans aucune infrastructure externe (ni Docker, ni Traefik, ni provider tiers).

Le devops pointe lui-même le DNS du domaine vers le serveur Jambo et gère le certificat SSL côté serveur. Jambo, lui, se charge de **relier un domaine à un projet** et de **servir l'app** quand une requête arrive avec ce `Host`.

**Flux complet :**
```
Workbench (génération IA) → Build statique (navigateur) → Publication dans Jambo
→ Liaison domaine → Jambo sert l'app sur le domaine custom
```

---

## Principe directeur & contraintes

- **Front statique uniquement.** L'app générée est un front statique qui consomme l'API Jambo (que Jambo héberge déjà lui-même). Pas de runtime Node côté serveur. Le SSR est explicitement hors scope (couvert par l'export ZIP pour le déploiement externe manuel).
- **Jambo est le serveur web.** Les fichiers buildés sont servis par Jambo (PHP/Symfony), donc **indépendant du type de serveur** (mutualisé, VPS, control panel…). Aucune dépendance à nginx/Traefik/Docker.
- **DNS & SSL hors scope code.** Le devops fait pointer le domaine vers le serveur Jambo et provisionne le SSL (reverse proxy, certbot, panel…). Jambo ne vérifie pas le DNS et ne provisionne pas de certificat.
- **Simplification.** On supprime les déploiements 1-clic et Jambo Cloud Docker pour réduire la surface et éviter les bugs futurs. On garde l'export ZIP comme porte de sortie pour l'externe.

---

## Périmètre

### Gardé
- Workbench : génération IA, preview WebContainer, éditeur de code, arbre de fichiers.
- **Export ZIP** (`ZipExportService` + route export + Dockerfiles des templates) : téléchargement pour déploiement externe manuel.

### Supprimé
- **Phase 2 — Deploy 1-clic** : `src/Service/Deploy/*` (DeployService, DeployProviderInterface, DeployResult, Vercel/Netlify/Railway providers), `src/Entity/DeployToken.php` + repo + migration `deploy_token`, `src/Controller/DeployOAuthController.php`, routes deploy/deploy-status/oauth du `WorkbenchController`, la gestion `deployIntegrations` dans `AppSettingsController` + l'onglet « Deploy » de `AppSettings.tsx` + le type `DeployIntegrationStatus`, la colonne `app_settings.deploy_integrations`, les clés i18n `app_settings.deploy.*` et `workbench.deploy.*` liées au 1-clic, les tests `tests/Service/Deploy/*`.
- **Phase 3 — Jambo Cloud (Docker/Traefik)** : `src/Service/Cloud/*` (TraefikLabelBuilder, ContainerOrchestratorInterface, DockerContainerOrchestrator, DnsResolverInterface, SystemDnsResolver, HostedAppService, CustomDomainService), `src/Entity/HostedApp.php` + `CustomDomain.php` + repos + migrations `hosted_app`/`custom_domain`, `src/Controller/CloudController.php` + `CustomDomainController.php`, `src/Message/DeployHostedAppMessage.php` + handler, `assets/js/pages/Projects/Workbench/CloudPanel.tsx`, l'onglet « Jambo Cloud » du DeployDrawer, `docker/traefik/*`, le wiring `services.yaml` cloud, les variables `.env` `jambo/cloud`, le routing messenger `DeployHostedAppMessage`, les clés i18n `workbench.cloud.*`, les tests `tests/Service/Cloud/*`.

### Ajouté
- Publication d'un build statique servi par Jambo (« Jambo Sites »).
- Liaison domaine ↔ projet (`SiteDomain`) + résolution par en-tête `Host`.
- Table de variables d'environnement par projet Workbench (`WorkbenchEnvVar`), injectées au build.
- Reskin de Jambo Studio/Workbench façon bolt.diy avec la palette Jambo.

---

## Architecture

Quatre unités, chacune avec une responsabilité claire :

1. **`WorkbenchEnvVar` (entité)** — paire clé/valeur par projet, éditable dans l'UI, injectée dans l'environnement de build.
2. **`SiteDomain` (entité)** — lie un domaine à un `WorkbenchProject`. Stockage simple, sans vérification.
3. **`PublishedSiteStorage` (service)** — écrit/lit les fichiers buildés sur le disque sous `var/published_sites/<projectUuid>/`, en dehors du web root.
4. **`SiteHostResolver` (EventSubscriber `kernel.request`)** — intercepte les requêtes dont le `Host` correspond à un `SiteDomain` et sert le fichier statique correspondant.

Le `WorkbenchController` gagne des routes pour : éditer les env vars, publier un build, gérer les domaines.

---

## Modèle de données

### `WorkbenchEnvVar`
```
id            int, PK
workbenchProject  FK → workbench_project (onDelete CASCADE, nullable: false)
keyName       string(120)   // nom de la variable (ex. PUBLIC_JAMBO_API_URL) — "key" est réservé en SQL, on utilise key_name
value         text
isSecret      bool          // masqué dans l'UI (affichage ••••), renvoyé seulement à la publication
createdAt     datetime_immutable
updatedAt     datetime_immutable
```
Contrainte d'unicité : `(workbench_project_id, key_name)`.

### `SiteDomain`
```
id            int, PK
uuid          uuid, unique
workbenchProject  FK → workbench_project (onDelete CASCADE, nullable: false)
domain        string(253), unique   // ex. "monsite.com" (lowercase, trim)
isPrimary     bool          // domaine principal (un seul true par projet, géré applicativement)
createdAt     datetime_immutable
```

### `WorkbenchProject` (existant — ajouts)
```
publishedAt   datetime_immutable, nullable   // dernière publication réussie
```
Le champ `files` existant continue de stocker le **source**. Les fichiers **buildés** ne vont PAS en base : ils vont sur le disque (voir storage).

### Stockage du build (hors base)
- Répertoire : `var/published_sites/<workbenchProjectUuid>/`
- Contient l'arborescence du `dist/` buildé (HTML/CSS/JS + assets).
- Réécrit intégralement à chaque publication (on vide puis on réécrit), pour éviter les fichiers orphelins.

---

## Flux de publication

1. **Build navigateur.** Dans le Workbench, l'utilisateur clique **« Publier »**. L'app est buildée dans **WebContainer** (déjà utilisé pour la preview) avec les `WorkbenchEnvVar` injectées sous forme de fichier d'environnement adapté au framework (ex. `.env`/`.env.production`, variables `PUBLIC_*`/`NEXT_PUBLIC_*`). La commande de build vient du template (`getBuildCommand()`), la sortie statique est lue depuis le dossier de build du framework (`dist/`, `out/`, `.output/public/`, `build/` selon le template).
2. **Upload.** Le `dist/` produit est envoyé à Jambo via `POST /api/projects/{uuid}/workbench/{workbenchUuid}/publish` — payload : map `{ chemin relatif → contenu }` (texte en clair, binaire en base64 avec un flag). Limite de taille appliquée (voir gestion d'erreurs).
3. **Écriture.** `PublishedSiteStorage` vide `var/published_sites/<uuid>/` puis y écrit les fichiers ; `WorkbenchProject.publishedAt` est mis à jour.
4. **Domaines.** L'utilisateur **ajoute un ou plusieurs domaines** (`POST .../site/domains`). Jambo enregistre le `SiteDomain`. Un message indique au devops de pointer le DNS du domaine vers le serveur Jambo.

### Frameworks et dossier de sortie
Chaque template déclare son dossier de build statique via une nouvelle méthode `getStaticOutputDir(): ?string` sur `BaseTemplate` :
- Astro → `dist`
- Next.js (export statique) → `out`
- Nuxt (génération statique) → `.output/public`
- SvelteKit (adapter-static) → `build`
- `null` ⇒ le framework n'a pas de sortie statique exploitable ⇒ la publication est refusée avec un message clair (utiliser l'export ZIP).

---

## Service (résolution par `Host`)

`SiteHostResolver` écoute `kernel.request` avec une priorité **supérieure au routing** (ex. priorité 32) :

1. Lit l'en-tête `Host` de la requête.
2. Si le `Host` **n'est pas** un `SiteDomain` connu → ne fait rien (la requête suit le routing normal : admin, API, etc.).
3. Si le `Host` correspond :
   - Résout le `WorkbenchProject` lié.
   - Mappe `request.pathInfo` vers un fichier dans `var/published_sites/<uuid>/` :
     - `/` → `index.html`
     - `/chemin/` → `/chemin/index.html` si présent
     - sinon le fichier demandé tel quel.
   - Si le fichier existe → `Response`/`BinaryFileResponse` avec le bon `Content-Type` (par extension) et un `Cache-Control` raisonnable.
   - Sinon → **fallback SPA** : sert `index.html` (HTTP 200) si présent, sinon 404.
   - Pose `$event->setResponse(...)` pour court-circuiter le CMS.

Le domaine d'administration/API de Jambo n'est jamais intercepté (il n'est pas dans `SiteDomain`).

### Sécurité du service
- **Protection traversal** : le chemin résolu est normalisé (`realpath`) et doit rester strictement sous `var/published_sites/<uuid>/` ; sinon 404.
- Seuls les fichiers du build publié sont servis ; aucune exécution PHP, pas d'accès au reste du disque.

---

## Variables d'environnement

- Éditées dans le nouvel onglet **« Publier »** (CRUD simple : clé, valeur, flag « masquer »).
- À la publication, Jambo renvoie les env vars au client (y compris les masquées, puisque le build se fait côté navigateur) pour générer le fichier d'environnement du build.
- ⚠️ **Important — pas de vrai secret côté front statique** : toute variable injectée dans un build front statique se retrouve dans le JS livré au navigateur. Le flag `isSecret` ne fait que **masquer l'affichage dans l'UI Jambo** (style mot de passe) ; il **ne garantit aucune confidentialité réelle**. L'UI affichera un avertissement à ce sujet. Les vrais secrets doivent rester côté serveur (API Jambo), jamais dans les env vars du front.
- Valeurs par défaut suggérées à la création d'un projet : `PUBLIC_JAMBO_API_URL` (host public de Jambo) et `PUBLIC_JAMBO_PROJECT_UUID`.

---

## UI — DeployDrawer simplifié + Design language

### Structure
Le `DeployDrawer` passe de 3 onglets à **2** :
- **Export** (inchangé) : téléchargement ZIP + Dockerfile pour déploiement externe manuel.
- **Publier** (nouveau, remplace « 1-clic » et « Jambo Cloud ») :
  - Section **Variables d'environnement** : liste éditable (clé/valeur/secret), ajout/suppression.
  - Bouton **Publier** : déclenche build (WebContainer) → upload → écriture ; affiche l'état (build / upload / publié) et la date de dernière publication.
  - Section **Domaines** : liste des `SiteDomain`, ajout (champ domaine + validation), suppression, indication du domaine principal, et une note « Pointez le DNS de ce domaine vers le serveur Jambo ».

`CloudPanel.tsx` est supprimé ; un nouveau `PublishPanel.tsx` le remplace.

### Design language (bolt.diy × couleurs Jambo)
On adopte la **structure et le feeling de bolt.diy** avec **la palette Jambo existante** (vert émeraude sur sombre), sans introduire de nouvelle couleur.

- **Mode sombre forcé** sur la page Studio/Workbench (classe `dark` sur le conteneur racine de la page).
- **Profondeurs** (mapping bolt → tokens Jambo déjà présents dans `app.css`) :
  - depth-1 = `--background` `oklch(0.13 0.012 165)`
  - depth-2 = `--card` `oklch(0.17 0.014 165)`
  - depth-3 = `--muted` `oklch(0.22 0.016 165)`
- **Accent** = `--primary` émeraude `oklch(0.68 0.20 158)` (à la place du violet bolt).
- **Bordures** = `--border` blanc 9 %.
- **Layout bolt** : header fin (~54px), panneau chat à gauche / workbench (preview/code) à droite, panneaux arrondis, bordures subtiles, terminal sur fond sombre.
- Police : sans neutre existante du projet (pas de police décorative — fidélité au sobre de bolt).

Aucune variable de couleur nouvelle : on réutilise les tokens shadcn/Jambo (`--background`, `--card`, `--muted`, `--primary`, `--border`, etc.). Le reskin consiste à appliquer le layout/patterns bolt et à forcer le thème sombre sur le Studio.

---

## Gestion d'erreurs

- **Framework non statique** : `publish` refuse avec 422 et un message renvoyant vers l'export ZIP.
- **Build vide / échec** (aucun fichier reçu) : 422, `publishedAt` inchangé.
- **Taille** : payload de publication plafonné (ex. 25 Mo cumulés) → 413/422 avec message.
- **Domaine invalide** : regex hostname stricte → 422. **Domaine déjà pris** (sur un autre projet) → 409.
- **Traversal** dans le service de résolution : 404 silencieux.
- **Autorisation** : toutes les routes de publication/domaines exigent `project.manage` ; lecture d'état `project.view`. (Mêmes voters que le reste du Workbench.)

---

## Tests

- **`WorkbenchEnvVar`** : unicité (project, key_name) ; sérialisation masque les secrets en lecture.
- **`PublishedSiteStorage`** : écriture remplace intégralement (pas d'orphelins) ; protection traversal (un chemin `../` est rejeté).
- **`SiteHostResolver`** (test unitaire avec une fausse requête + storage mocké/temp) : Host inconnu → pas de réponse ; Host connu + fichier présent → bon Content-Type ; Host connu + chemin absent → fallback `index.html` ; chemin traversal → 404.
- **Validation domaine** (controller ou service) : regex, doublon → 409.
- Les suites Deploy/Cloud supprimées disparaissent ; la suite globale doit rester verte après suppression (drop des tables + nettoyage DI/routes/i18n).

---

## Migrations

- **Drop** : `deploy_token`, `hosted_app`, `custom_domain`, colonne `app_settings.deploy_integrations`.
- **Create** : `workbench_env_var`, `site_domain`, colonne `workbench_project.published_at`.
- La base de test doit être migrée (`doctrine:migrations:migrate --env=test`) avant exécution de la suite.

---

## Hors périmètre (YAGNI)

- SSR / runtime Node hébergé par Jambo (l'export ZIP couvre l'externe).
- Vérification DNS et provisioning SSL automatique (devops).
- Déploiements 1-clic (Vercel/Netlify/Railway) et orchestration Docker/Traefik (supprimés ; réexplorables plus tard sur une base propre).
- Build côté serveur (le build reste dans le navigateur via WebContainer).
- Cache/CDN, invalidation, rollback de versions publiées (itération future).

---

## Découpage en sous-unités (pour le futur plan)

1. **Nettoyage** : suppression Phase 2 1-clic + Phase 3 Cloud (code, entités, migrations de drop, DI, routes, i18n, UI), conservation de l'export ZIP. Suite verte.
2. **Entités & migrations** : `WorkbenchEnvVar`, `SiteDomain`, `WorkbenchProject.publishedAt`.
3. **Storage** : `PublishedSiteStorage` (+ tests).
4. **Résolution Host** : `SiteHostResolver` (+ tests).
5. **API Workbench** : routes env vars (CRUD), publish, domaines (CRUD) + sérialisation.
6. **Build/publish côté front** : intégration WebContainer build → upload, dans `PublishPanel.tsx`.
7. **UI Publier + reskin bolt.diy** : `PublishPanel`, DeployDrawer à 2 onglets, thème sombre Studio.
8. **i18n** FR/EN/ES/AR des nouvelles clés.
9. **Validation** : suite verte + build webpack.
