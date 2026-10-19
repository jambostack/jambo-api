# ROADMAP DE CORRECTION ET DE MISE EN PRODUCTION — JAMBO API
**Dépôt audité** : [https://github.com/jambostack/jambo-api](https://github.com/jambostack/jambo-api)  
**Chemin local** : `c:\laragon\www\jamboapicms`  
**Date d'audit** : 19 Octobre 2026  
**Statut** : Document de référence opposable — Plan directeur de remédiation  
**Niveau d'assurance qualité** : Rigueur d'ingénierie logicielle & Preuves factuelles  

---

## SOMMAIRE
1. [A. Synthèse exécutive](#a-synthèse-exécutive)
2. [B. Périmètre et limites de l'audit](#b-périmètre-et-limites-de-laudit)
3. [C. Cartographie fonctionnelle](#c-cartographie-fonctionnelle)
4. [D. Registre exhaustif des anomalies](#d-registre-exhaustif-des-anomalies)
5. [E. Registre des ambiguïtés et décisions à prendre](#e-registre-des-ambiguïtés-et-décisions-à-prendre)
6. [F. Architecture cible et stratégie de correction](#f-architecture-cible-et-stratégie-de-correction)
7. [G. Roadmap d'exécution par phases (Tâches FIX-001 à FIX-032)](#g-roadmap-dexécution-par-phases)
8. [H. Matrice de traçabilité](#h-matrice-de-traçabilité)
9. [I. Plan de tests et stratégie de non-régression](#i-plan-de-tests-et-stratégie-de-non-régression)
10. [J. Critères objectifs de préparation à la production](#j-critères-objectifs-de-préparation-à-la-production)
11. [K. Risques résiduels et prochaines étapes](#k-risques-résiduels-et-prochaines-étapes)

---

## A. SYNTHÈSE EXÉCUTIVE

### 1. État général du projet
Le projet **Jambo API** est un CMS Headless multi-tenant développé sous **Symfony 7.2 (PHP 8.4)**, intégrant un modèle de données hybride EAV / JSON, un moteur d'automatisations par graphes de flux, des fonctionnalités d'orchestration IA, une intégration MCP (Model Context Protocol), et une couche CDN de transformation d'images.

L'analyse approfondie du dépôt révèle un **décalage critique entre le niveau de maturité revendiqué dans la documentation / releases git (v1.26.0 annoncée « Production Ready ») et la réalité technique du code source**.

### 2. Niveau de maturité technique estimé : **Pré-Alpha / Prototype Avancé (Niveau 2/5)**
Le projet ne peut en aucun cas être déployé en production dans son état actuel. Les causes objectives de cette évaluation sont :
- **Secrets de production en clair dans git** : Le fichier `.env` suivi par git contient les mots de passe de base de données OVHcloud, identifiants SMTP de production et clés JWT secrètes.
- **Blocages stricts au build et à l'installation** : `composer.lock` est désynchronisé (`composer install` échoue en mode non-interactif), la suite de migrations Doctrine échoue sur une base vierge en raison de doublons de tables (`form`, `form_submission`), et l'assistant `/install` est verrouillé derrière une authentification obligatoire alors qu'aucun utilisateur n'est encore provisionné (impasse absolue).
- **Failles de sécurité critiques (P0)** :
  - Écriture de fichier arbitraire / RCE via path traversal dans le module de publication de sites statiques (`PublishedSiteStorage.php`).
  - Fuite massive de données personnelles (PII) et annuaire administrateur complet exposé sur le web public sans authentification (`/api/users`, `/api/roles`, `/api/permissions`).
  - Fuite intégrale des End-Users et collections cross-tenant via l'endpoint MCP public (`/api/projects/{uuid}/mcp`).
  - Désactivation accidentelle du garde-fou multi-tenant (`EnsureProjectMemberSubscriber.php`) sur plus de 15 routes fondamentales par désalignement du paramètre d'URL (`{uuid}` au lieu de `{projectUuid}`).
- **Implémentations simulées ou trompeuses (Fakes)** :
  - L'assistant IA en streaming (`ContentAiStreamController.php`), documenté comme un assistant temps-réel alimenté par 10 LLMs, n'appelle aucun modèle IA : il découpe la requête de l'utilisateur par paquets de 4 mots avec un `usleep(30000)` et renvoie les mots de l'utilisateur.
  - Le module IdP OAuth2 multi-tenant n'a **aucun endpoint `/authorize`**, rendant impossible l'obtention d'un code d'autorisation par un utilisateur réel.
  - Le stockage relationnel des répéteurs (`ContentFieldGroup`) mis en avant en v1.20 n'est branché à aucun contrôleur ni formateur de sortie : le système continue de manipuler des blobs JSON.
  - La sandbox hermétique Twig (`NativeTwigSecurityPolicy.php`) n'est jamais injectée dans le conteneur Symfony ni dans l'environnement Twig réel.
  - La release `v1.26.0` annonçant « Automated CI/CD pipeline & smoke tests complete » ne contient aucun workflow GitHub Actions (le dossier `.github/workflows` a été supprimé).

### 3. Principaux risques opérationnels
| Catégorie | Risque | Conséquence |
| :--- | :--- | :--- |
| **Sécurité** | Compromission totale du serveur | Exécution de code arbitraire via traversée de répertoire lors du build statique. |
| **Confidentialité** | Violation RGPD majeure | Exposition publique non authentifiée des comptes administrateurs, emails et clients finaux. |
| **Disponibilité** | Crash de l'installation et des builds | Impossibilité d'exécuter `composer install`, les migrations Doctrine ou de lancer le conteneur Docker. |
| **Fonctionnel** | Rupture de service client | Les fonctionnalités IA, OAuth2 et rendu d'images CDN échouent ou renvoient des fictions. |

---

## B. PÉRIMÈTRE ET LIMITES DE L'AUDIT

### 1. Périmètre audité
- **Fichiers de configuration & dépendances** : `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `config/packages/*.yaml`, `.env*`.
- **Contrôleurs et points d'entrée HTTP** : 42 contrôleurs dans `src/Controller/`, couvrant les espaces `Admin/`, `AdminApi/`, `Api/`, `Auth/`, `Public/`, `Settings/`, `UserManagement/`.
- **Modèles de données & persistance** : 34 entités dans `src/Entity/`, 19 migrations dans `migrations/`, logique EAV et hydratation.
- **Sécurité & Contrôle d'accès** : `security.yaml`, `EnsureProjectMemberSubscriber.php`, `ProjectVoter.php`, `NativeTwigSecurityPolicy.php`, `ApiTokenChecker.php`.
- **Services métier & Moteurs** : `AutomationEngine`, `FlowInterpreter`, `PublishedSiteStorage`, `ImageTransformService`, `AiContentService`, `ProjectOAuthService`, `DirectUploadService`.
- **Conteneurisation & Déploiement** : `Dockerfile`, `docker/nginx.conf`, `docker/php.ini`, `docker/supervisord.conf`, `deploy.py`.
- **Tests** : 16 suites de tests PHPUnit dans `tests/`, tests E2E Playwright dans `e2e/`.

### 2. Outils et commandes exécutées localement
- Inspection statique complète de l'arborescence et recherche par motifs (regex ripgrep).
- Validation Composer : `composer validate` (rapporte `composer.lock` désynchronisé).
- Simulation d'installation : `composer install --dry-run` (rapporte la divergence de lockfile).
- Examen de l'historique git : `git log`, `git show`, analyse des commits `f938c05` et `a6c316a`.
- Environnement d'exécution de référence : Windows 11 / Laragon, PHP 8.4.12 CLI, Composer 2.10.2, Node 22.14.0 / npm 11.19.0.

### 3. Limites de l'audit
- **Absence de vendor/ et node_modules/ locaux** : Le checkout audité ne contenait pas les dépendances pré-installées. Pour respecter la règle de non-modification du dépôt durant la phase d'audit, aucune écriture non autorisée n'a été réalisée.
- **Absence de base de données active connectée** : Les tests dynamiques n'ont pas été lancés contre une base de données MySQL réelle pour éviter toute altération de la base distante référencée dans le `.env`.
- **Accès GitHub Actions distant** : Le dépôt GitHub distant n'étant pas accessible en modification, la confirmation de l'absence de pipelines distants s'est faite via l'historique git local.

---

## C. CARTOGRAPHIE FONCTIONNELLE

| Identifiant | Module / Fonctionnalité | Point d'entrée HTTP / Commande | Composants clés | Statut réel | Écart constaté / Preuve |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **F-01** | **Authentification CMS (Session / Form)** | `POST /login`, `POST /logout` | `SecurityController`, `security.yaml` | **Opérationnel** | Fonctionnel sous condition de création préalable d'un compte. |
| **F-02** | **Gestion des Utilisateurs CMS** | `GET /api/users`, `POST /api/users` | `UserController`, `UserRepository` | **Défaillant / Faille** | Pas d'authentification sur `GET /api/users` ni `GET /api/users/{id}` (`UserController.php:26,115`). Fuite PII totale. |
| **F-03** | **Gestion des Rôles & Permissions** | `GET /api/roles`, `GET /api/permissions` | `RoleController`, `PermissionController` | **Défaillant / Faille** | Pas de protection sur `GET /api/roles` ni `GET /api/permissions`. Exposé publiquement. |
| **F-04** | **Assistant d'Installation Initiale** | `GET|POST /install` | `InstallController.php` | **Bloqué** | Non autorisé dans `security.yaml` : redirigé vers `/login`. Impossible d'initialiser une instance neuve. |
| **F-05** | **Administration API (Admin API / PAT)** | `GET|POST /admin-api/v1/*` | `PersonalAccessToken`, `AdminApi/*Controller` | **Partiel / Faille** | `ProjectVoter::VIEW` utilisé pour la mise à jour et suppression de projets et collections (`CollectionController.php:67,84,101`). |
| **F-06** | **Garde-fou Multi-Tenant Projets** | `Event KernelEvents::REQUEST` | `EnsureProjectMemberSubscriber.php` | **Défaillant** | Lit `$request->attributes->get('projectUuid')`. Null sur 15+ routes définissant `{uuid}`. Biais d'isolation complet. |
| **F-07** | **Studio IA — Streaming Inline** | `POST /admin/api/content-ai/stream` | `ContentAiStreamController.php` | **SIMULÉ** | N'appelle aucun LLM. Découpe la chaîne utilisateur par `array_chunk($words, 4)` et dort 30ms (`ContentAiStreamController.php:58-79`). |
| **F-08** | **Studio IA — Génération d'Image** | `POST /api/projects/{uuid}/studio/ai-generate-image` | `StudioController.php`, `AiContentService` | **Partiel / Risque** | Téléchargement d'image via `@file_get_contents($imageUrl)` non assaini (`StudioController.php:1402`). Risque SSRF/LFI. |
| **F-09** | **Studio IA — Génération de Schéma** | `POST /api/projects/{uuid}/studio/ai-schema` | `StudioController.php`, `AiContentService` | **Opérationnel** | Requête bien transmise au provider IA configuré avec fallback mock. |
| **F-10** | **Identity Provider OAuth2 (RFC 6749)** | `POST /api/v1/projects/{uuid}/oauth/token` | `ProjectOAuthController`, `ProjectOAuthService` | **INCOMPLET** | **Pas d'endpoint `/authorize`**. Méthode `createAuthorizationCode` jamais invoquée hors tests. Inutilisable. |
| **F-11** | **Bac à sable Twig Hermétique** | N/A (Service de rendu) | `NativeTwigSecurityPolicy.php` | **ABSENT** | Classe existante isolée mais **non enregistrée dans Symfony/Twig** (`twig.yaml` / `services.yaml`). |
| **F-12** | **Publication de Sites Statiques** | `POST /api/projects/{uuid}/studio/publish` | `PublishedSiteStorage.php`, `StudioController` | **Vulnérable P0** | Écriture directe de fichiers sans vérification de traversée de chemin (`PublishedSiteStorage.php:30-36`). RCE possible. |
| **F-13** | **Serveur MCP (Model Context Protocol)** | `POST /mcp`, `POST /api/projects/{uuid}/mcp` | `McpController.php`, `JamboApiMcpServer.php` | **Vulnérable P0** | `/api/projects/{uuid}/mcp` contourne l'auth et permet de lister tous les utilisateurs finaux et collections sans jeton. |
| **F-14** | **CDN & Transformation d'Images** | `GET /cdn/media/{uuid}` | `ImageTransformController`, `docker/nginx.conf` | **Défaillant** | Nginx intercepte `/cdn/media/` vers des fichiers statiques locaux (`nginx.conf:24-27`). Incompatible S3/R2 et transformations à la volée. |
| **F-15** | **Répéteurs & Groupes Relationnels** | Formateurs d'API EAV | `ContentFieldGroup.php`, `EavDataFormatterService` | **SIMULÉ** | `ContentFieldGroup` existe en DB mais n'est jamais lu ni hydraté. Les réponses API lisent toujours le JSON blob (`EavDataFormatterService.php:135`). |
| **F-16** | **Moteur d'Automatisations (Flows)** | Événements Doctrine `ContentEvent` | `AutomationEngine`, `AutomationTriggerSubscriber` | **Partiel / Synchrone** | Les automatisations s'exécutent de manière synchrone et bloquante dans le cycle HTTP, ignorant la file Messenger. |
| **F-17** | **Recherche Fulltext & Vectorielle** | `GET /api/projects/{uuid}/search` | `SearchController.php`, `SearchService.php` | **Opérationnel** | Requêtes SQL MATCH AGAINST et LIKE fonctionnelles. |
| **F-18** | **SEO, Sitemap & Redirections** | `GET /{uuid}/sitemap.xml`, `redirects/resolve` | `SitemapGenerator`, `RedirectResolver` | **Opérationnel** | Génération XML et résolution des redirections fonctionnelles. |
| **F-19** | **Téléversement Direct (Direct Upload / S3)** | `POST /api/projects/{uuid}/media/upload-url` | `DirectUploadService.php` | **Opérationnel** | Signatures AWS S3 / Cloudflare R2 conformes. |
| **F-20** | **Migrations de Base de Données** | `doctrine:migrations:migrate` | `migrations/*.php` | **Bloqué** | Conflit fatal entre `Version20260624070523.php` et `Version20260629084616.php` (tables déjà existantes). |
| **F-21** | **Conteneur Docker de Production** | `docker build .` | `Dockerfile`, `docker/*` | **Bloqué** | Échec de build sur `RUN composer install` (lockfile désynchronisé) et `RUN php bin/console cache:warmup` sans DB. |
| **F-22** | **Script de Déploiement** | `python deploy.py` | `deploy.py` | **Défaillant** | Exécute un simple `git pull` sur le serveur sans `composer install`, sans migration, sans `cache:clear`. |
| **F-23** | **Paramètres Utilisateur (Profil/Mot de passe)** | `GET|PUT /api/settings/password` | `PasswordController.php`, `ProfileController.php` | **Défaillant** | Appels à `$this->getUser()` sur des routes publiques sans validation null : crashe en `TypeError` 500 unhandled. |
| **F-24** | **Pipeline CI/CD Automatisé** | `.github/workflows/*` | Supprimé en commit `f938c05` | **ABSENT** | Revendiqué en `v1.26.0` mais physiquement inexistant dans le dépôt. |

---

## D. REGISTRE EXHAUSTIF DES ANOMALIES

### [SEC-001] Fuite de secrets de production et identifiants dans le suivi git
- **Localisation** : `.env` (lignes 11, 19, 27), `deploy.py` (ligne 13).
- **Preuve** :
  ```env
  DATABASE_URL="mysql://jprud67_jamboapi:***REDACTED_PASSWORD***@2.24.10.163:3306/jprud67_jamboapi?serverVersion=10.11.8-MariaDB&charset=utf8mb4"
  MAILER_DSN=smtp://noreply@jambostack.site:***REDACTED_PASSWORD***@smtp.hostinger.com:465
  MERCURE_JWT_SECRET="***REDACTED_JWT_SECRET***"
  ```
- **État** : Confirmé.
- **Comportement observé** : Identifiants de connexion MySQL OVH, mot de passe SMTP Hostinger et IP de production commités et suivis dans l'historique git public/partagé.
- **Comportement attendu** : Aucun secret réel dans `.env`. Seul `.env.example` doit contenir des valeurs fictives documentées.
- **Cause racine** : Manque d'assainissement avant commit initial et absence de `.env` dans `.gitignore` standard.
- **Impact** : Compromission complète immédiate de la base de données de production et du serveur de messagerie.
- **Gravité** : Critique | **Priorité** : P0.
- **Correction recommandée** : Révocation/rotation immédiate des mots de passe DB et SMTP distants. Suppression de `.env` du suivi git (`git rm --cached .env`), ajout au `.gitignore`, et template sécurisé dans `.env.example`.
- **Dépendances** : Aucune.
- **Tests de validation** : Vérifier que `git status` ignore `.env` et que `.env.example` ne contient aucune chaîne secrète.
- **Critères d'acceptation** : Aucun mot de passe réel présent dans l'arborescence git.

---

### [SEC-002] Écriture de fichier arbitraire et RCE via Directory Traversal dans la publication statique
- **Localisation** : `src/Service/PublishedSiteStorage.php` (lignes 29-36).
- **Preuve** :
  ```php
  29: foreach ($files as $relativePath => $content) {
  30:     $filePath = $projectDir . '/' . ltrim($relativePath, '/');
  31:     $dir = dirname($filePath);
  32:     if (!is_dir($dir)) {
  33:         mkdir($dir, 0755, true);
  34:     }
  35:     file_put_contents($filePath, $content);
  36: }
  ```
- **État** : Confirmé.
- **Comportement observé** : `publish()` concatène directement `$relativePath` sans valider l'absence de séquences `../`. Contrairement à `readFile()` (lignes 58-60) qui implémente une vérification `str_starts_with`, `publish()` écrit n'importe où sur le disque.
- **Comportement attendu** : Rejet strict de tout chemin contenant `..`, des chemins absolus ou résolvant hors du dossier racine du projet (`$projectDir`).
- **Cause racine** : Oubli d'application du contrôle de chemin canonique dans la méthode d'écriture.
- **Impact** : Écriture de scripts PHP dans `/public` ou écrasement de fichiers de configuration système entraînant une exécution de code à distance (RCE).
- **Gravité** : Critique | **Priorité** : P0.
- **Correction recommandée** : Vérifier que chaque chemin résolu commence strictement par `$projectDir . DIRECTORY_SEPARATOR` avant tout appel à `file_put_contents()`. Lever une `InvalidArgumentException` en cas d'anomalie.
- **Dépendances** : Aucune.
- **Tests de validation** : Test unitaire tentant d'injecter `../../public/shell.php` et vérifiant le blocage avec exception.
- **Critères d'acceptation** : Impossible d'écrire un fichier hors du dossier de destination du projet.

---

### [SEC-003] Fuite massive de données personnelles (PII) et d'utilisateurs CMS non authentifiée
- **Localisation** : `config/packages/security.yaml` (ligne 73), `src/Controller/UserManagement/UserController.php` (lignes 26-45, 115-124), `RoleController.php` (ligne 26), `PermissionController.php` (ligne 18).
- **Preuve** :
  `security.yaml:73` :
  ```yaml
  - { path: ^/api, roles: PUBLIC_ACCESS }
  ```
  `UserController.php:26,115` :
  ```php
  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Request $request): JsonResponse { ... } // Pas de #[IsGranted]
  
  #[Route('/{id}', name: 'show', methods: ['GET'])]
  public function show(int $id): JsonResponse { ... } // Pas de #[IsGranted]
  ```
- **État** : Confirmé.
- **Comportement observé** : Tout visiteur anonyme peut interroger `GET /api/users`, `GET /api/users/{id}`, `GET /api/roles`, `GET /api/permissions` et extraire l'intégralité des noms, emails, rôles et métadonnées des administrateurs du CMS.
- **Comportement attendu** : Seuls les utilisateurs connectés disposant de la permission `users.view` ou `users.manage` peuvent consulter ces ressources.
- **Cause racine** : Exposition globale de `^/api` en `PUBLIC_ACCESS` combinée à l'absence d'attribut `#[IsGranted]` sur les contrôleurs de gestion des utilisateurs.
- **Impact** : Violation RGPD majeure, reconnaissance exhaustive de la surface d'attaque et ciblage de phishing/brute-force.
- **Gravité** : Critique | **Priorité** : P0.
- **Correction recommandée** : Ajouter `#[IsGranted('ROLE_USER')]` au niveau de la classe `UserController`, `RoleController` et `PermissionController`, avec contrôle granulaire de permission. Modifier la règle de sécurité pour verrouiller `/api/users`, `/api/roles`, `/api/permissions`.
- **Dépendances** : Système de permissions.
- **Tests de validation** : Requête HTTP anonyme `GET /api/users` renvoyant 401 Unauthorized.
- **Critères d'acceptation** : Aucun accès anonyme possible aux données de comptes utilisateurs.

---

### [SEC-004] Divulgation intégrale de données et contournement d'accès via l'endpoint MCP
- **Localisation** : `src/Controller/McpController.php` (lignes 55-72, 100-131), `src/Mcp/JamboApiMcpServer.php` (lignes 68-87, 594-625).
- **Preuve** :
  `McpController.php:55-72` :
  ```php
  #[Route('/api/projects/{uuid}/mcp', name: 'mcp_project_endpoint', methods: ['POST'])]
  public function handleProject(string $uuid, Request $request): Response {
      // Aucun IsGranted, aucun appel à denyAccessUnlessGranted
      return $this->mcpServer->handleRequest($body, $context);
  }
  ```
  `JamboApiMcpServer.php:594-625` (`list_end_users`) :
  ```php
  function (array $args, array $ctx): array {
      $project = $this->findProject($args['project_uuid']);
      // Ne vérifie aucunement $ctx['user'] ni les droits d'accès
      return array_map(..., $qb->getQuery()->getResult());
  }
  ```
- **État** : Confirmé.
- **Comportement observé** : L'URL `/api/projects/{uuid}/mcp` ne valide aucune authentification. Un attaquant peut exécuter l'outil `list_end_users` et extraire tous les utilisateurs finaux (emails, champs personnalisés, métadonnées), ou appeler `/mcp` pour lister tous les projets de tous les tenants (`list_projects`).
- **Comportement attendu** : L'accès aux outils d'un projet doit exiger un jeton d'authentification valide (PAT ou ApiToken) associé à ce projet spécifique.
- **Cause racine** : Absence de validation d'identité dans `handleProject()` et outils MCP dépourvus de vérifications d'autorisations sur le contexte.
- **Impact** : Fuite de données cross-tenant et violation complète de l'isolation des projets.
- **Gravité** : Critique | **Priorité** : P0.
- **Correction recommandée** : Exiger une authentification stricte dans `McpController`, vérifier l'appartenance au projet via `ProjectVoter::VIEW`, et conditionner l'exécution de chaque outil MCP aux permissions associées.
- **Dépendances** : `EnsureProjectMemberSubscriber`, `ApiTokenChecker`.
- **Tests de validation** : Requête POST anonyme sur `/api/projects/{uuid}/mcp` doit retourner 401 ou 403.
- **Critères d'acceptation** : Aucun outil de projet ne s'exécute sans jeton d'accès autorisé.

---

### [SEC-005] Désactivation du garde-fou multi-tenant sur 15+ routes fondamentales
- **Localisation** : `src/EventSubscriber/EnsureProjectMemberSubscriber.php` (lignes 36-41).
- **Contrôleurs impactés** : `StudioController.php` (10 routes), `SearchController.php` (2 routes), `AiController.php` (6 routes), `GraphQLController.php` (1 route), `McpController.php` (1 route).
- **Preuve** :
  `EnsureProjectMemberSubscriber.php:36-41` :
  ```php
  36: $request     = $event->getRequest();
  37: $projectUuid = $request->attributes->get('projectUuid');
  38: 
  39: if ($projectUuid === null) {
  40:     return; // Sortie immédiate silencieuse !
  41: }
  ```
  `GraphQLController.php:31` :
  ```php
  #[Route('/api/projects/{uuid}/graphql', name: 'graphql_endpoint', methods: ['POST', 'GET'])]
  ```
- **État** : Confirmé.
- **Comportement observé** : Les routes déclarant `{uuid}` ne renseignent pas l'attribut `projectUuid`. L'événement `EnsureProjectMemberSubscriber` sort silencieusement à la ligne 40, désactivant la vérification d'appartenance au projet pour l'utilisateur connecté ou le token API.
- **Comportement attendu** : Le subscriber doit extraire indifféremment `projectUuid` ou `uuid`, ou les routes doivent être uniformisées avec `{projectUuid}`.
- **Cause racine** : Incohérence de nommage des paramètres de route entre les modules sans normalisation dans le subscriber central.
- **Impact** : Contournement des contrôles de sécurité multi-tenant sur l'ensemble des endpoints concernés.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Dans `EnsureProjectMemberSubscriber.php`, écrire `$projectUuid = $request->attributes->get('projectUuid') ?? $request->attributes->get('uuid');` et harmoniser le nommage des routes.
- **Dépendances** : Aucune.
- **Tests de validation** : Test fonctionnel vérifiant qu'un utilisateur n'appartenant pas à un projet reçoit un 403 sur `/api/projects/{uuid}/graphql`.
- **Critères d'acceptation** : Aucune route projet ne peut être requêtée sans contrôle d'appartenance effectif.

---

### [SEC-006] Élévation de privilèges et suppression arbitraire dans l'Admin API
- **Localisation** : `src/Controller/AdminApi/CollectionController.php` (lignes 67, 84, 101), `src/Controller/AdminApi/ProjectController.php` (lignes 63, 76).
- **Preuve** :
  `CollectionController.php:101` :
  ```php
  #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
  public function delete(string $uuid, string $slug, Request $request): JsonResponse {
      $this->requireScope($request, 'schema:write');
      ...
      $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project); // ERREUR : VIEW au lieu de MANAGE ou DELETE
      $this->provisioner->deleteCollection($c);
      return new JsonResponse(null, 204);
  }
  ```
- **État** : Confirmé.
- **Comportement observé** : Les endpoints de création, mise à jour et suppression de collections et de projets vérifient `ProjectVoter::VIEW`. Tout membre du projet ayant un droit de simple lecture (« Viewer ») et disposant d'un PAT avec scope `schema:write` ou `projects:write` peut supprimer l'intégralité du projet ou de ses collections.
- **Comportement attendu** : La suppression et modification exigent `ProjectVoter::MANAGE` ou `ProjectVoter::EDIT`.
- **Cause racine** : Copier-coller erroné de la vérification de vote de lecture sur les méthodes de mutation.
- **Impact** : Destruction intentionnelle ou accidentelle de schéma et de données par des profils en lecture seule.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Remplacer `ProjectVoter::VIEW` par `ProjectVoter::MANAGE` sur `create`, `update`, `delete` dans `CollectionController` et `ProjectController`.
- **Dépendances** : `ProjectVoter`.
- **Tests de validation** : Test avec un token d'un utilisateur au rôle « Viewer » tentant un DELETE sur une collection : doit recevoir 403 Forbidden.
- **Critères d'acceptation** : Seuls les administrateurs/gestionnaires de projet peuvent altérer ou supprimer des collections.

---

### [SEC-007] Risque SSRF / LFI et blocage serveur via `file_get_contents` non assaini
- **Localisation** : `src/Controller/StudioController.php` (lignes 1402, 1696).
- **Preuve** :
  ```php
  1402: $imageData = @file_get_contents($imageUrl);
  ...
  1696: $imageData = @file_get_contents($imageUrl);
  ```
- **État** : Confirmé.
- **Comportement observé** : Le contrôleur télécharge l'image générée en appelant directement `@file_get_contents($imageUrl)` sans restreindre le protocole (autorisant potentiellement `file:///`), sans validation d'IP privée (`169.254.169.254`, `127.0.0.1`), et sans timeout d'expiration HTTP explicite.
- **Comportement attendu** : Utilisation du client HTTP Symfony (`HttpClientInterface`) avec timeout strict, validation d'URL (protocole HTTPS uniquement) et interdiction des adresses réseau internes privées (anti-SSRF).
- **Cause racine** : Recours à une fonction PHP bas niveau synchrone et non filtrée.
- **Impact** : Fuite de métadonnées d'instance cloud, lecture locale de fichiers ou blocage des workers PHP-FPM sur URL lentes.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Remplacer `file_get_contents` par `$this->httpClient->request('GET', $imageUrl, ['timeout' => 10])` précédé d'une validation d'IP non privée.
- **Dépendances** : `HttpClientInterface`.
- **Tests de validation** : Tentative d'injection d'URL vers `http://169.254.169.254` doit être immédiatement bloquée.
- **Critères d'acceptation** : Aucun appel réseau sortant non contrôlé ou synchrone non borné en temps.

---

### [SIM-001] Assistant IA en streaming simulé (Mock trompeur)
- **Localisation** : `src/Controller/Admin/ContentAiStreamController.php` (lignes 16, 45-84).
- **Preuve** :
  ```php
  16: private readonly AiContentService $aiService, // Injecté mais jamais appelé !
  ...
  58: $words = explode(' ', $text);
  59: $chunks = array_chunk($words, 4);
  60: foreach ($chunks as $chunkIndex => $chunkWords) {
  ...
  78:     usleep(30000); // 30ms pour un effet streaming naturel
  79: }
  ```
- **État** : Confirmé.
- **Comportement observé** : Annoncé dans la documentation comme un « Assistant IA SSE inline alimenté par 10 LLMs », le code n'utilise jamais `$aiService`. Il prend le texte envoyé par l'utilisateur, le découpe par lots de 4 mots et simule un délai de 30ms avant de renvoyer le texte de l'utilisateur avec un préfixe cosmétique.
- **Comportement attendu** : Véritable appel en streaming vers le provider IA sélectionné (OpenAI / Anthropic / Gemini) avec transmission en flux réel des tokens générés.
- **Cause racine** : Implémentation factice livrée comme du code de production pour donner l'apparence d'une fonctionnalité achevée.
- **Impact** : Fonctionnalité entièrement non opérationnelle ; tromperie sur les capacités réelles du produit.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Connecter le contrôleur à `AiContentService::streamCompletion()` avec gestion des flux SSE natifs des SDK providers.
- **Dépendances** : `AiContentService`.
- **Tests de validation** : Test d'intégration avec mock du client HTTP validant la réception de tokens générés par un LLM distant.
- **Critères d'acceptation** : Les réponses proviennent du provider IA configuré et non d'une boucle statique sur l'entrée.

---

### [SIM-002] Répéteurs relationnels fantômes et absence de `ContentEntryService`
- **Localisation** : `src/Entity/ContentFieldGroup.php`, `src/Service/EavDataFormatterService.php` (lignes 135-136), `ROADMAP.md` (Phase 2, v1.20).
- **Preuve** :
  `EavDataFormatterService.php:135` :
  ```php
  'json', 'array', 'repeater',
  'relation', 'enumeration', 'tags' => $fieldValue->jsonValue,
  ```
- **État** : Confirmé.
- **Comportement observé** : L'entité `ContentFieldGroup` existe dans la base mais n'est injectée, hydratée ni sérialisée nulle part. Le service `ContentEntryService` annoncé en v1.20 n'existe physiquement pas dans le projet. L'API continue de lire et d'écrire des chaînes JSON non normalisées pour les répéteurs.
- **Comportement attendu** : Soit l'architecture relationnelle est complètement déployée et branchée (CRUD, hydratation, validation FK), soit le stockage JSON est officialisé et nettoyé.
- **Cause racine** : Refactorisation structurelle arrêtée en cours de route et documentée comme terminée.
- **Impact** : Impossibilité de requêter, trier ou garantir l'intégrité référentielle des sous-champs de répéteurs.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Acter formellement l'architecture cible (voir Section E & F) : implémenter `ContentEntryService` complet avec gestion des `ContentFieldGroup` ou consolider la validation schéma du JSON blob.
- **Dépendances** : `ContentEntryRepository`, `GraphQLSchemaGenerator`.
- **Tests de validation** : Création d'une entrée avec répéteur multi-lignes et vérification de la persistance en table relationnelle.
- **Critères d'acceptation** : Zéro code mort ou entité orpheline dans le modèle de persistance.

---

### [SIM-003] Identity Provider OAuth2 inopérant (Absence d'endpoint `/authorize`)
- **Localisation** : `src/Controller/ProjectOAuthController.php`, `src/Service/ProjectOAuthService.php` (lignes 45-60).
- **Preuve** :
  `ProjectOAuthController.php` ne déclare que :
  - `POST /token`
  - `GET|POST /userinfo`
  - `POST /revoke`
  Aucune route `/authorize` n'existe dans le contrôleur ni dans tout le projet.
  `ProjectOAuthService::createAuthorizationCode` n'est appelé que dans `tests/Service/ProjectOAuthServiceTest.php`.
- **État** : Confirmé.
- **Comportement observé** : Il est impossible pour une application tierce d'utiliser le flux OAuth2 standard (`authorization_code`) car aucun écran d'autorisation ou endpoint d'émission de code n'est exposé.
- **Comportement attendu** : Implémentation complète de l'endpoint `/authorize` avec authentification de l'utilisateur final, confirmation de consentement et redirection vers le client avec code PKCE.
- **Cause racine** : Implémentation partielle du standard RFC 6749 limitée à l'échange de jeton.
- **Impact** : Fonctionnalité inutilisable de bout en bout en conditions réelles.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Créer l'action `authorizeAction` dans `ProjectOAuthController` avec template de consentement et génération du code d'autorisation.
- **Dépendances** : `EndUserRepository`, `EndUserSession`.
- **Tests de validation** : Parcours E2E complet : requête `/authorize` -> authentification -> redirection avec `code` -> échange sur `/token`.
- **Critères d'acceptation** : Un client OAuth2 standard (ex. client OIDC générique) peut compléter une authentification.

---

### [SIM-004] Bac à sable de sécurité Twig orphelin et inactif
- **Localisation** : `src/Twig/NativeTwigSecurityPolicy.php`, `config/packages/twig.yaml`, `config/services.yaml`.
- **Preuve** :
  Recherche grep sur l'ensemble du projet : la classe n'apparaît que dans sa propre définition et dans son test unitaire `NativeTwigSecurityPolicyTest.php`. Elle n'est configurée dans aucun fichier de service ou extension Twig.
- **État** : Confirmé.
- **Comportement observé** : La politique de sécurité est vendue dans la documentation v1.25 comme une protection active contre les injections de templates (SSTI/RCE), mais elle n'est jamais activée dans l'application Symfony.
- **Comportement attendu** : Déclaration de la politique dans `services.yaml` et injection dans l'extension `Twig\Extension\SandboxExtension` appliquée aux rendus dynamiques.
- **Cause racine** : Création d'une classe sans déclaration de service ni intégration au conteneur d'injection de dépendances.
- **Impact** : Sentiment trompeur de sécurité ; vulnérabilité SSTI totale si des templates dynamiques sont rendus par Twig.
- **Gravité** : Moyenne | **Priorité** : P2.
- **Correction recommandée** : Enregistrer le service dans le conteneur Symfony et le lier à un renderer dédié aux templates non sécurisés.
- **Dépendances** : Twig Environment.
- **Tests de validation** : Tentative de rendu d'une balise interdite (`{{ app.request }}`) bloquée par une `SecurityError`.
- **Critères d'acceptation** : Les rendus de templates utilisateurs passent obligatoirement par la sandbox.

---

### [SIM-005] Release fictive du pipeline CI/CD et absence de contrôle continu
- **Localisation** : Git commit `a6c316a` (« release: v1.26.0 - Automated CI/CD pipeline, smoke tests & roadmap complete »), dossier `.github/workflows`.
- **Preuve** :
  L'historique montre la suppression de `.github/` au commit `f938c05`. Le commit `a6c316a` n'a modifié que `package.json`, `composer.json` et `TEST_ROADMAP.md` sans jamais recréer les fichiers de workflow.
- **État** : Confirmé.
- **Comportement observé** : Aucun pipeline d'intégration continue ne s'exécute sur le dépôt GitHub.
- **Comportement attendu** : Présence de workflows GitHub Actions exécutant tests unitaires, analyse statique, lint et smoke tests.
- **Cause racine** : Clôture administrative d'une roadmap sans validation effective des livrables.
- **Impact** : Absence totale de détection automatisée des régressions avant déploiement.
- **Gravité** : Moyenne | **Priorité** : P2.
- **Correction recommandée** : Recréer un fichier `.github/workflows/ci.yml` complet et fonctionnel.
- **Dépendances** : Configuration GitHub Actions.
- **Tests de validation** : Exécution réussie du pipeline sur push/pull request.
- **Critères d'acceptation** : Tests et contrôles de qualité exécutés automatiquement à chaque commit.

---

### [OPS-001] Incohérence fatale de `composer.lock` bloquant le build
- **Localisation** : `composer.json`, `composer.lock`.
- **Preuve** :
  Sortie de `composer validate` :
  ```text
  The lock file is not up to date with the latest changes in composer.json, you may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package>`.
  ```
- **État** : Confirmé.
- **Comportement observé** : La dépendance `spomky-labs/otphp` a été ajoutée/modifiée dans `composer.json` sans régénération du lockfile. Toute commande `composer install --no-dev --no-interaction` échoue immédiatement en environnement strict ou Docker.
- **Comportement attendu** : `composer.lock` en parfaite cohérence avec `composer.json`.
- **Cause racine** : Modification manuelle de `composer.json` sans exécution de `composer update --lock`.
- **Impact** : Impossible de construire l'image Docker ou de déployer sur une nouvelle infrastructure.
- **Gravité** : Critique | **Priorité** : P0.
- **Correction recommandée** : Exécuter `composer update --lock` pour resynchroniser les métadonnées de hachage.
- **Dépendances** : Composer CLI.
- **Tests de validation** : `composer validate --strict` retourne 0.
- **Critères d'acceptation** : `composer install --no-interaction` s'exécute sans aucun avertissement ni erreur.

---

### [OPS-002] Conflit de migrations Doctrine avec doublons de tables
- **Localisation** : `migrations/Version20260624070523.php` (lignes 41, 45) et `migrations/Version20260629084616.php` (lignes 23, 24).
- **Preuve** :
  `Version20260624070523.php` exécute :
  `CREATE TABLE form (...)` et `CREATE TABLE form_submission (...)`.
  `Version20260629084616.php` ré-exécute :
  `CREATE TABLE form (...)` et `CREATE TABLE form_submission (...)`.
- **État** : Confirmé.
- **Comportement observé** : L'exécution de `php bin/console doctrine:migrations:migrate` échoue avec l'erreur SQL `Table 'form' already exists`.
- **Comportement attendu** : La chaîne de migrations s'exécute de façon linéaire et idempotente depuis la première version jusqu'à la dernière sans collision.
- **Cause racine** : Génération aveugle d'une seconde migration complète recréant des tables déjà présentes dans la migration précédente sans contrôle différentiel.
- **Impact** : Impossibilité totale de provisionner une base de données de production neuve via les migrations.
- **Gravité** : Critique | **Priorité** : P0.
- **Correction recommandée** : Nettoyer `Version20260629084616.php` pour ne conserver que les altérations réelles ou fusionner les migrations conflictuelles.
- **Dépendances** : Doctrine Migrations.
- **Tests de validation** : Exécuter la suite complète de migrations sur une base MySQL vierge et vérifier le succès final.
- **Critères d'acceptation** : La commande `doctrine:migrations:migrate --no-interaction` s'achève avec succès sur base neuve.

---

### [OPS-003] Défaillance du Dockerfile et court-circuit Nginx sur le CDN
- **Localisation** : `Dockerfile` (lignes 25, 31), `docker/nginx.conf` (lignes 24-27).
- **Preuve** :
  `docker/nginx.conf:24-27` :
  ```nginx
  location /cdn/media/ {
      alias /app/public/uploads/media/cache/;
      try_files $uri =404;
  }
  ```
- **État** : Confirmé.
- **Comportement observé** :
  1. Le `Dockerfile` échoue à la ligne 25 en raison de l'anomalie OPS-001 (`composer.lock`), et à la ligne 31 (`cache:warmup`) si les variables d'environnement de DB ne sont pas injectées au build.
  2. Nginx intercepte `/cdn/media/` vers des fichiers statiques locaux pré-générés. Si un fichier n'est pas encore en cache, Nginx renvoie immédiatement un 404 au lieu de transférer la requête à `index.php` pour que `ImageTransformController` effectue le redimensionnement.
- **Comportement attendu** :
  1. Build Docker reproductible et propre.
  2. Fallback de Nginx vers PHP-FPM lorsque le fichier cache n'existe pas encore.
- **Cause racine** : Mauvaise configuration du bloc `try_files` dans Nginx et dépendances non résolues dans le Dockerfile.
- **Impact** : Conteneurs inutilisables et échec systématique des requêtes de transformation d'image à la volée en environnement conteneurisé.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Modifier `nginx.conf` : `try_files $uri /index.php$is_args$args;`. Mettre à jour le `Dockerfile` avec build multi-étapes et lockfile assaini.
- **Dépendances** : OPS-001.
- **Tests de validation** : `docker build -t jamboapi .` réussi et test HTTP de transformation d'image non encore mise en cache.
- **Critères d'acceptation** : L'image Docker démarre et traite les images CDN dynamiques.

---

### [OPS-004] Blocage de l'assistant d'installation par contrôle d'accès circulaire
- **Localisation** : `config/packages/security.yaml` (lignes 60-77), `src/Controller/InstallController.php` (ligne 16).
- **Preuve** :
  Dans `security.yaml`, la route `/install` n'est pas déclarée dans la liste des accès `PUBLIC_ACCESS`. Elle tombe sous la règle finale :
  `- { path: ^/, roles: ROLE_USER }`.
- **État** : Confirmé.
- **Comportement observé** : Sur une instance nouvellement déployée sans compte administrateur, toute tentative d'accès à `/install` redirige vers `/login`, empêchant la création du premier utilisateur et le contrôle des prérequis.
- **Comportement attendu** : `/install` doit être publiquement accessible uniquement si aucun utilisateur n'existe encore en base, puis verrouillé de façon permanente.
- **Cause racine** : Règle de pare-feu trop restrictive sans exception pour le bootstrap initial.
- **Impact** : Impossible d'utiliser l'assistant web d'installation officiel.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Ajouter `- { path: ^/install, roles: PUBLIC_ACCESS }` dans `security.yaml` et renforcer dans `InstallController` la vérification de l'absence totale d'utilisateurs (`$userRepository->count([]) === 0`).
- **Dépendances** : `security.yaml`.
- **Tests de validation** : Accès à `/install` sur base vide renvoyant le formulaire HTTP 200.
- **Critères d'acceptation** : L'assistant est accessible à froid et s'auto-verrouille dès le premier administrateur créé.

---

### [OPS-005] Script de déploiement dangereux et incomplet
- **Localisation** : `deploy.py` (lignes 44-53).
- **Preuve** :
  `deploy.py` n'exécute que `git pull origin main` et `npm run build`.
  Aucun appel à `composer install`, `php bin/console doctrine:migrations:migrate`, `php bin/console cache:clear` ou `cache:warmup`.
- **État** : Confirmé.
- **Comportement observé** : En cas de modification du code PHP ou de la structure de base de données, le déploiement laisse le serveur dans un état désynchronisé (classes manquantes, schémas DB obsolètes, cache applicatif périmé).
- **Comportement attendu** : Pipeline de déploiement atomique ou script complet assurant l'installation des dépendances, les migrations et le réchauffement du cache avec possibilité de rollback.
- **Cause racine** : Script de bricolage minimaliste non adapté à une application d'entreprise Symfony.
- **Impact** : Pannes de production immédiates à chaque déploiement comportant des modifications backend.
- **Gravité** : Élevée | **Priorité** : P1.
- **Correction recommandée** : Remplacer `deploy.py` par un déploiement basé sur des conteneurs ou un script d'automatisation incluant les étapes indispensables (migrations, cache, redémarrage PHP-FPM).
- **Dépendances** : Configuration serveur de production.
- **Tests de validation** : Déploiement en préproduction avec application sans interruption des migrations.
- **Critères d'acceptation** : Déploiement sans intervention manuelle corrective post-exécution.

---

### [OPS-006] Incompatibilité du CDN d'images avec le stockage cloud S3/R2
- **Localisation** : `src/Controller/ImageTransformController.php` (lignes 39-47).
- **Preuve** :
  ```php
  39: $mediaRoot = $this->getParameter('kernel.project_dir') . '/public/uploads/media/';
  40: $filePath = $mediaRoot . (string) $project->uuid . '/' . $media->fileName;
  41: if (!file_exists($filePath)) {
  42:     $filePath = $mediaRoot . $media->fileName;
  43: }
  44: if (!file_exists($filePath)) {
  45:     return new Response('Fichier introuvable', 404);
  46: }
  ```
- **État** : Confirmé.
- **Comportement observé** : Le contrôleur ne lit les fichiers sources que sur le système de fichiers local (`file_exists()`). Si les médias sont téléversés vers AWS S3 ou Cloudflare R2 via `DirectUploadService`, toute requête sur `/cdn/media/{uuid}` échoue en 404.
- **Comportement attendu** : Le contrôleur doit s'abstraire du stockage physique en passant par Flysystem (`StorageManager`) pour récupérer le flux source ou générer le cache.
- **Cause racine** : Couplage fort au système de fichiers local dans le contrôleur de transformation.
- **Impact** : Impossibilité d'utiliser un stockage distribué en cluster ou multi-serveurs pour les médias.
- **Gravité** : Moyenne | **Priorité** : P2.
- **Correction recommandée** : Adapter `ImageTransformController` pour rapatrier en cache local les fichiers distants via le pilote Flysystem avant transformation.
- **Dépendances** : `StorageManager`.
- **Tests de validation** : Transformation d'un média hébergé sur un bucket S3 simulé.
- **Critères d'acceptation** : Le CDN fonctionne de manière identique quel que soit le pilote de stockage actif.

---

### [BUG-001] Crashs par exceptions de type non gérées sur les paramètres utilisateur
- **Localisation** : `src/Controller/Settings/PasswordController.php` (lignes 28-32), `src/Controller/Settings/ProfileController.php` (lignes 24-27, 36).
- **Preuve** :
  `PasswordController.php:28-32` :
  ```php
  /** @var User $user */
  $user = $this->getUser();
  $data = $request->toArray();
  if (!$this->hasher->isPasswordValid($user, $data['current_password'] ?? ''))
  ```
- **État** : Confirmé.
- **Comportement observé** : Comme `/api` est public et que ces routes ne vérifient pas si `$user` est une instance de `User`, une requête non authentifiée déclenche une erreur fatale PHP 500 : `TypeError: Argument #1 ($user) must be of type PasswordAuthenticatedUserInterface, null given`.
- **Comportement attendu** : Réponse JSON 401 Unauthorized propre lorsque la session ou le token est absent.
- **Cause racine** : Hypothèse implicite infondée que le pare-feu filtre les requêtes anonymes avant le contrôleur.
- **Impact** : Erreurs 500 non interceptées exposant potentiellement des traces système.
- **Gravité** : Moyenne | **Priorité** : P2.
- **Correction recommandée** : Ajouter `#[IsGranted('ROLE_USER')]` sur les contrôleurs de paramètres et vérifier `if (!$user instanceof User) return $this->json(['error' => 'Unauthorized'], 401);`.
- **Dépendances** : `security.yaml`.
- **Tests de validation** : Requête non authentifiée sur `PUT /api/settings/password` renvoyant 401.
- **Critères d'acceptation** : Zéro TypeError lors d'appels non authentifiés.

---

### [BUG-002] Exécution bloquante synchrone des automatisations
- **Localisation** : `src/EventSubscriber/AutomationTriggerSubscriber.php` (lignes 55-61), `src/MessageHandler/ExecuteAutomationMessageHandler.php`.
- **Preuve** :
  `AutomationTriggerSubscriber.php:58` :
  ```php
  $this->engine->execute($automation, $payload); // Appel synchrone direct dans le cycle HTTP
  ```
- **État** : Confirmé.
- **Comportement observé** : Lors de la création ou modification d'une entrée, toutes les automatisations associées s'exécutent en série de manière bloquante dans la même requête HTTP. Si une automatisation contient un appel webhook ou l'envoi d'un email, la requête du client attend la fin du traitement réseau. Le handler asynchrone `ExecuteAutomationMessageHandler` a été créé mais est court-circuité.
- **Comportement attendu** : Dispatcher un message `ExecuteAutomationMessage` sur le bus Messenger pour traitement en arrière-plan par les workers asynchrones.
- **Cause racine** : Non-utilisation du message bus dans le subscriber d'événements.
- **Impact** : Dégradation massive des temps de réponse API, timeouts HTTP côté client et vulnérabilité au déni de service (DoS).
- **Gravité** : Moyenne | **Priorité** : P2.
- **Correction recommandée** : Injecter `MessageBusInterface` dans `AutomationTriggerSubscriber` et dispatcher `ExecuteAutomationMessage`.
- **Dépendances** : Symfony Messenger, transport Doctrine/Redis.
- **Tests de validation** : Vérifier que la requête de création de contenu répond en < 50ms et que l'automatisation s'exécute dans le worker.
- **Critères d'acceptation** : Aucun appel réseau externe bloquant dans le cycle de vie de la requête synchrone.

---

## E. REGISTRE DES AMBIGUÏTÉS ET DÉCISIONS À PRENDRE

### D-01 : Modèle de persistance des répéteurs — EAV Relationnel pur vs JSON Schemas
- **Contexte** : Le projet oscille entre un modèle EAV relationnel avec `ContentFieldGroup` (incomplet) et un stockage JSON blob dans `ContentFieldValue::jsonValue`.
- **Options** :
  - *Option A* : Terminer l'implémentation de `ContentFieldGroup` et `ContentEntryService` (pureté relationnelle, intégrité référentielle, requêtes SQL plus complexes).
  - *Option B* : Conserver le stockage JSON blob pour les répéteurs en durcissant la validation par JSON Schema (simplicité, performances accrues en lecture, flexibilité maximale).
- **Recommandation** : **Option B à court terme (Production)**, avec transition planifiée vers Option A uniquement pour les champs nécessitant des clés étrangères strictes. Supprimer les références trompeuses dans la documentation.
- **Impact du report** : Confusion technique chez les contributeurs et persistance de code orphelin.

---

### D-02 : Rôle du module OAuth2 — IdP autonome vs Passerelle d'authentification projet
- **Contexte** : `ProjectOAuthController` prétend être un Identity Provider OIDC/OAuth2 mais n'a pas d'endpoint d'autorisation interactive.
- **Options** :
  - *Option A* : Développer l'interface interactive `/authorize` avec gestion des consentements pour faire de Jambo un véritable IdP.
  - *Option B* : Recentrer le module sur l'authentification directe d'API pour les clients SPA/mobiles (flux Resource Owner Password ou JWT direct) et désactiver le grant `authorization_code`.
- **Recommandation** : **Option A** car la documentation et les SDK vendent cette capacité. Implémenter l'endpoint `/authorize` minimal conforme RFC 6749.
- **Impact du report** : Inutilisabilité des intégrations tierces annoncées.

---

### D-03 : Architecture de sécurité des routes `/api` — Whitelist stricte vs Pare-feu global
- **Contexte** : `security.yaml` expose l'intégralité de `^/api` en `PUBLIC_ACCESS`, reportant la responsabilité sur des subscribers d'événements qui souffrent de failles de nommage.
- **Options** :
  - *Option A* : Conserver `^/api` en `PUBLIC_ACCESS` et corriger chaque contrôleur individuellement avec `#[IsGranted]`.
  - *Option B* : Inverser la politique : verrouiller `^/api` par défaut sous `ROLE_USER` et n'ouvrir en `PUBLIC_ACCESS` que les routes expressément publiques (`/api/public/*`, `/api/{uuid}/auth/*`).
- **Recommandation** : **Option B (Sécurité par défaut)**. C'est le seul moyen d'éliminer définitivement les risques de fuites PII sur de futurs contrôleurs.
- **Impact du report** : Risque persistant de fuites de données à chaque ajout de point d'entrée API.

---

## F. ARCHITECTURE CIBLE ET STRATÉGIE DE CORRECTION

```mermaid
flowchart TD
    subgraph Edge ["Périmètre Frontal & Réseau"]
        NGINX["Nginx Reverse Proxy\n(Cache dynamique + Fallback PHP)"]
    end

    subgraph Security ["Couche de Sécurité & Filtrage"]
        FW["Symfony Firewall\n(Défaut: Authentification requise)"]
        WL["Whitelist Publique\n(/login, /install-init, /cdn, /public-api)"]
        TENANT["EnsureProjectMemberSubscriber\n(Résolution {uuid} & {projectUuid})"]
    end

    subgraph Core ["Noyau Applicatif (Symfony 7.2 / PHP 8.4)"]
        CTRL["Contrôleurs REST / GraphQL / Admin API"]
        VOTER["ProjectVoter & Permissions Granulaires\n(VIEW, EDIT, MANAGE, DELETE)"]
        
        subgraph Services ["Services Métier Durcis"]
            AUTO["AutomationEngine\n(Dispatch Asynchrone Messenger)"]
            AI["AiContentService\n(Vrai streaming LLM + Sandbox SSRF)"]
            STATIC["PublishedSiteStorage\n(Contrôle anti-traversal strict)"]
            CDN["ImageTransformService\n(Abstraction Flysystem / S3 / Local)"]
        end
    end

    subgraph Async ["Traitements en Arrière-plan"]
        MESSENGER["Symfony Messenger Bus"]
        WORKER["Workers d'exécution\n(Automatisations, Emails, Webhooks)"]
    end

    subgraph Storage ["Persistance & Données"]
        DB[("MySQL 8.0+ / MariaDB\n(Migrations linéaires et nettoyées)")]
        MEDIA[("Stockage Médias\n(S3 / R2 / Stockage Local)")]
    end

    NGINX --> FW
    FW --> WL
    FW --> TENANT
    TENANT --> CTRL
    CTRL --> VOTER
    VOTER --> Services
    AUTO --> MESSENGER
    MESSENGER --> WORKER
    STATIC --> Storage
    CDN --> MEDIA
    Services --> DB
```

### Principes structurants de la cible :
1. **Sécurité défensive par défaut** : Aucune route n'est publique à moins d'être explicitement listée dans une whitelist dédiée.
2. **Isolation multi-tenant étanche** : Tout paramètre identifiant un projet (`uuid`, `projectUuid`) fait l'objet d'un contrôle d'habilitation systématique avant exécution métier.
3. **Traitements externes asynchrones** : Tout appel à un service externe (webhook, mailer, modèle IA) est déporté dans un worker ou protégé par un timeout strict.
4. **Idempotence des opérations d'infrastructure** : Les migrations, builds et scripts de déploiement doivent pouvoir s'exécuter n'importe quand sans état d'erreur.

---

## G. ROADMAP D'EXÉCUTION PAR PHASES

```mermaid
gantt
    title Déroulement Chronologique des Phases de Remédiation
    dateFormat  YYYY-MM-DD
    section Phase 0 : Diagnostic & Secrets
    Assainissement Git & Secrets [FIX-001]     :p0_1, 2026-10-20, 1d
    Synchronisation Lockfile [FIX-002]        :p0_2, after p0_1, 1d
    Nettoyage Migrations DB [FIX-003]         :p0_3, after p0_2, 1d
    section Phase 1 : Sécurité P0 & Blocages
    Correctif Path Traversal RCE [FIX-004]    :p1_1, after p0_3, 1d
    Verrouillage Annuaire PII [FIX-005]       :p1_2, after p1_1, 1d
    Sécurisation Endpoint MCP [FIX-006]       :p1_3, after p1_2, 1d
    Harmonisation Tenant Guard [FIX-007]      :p1_4, after p1_3, 1d
    Correction Assistant Install [FIX-008]    :p1_5, after p1_4, 1d
    section Phase 2 : Réparations P1
    Droits Admin API [FIX-009]                :p2_1, after p1_5, 2d
    Protection Anti-SSRF IA [FIX-010]         :p2_2, after p2_1, 1d
    Correction Crashs Settings [FIX-011]      :p2_3, after p2_2, 1d
    Async Automation Messenger [FIX-012]      :p2_4, after p2_3, 2d
    section Phase 3 : Implémentations Réelles
    Vrai Streaming IA [FIX-013]               :p3_1, after p2_4, 3d
    Endpoint OAuth2 Authorize [FIX-014]       :p3_2, after p3_1, 3d
    Intégration Twig Sandbox [FIX-015]        :p3_3, after p3_2, 1d
    Clarification Répéteurs EAV [FIX-016]     :p3_4, after p3_3, 2d
    section Phase 4 : Ops & Infrastructure
    Correctif Docker & Nginx [FIX-017]        :p4_1, after p3_4, 2d
    Compatibilité S3 CDN [FIX-018]            :p4_2, after p4_1, 2d
    Pipeline CI/CD GitHub Actions [FIX-019]   :p4_3, after p4_2, 1d
    Script Déploiement Atomique [FIX-020]     :p4_4, after p4_3, 2d
    section Phase 5 : Stabilisation & Recette
    Tests Intégration E2E [FIX-021]           :p5_1, after p4_4, 3d
    Recette Production [FIX-022]              :p5_2, after p5_1, 2d
```

---

### Règle d'Or de Versionnement et Stratégie de Tagging Git (`v1.26.*`)
Conformément aux exigences de traçabilité stricte en production :
1. **Périmètre de version** : Toutes les corrections demeurent impérativement dans la série de correctifs **`v1.26.*`** (succédant immédiatement à `v1.26.0`).
2. **Atomicité des releases** : Chaque tâche de correction `FIX-xxx` donne lieu à un commit unitaire comprenant :
   - Le code source corrigé et ses tests de validation associés.
   - La mise à jour du champ `"version"` dans `package.json` (`"version": "1.26.X"`).
   - La mise à jour du champ `"version"` dans `composer.json` (`"version": "1.26.X"`).
   - L'ajout d'une entrée détaillée dans `CHANGELOG.md` sous la section `## [1.26.X] — YYYY-MM-DD`.
3. **Création de tags git réels** : Chaque tâche achevée est scellée par un tag git annoté :
   `git tag -a v1.26.X -m "fix(composant): libellé précis du correctif FIX-xxx"`
4. **Table d'attribution des tags de correctifs** :
   - `FIX-001` -> **Tag git `v1.26.01`** (Assainissement des secrets Git & `.gitignore`)
   - `FIX-002` -> **Tag git `v1.26.02`** (Resynchronisation `composer.lock`)
   - `FIX-003` -> **Tag git `v1.26.03`** (Résolution des conflits de migrations DB)
   - `FIX-004` -> **Tag git `v1.26.04`** (Protection Path Traversal & RCE `PublishedSiteStorage`)
   - `FIX-005` -> **Tag git `v1.26.05`** (Verrouillage annuaire utilisateurs PII)
   - `FIX-006` -> **Tag git `v1.26.06`** (Sécurisation MCP & isolation tenant)
   - `FIX-007` -> **Tag git `v1.26.07`** (Harmonisation route `{uuid}` dans `EnsureProjectMemberSubscriber`)
   - `FIX-008` -> **Tag git `v1.26.08`** (Déblocage assistant `/install`)
   - `FIX-009` -> **Tag git `v1.26.09`** (Permissions AdminApi Collections & Projets)
   - `FIX-010` -> **Tag git `v1.26.10`** (Protection anti-SSRF téléchargement d'images IA)
   - `FIX-011` -> **Tag git `v1.26.11`** (Contrôle null user dans les paramètres de compte)
   - `FIX-012` -> **Tag git `v1.26.12`** (Déport asynchrone des automatisations via Messenger)
   - `FIX-013` -> **Tag git `v1.26.13`** (Véritable flux SSE IA connecté aux providers LLM)
   - `FIX-014` -> **Tag git `v1.26.14`** (Endpoint OAuth2 `/authorize` interactif)
   - `FIX-015` -> **Tag git `v1.26.15`** (Intégration de la Sandbox Twig hermétique)
   - `FIX-016` -> **Tag git `v1.26.16`** (Consolidation EAV et validation des répéteurs)
   - `FIX-017` -> **Tag git `v1.26.17`** (Correction Nginx CDN fallback & Dockerfile)
   - `FIX-018` -> **Tag git `v1.26.18`** (Support Flysystem S3 dans `ImageTransformController`)
   - `FIX-019` -> **Tag git `v1.26.19`** (Restauration du pipeline CI/CD GitHub Actions)
   - `FIX-020` -> **Tag git `v1.26.20`** (Script de déploiement atomique sécurisé)
   - `FIX-021` -> **Tag git `v1.26.21`** (Couverture de tests de non-régression)
   - `FIX-022` -> **Tag git `v1.26.22`** (Validation de sortie et certification de production)

---

### PHASE 0 — Diagnostic Complémentaire, Assainissement des Secrets & Dépendances

#### [FIX-001] Assainissement des secrets et révocation des accès exposés
- **Problème source** : SEC-001.
- **Objectif technique** : Éliminer tout secret de l'historique et des fichiers suivis par git, révoquer les identifiants compromis.
- **Fichiers concernés** : `.env`, `.env.example`, `.gitignore`.
- **Modifications à réaliser** :
  1. Générer de nouveaux mots de passe pour l'utilisateur MySQL distant et la boîte SMTP.
  2. Retirer `.env` du suivi git via `git rm --cached .env`.
  3. Ajouter `.env` et `.env.local` au fichier `.gitignore`.
  4. Compléter `.env.example` avec des variables factices documentées (`DATABASE_URL=mysql://db_user:db_password@127.0.0.1:3306/db_name`).
- **Prérequis** : Accès aux consoles d'administration OVHcloud et Hostinger pour renouvellement des mots de passe.
- **Tests de validation** : `git status` ne doit plus afficher `.env`.
- **Critères d'acceptation** : Aucun secret réel dans l'arborescence git.
- **Priorité** : P0 | **Effort estimé** : 2h.

#### [FIX-002] Resynchronisation du lockfile Composer et validation des dépendances
- **Problème source** : OPS-001.
- **Objectif technique** : Résoudre la désynchronisation de `composer.lock` pour débloquer les installations automatisées.
- **Fichiers concernés** : `composer.json`, `composer.lock`.
- **Modifications à réaliser** :
  1. Exécuter `composer update --lock` pour recalculer les empreintes sans altérer les versions résolues.
  2. Valider l'intégrité via `composer validate --strict`.
  3. Tester `composer install --no-interaction --dry-run`.
- **Prérequis** : PHP 8.4 CLI local.
- **Tests de validation** : `composer validate` doit retourner 0 erreur.
- **Critères d'acceptation** : Le build Composer s'exécute sans avertissement en mode non-interactif.
- **Priorité** : P0 | **Effort estimé** : 1h.

#### [FIX-003] Linéarisation et résolution des doublons dans les migrations Doctrine
- **Problème source** : OPS-002.
- **Objectif technique** : Supprimer les créations en double de tables pour garantir l'exécution sans erreur sur base neuve.
- **Fichiers concernés** : `migrations/Version20260629084616.php`.
- **Modifications à réaliser** :
  1. Inspecter `Version20260624070523.php` et `Version20260629084616.php`.
  2. Retirer de `Version20260629084616.php` les instructions `CREATE TABLE` redondantes (`form`, `form_submission`, `redirect`, `redirect_revision`, `seo_revision`).
  3. Conserver uniquement les ajouts de colonnes ou nouvelles tables réellement introduits par cette migration.
- **Prérequis** : FIX-002.
- **Tests de validation** : Exécuter `php bin/console doctrine:migrations:migrate --no-interaction` sur une base locale vierge.
- **Critères d'acceptation** : Toutes les migrations s'appliquent sans lever d'exception SQL.
- **Priorité** : P0 | **Effort estimé** : 2h.

---

### PHASE 1 — Sécurisation Critique & Levée des Blocages P0

#### [FIX-004] Neutralisation de la vulnérabilité Path Traversal dans la publication statique
- **Problème source** : SEC-002.
- **Objectif technique** : Empêcher l'écriture de fichiers arbitraires hors du répertoire cible du projet.
- **Fichiers concernés** : `src/Service/PublishedSiteStorage.php`.
- **Modifications à réaliser** :
  1. Dans `publish()`, normaliser le chemin cible via `realpath()` ou vérification canonique.
  2. Interdire formellement les caractères `..` et vérifier que le chemin absolu calculé débute strictement par `$projectDir . DIRECTORY_SEPARATOR`.
  3. Lever une `\InvalidArgumentException` immédiate en cas de détection d'anomalie.
- **Prérequis** : Aucun.
- **Risques de régression** : Aucun sur les flux normaux.
- **Tests de validation** : Créer un test unitaire `PublishedSiteStorageTest` injectant `../../public/evil.php` et constatant le refus.
- **Critères d'acceptation** : Impossible d'écrire un fichier en dehors de `$projectDir`.
- **Priorité** : P0 | **Effort estimé** : 3h.

#### [FIX-005] Verrouillage de l'annuaire d'utilisateurs CMS et protection PII
- **Problème source** : SEC-003.
- **Objectif technique** : Interdire tout accès anonyme aux comptes utilisateurs, rôles et permissions.
- **Fichiers concernés** : `src/Controller/UserManagement/UserController.php`, `RoleController.php`, `PermissionController.php`, `config/packages/security.yaml`.
- **Modifications à réaliser** :
  1. Ajouter `#[IsGranted('ROLE_USER')]` sur les trois classes de contrôleurs.
  2. Ajouter `#[IsGranted('users.view')]` sur les méthodes `index` et `show`.
  3. Dans `security.yaml`, déclarer explicitement :
     ```yaml
     - { path: ^/api/users, roles: ROLE_USER }
     - { path: ^/api/roles, roles: ROLE_USER }
     - { path: ^/api/permissions, roles: ROLE_USER }
     ```
- **Prérequis** : Aucun.
- **Tests de validation** : `curl -i http://localhost/api/users` sans token doit renvoyer HTTP 401.
- **Critères d'acceptation** : Aucune donnée utilisateur n'est retournée sans authentification valide.
- **Priorité** : P0 | **Effort estimé** : 2h.

#### [FIX-006] Contrôle d'accès et isolation tenant sur le serveur MCP
- **Problème source** : SEC-004.
- **Objectif technique** : Restreindre l'exécution des outils MCP aux utilisateurs/tokens dument autorisés.
- **Fichiers concernés** : `src/Controller/McpController.php`, `src/Mcp/JamboApiMcpServer.php`.
- **Modifications à réaliser** :
  1. Dans `McpController::handleProject()`, exiger un token d'authentification valide (PAT ou ApiToken).
  2. Vérifier l'accès au projet via `ProjectVoter::VIEW`.
  3. Dans `JamboApiMcpServer::registerUserTools()`, vérifier dans le callable que le contexte contient une habilitation sur le projet avant d'exécuter `list_end_users`.
  4. Restreindre `list_projects` aux seuls projets auxquels l'utilisateur authentifié est rattaché.
- **Prérequis** : FIX-007.
- **Tests de validation** : Requête anonyme sur `/api/projects/{uuid}/mcp` rejetée avec 401.
- **Critères d'acceptation** : Zéro fuite cross-tenant via les outils MCP.
- **Priorité** : P0 | **Effort estimé** : 4h.

#### [FIX-007] Harmonisation des paramètres de route dans le garde-fou multi-tenant
- **Problème source** : SEC-005.
- **Objectif technique** : Assurer que le subscriber `EnsureProjectMemberSubscriber` s'exécute sur toutes les routes de projet.
- **Fichiers concernés** : `src/EventSubscriber/EnsureProjectMemberSubscriber.php`.
- **Modifications à réaliser** :
  1. Ligne 37, remplacer l'extraction par :
     `$projectUuid = $request->attributes->get('projectUuid') ?? $request->attributes->get('uuid');`
  2. S'assurer que le filtrage prend en compte indifféremment les deux dénominations.
- **Prérequis** : Aucun.
- **Tests de validation** : Appeler `/api/projects/{uuid}/graphql` avec un utilisateur étranger au projet -> doit renvoyer 403 Forbidden.
- **Critères d'acceptation** : Aucune route projet ne peut être requêtée sans contrôle d'accès effectif.
- **Priorité** : P1 | **Effort estimé** : 2h.

#### [FIX-008] Déblocage de l'assistant d'installation `/install`
- **Problème source** : OPS-004.
- **Objectif technique** : Permettre l'accès à l'assistant d'installation sur une instance vierge tout en le protégeant post-installation.
- **Fichiers concernés** : `config/packages/security.yaml`, `src/Controller/InstallController.php`.
- **Modifications à réaliser** :
  1. Ajouter `- { path: ^/install, roles: PUBLIC_ACCESS }` dans `security.yaml` avant la règle générique.
  2. Dans `InstallController`, vérifier dès l'entrée : si `$this->userRepository->count([]) > 0`, renvoyer une erreur 403 avec message « L'application est déjà installée ».
- **Prérequis** : Aucun.
- **Tests de validation** : Accès à `/install` sur base vide -> formulaire 200 OK. Création de l'admin -> second accès -> 403 Forbidden.
- **Critères d'acceptation** : Bootstrap initial possible et sécurisé.
- **Priorité** : P1 | **Effort estimé** : 2h.

---

### PHASE 2 — Corrections Fondamentales & Réparations P1

#### [FIX-009] Correction des droits de mutation dans l'Admin API (Collections & Projets)
- **Problème source** : SEC-006.
- **Objectif technique** : Corriger l'élévation de privilège permettant à un lecteur de supprimer des projets et collections.
- **Fichiers concernés** : `src/Controller/AdminApi/CollectionController.php`, `src/Controller/AdminApi/ProjectController.php`.
- **Modifications à réaliser** :
  1. Dans `CollectionController`, remplacer `ProjectVoter::VIEW` par `ProjectVoter::MANAGE` sur `create`, `update`, `delete`.
  2. Dans `ProjectController`, remplacer `ProjectVoter::VIEW` par `ProjectVoter::MANAGE` sur `update` et `delete`.
- **Prérequis** : `ProjectVoter`.
- **Tests de validation** : Test fonctionnel vérifiant qu'un membre au rôle « Viewer » ne peut pas supprimer une collection.
- **Critères d'acceptation** : Seuls les gestionnaires légitimes peuvent modifier ou supprimer ces entités.
- **Priorité** : P1 | **Effort estimé** : 2h.

#### [FIX-010] Sécurisation anti-SSRF et timeout sur l'ingestion d'images IA
- **Problème source** : SEC-007.
- **Objectif technique** : Remplacer `file_get_contents` par un téléchargement HTTP sécurisé et restreint.
- **Fichiers concernés** : `src/Controller/StudioController.php`.
- **Modifications à réaliser** :
  1. Remplacer les appels `@file_get_contents($imageUrl)` par un helper sécurisé utilisant Symfony `HttpClientInterface`.
  2. Valider l'URL : protocole `https://` obligatoire, résolution DNS avec rejet des plages d'adresses privées (RFC 1918, 127.0.0.0/8, 169.254.0.0/16).
  3. Définir un timeout strict de 10 secondes et une limite de taille maximale (ex. 10 Mo).
- **Prérequis** : FIX-007.
- **Tests de validation** : Test unitaire tentant de charger `http://127.0.0.1:8080` bloqué immédiatement.
- **Critères d'acceptation** : Aucun appel réseau sortant non assaini.
- **Priorité** : P1 | **Effort estimé** : 3h.

#### [FIX-011] Gestion sécurisée des utilisateurs nuls dans les paramètres de compte
- **Problème source** : BUG-001.
- **Objectif technique** : Éliminer les erreurs 500 fatales sur les requêtes non authentifiées.
- **Fichiers concernés** : `src/Controller/Settings/PasswordController.php`, `src/Controller/Settings/ProfileController.php`.
- **Modifications à réaliser** :
  1. Ajouter `#[IsGranted('ROLE_USER')]` sur les contrôleurs.
  2. Vérifier explicitement : `$user = $this->getUser(); if (!$user instanceof User) { return $this->json(['error' => 'Non authentifié'], 401); }`.
- **Prérequis** : Aucun.
- **Tests de validation** : Appel non authentifié sur `PUT /api/settings/password` renvoie 401 JSON propre.
- **Critères d'acceptation** : Zéro exception 500 sur appel anonyme.
- **Priorité** : P2 | **Effort estimé** : 1h.

#### [FIX-012] Déport asynchrone des automatisations via Symfony Messenger
- **Problème source** : BUG-002.
- **Objectif technique** : Rendre l'exécution des automatisations non-bloquante pour les requêtes HTTP.
- **Fichiers concernés** : `src/EventSubscriber/AutomationTriggerSubscriber.php`.
- **Modifications à réaliser** :
  1. Injecter `MessageBusInterface $bus` dans `AutomationTriggerSubscriber`.
  2. Dans `executeAutomation()`, créer un `AutomationRun` en statut `pending`, puis dispatcher `new ExecuteAutomationMessage($automation->id, $payload, $run->id)`.
  3. Laisser `ExecuteAutomationMessageHandler` prendre en charge l'exécution dans le worker d'arrière-plan.
- **Prérequis** : FIX-002, configuration de transport Messenger.
- **Tests de validation** : Création d'une entrée avec webhook distant : la requête d'entrée répond en < 50ms et le webhook est expédié par le worker.
- **Critères d'acceptation** : Zéro blocage réseau synchrone dans la requête HTTP client.
- **Priorité** : P1 | **Effort estimé** : 4h.

---

### PHASE 3 — Implémentation Réelle des Fonctionnalités Simulées

#### [FIX-013] Remplacement du mock de streaming IA par une véritable intégration LLM
- **Problème source** : SIM-001.
- **Objectif technique** : Fournir un véritable flux SSE connecté aux providers LLM configurés.
- **Fichiers concernés** : `src/Controller/Admin/ContentAiStreamController.php`, `src/Service/AiContentService.php`.
- **Modifications à réaliser** :
  1. Implémenter une méthode `streamPrompt(string $prompt, callable $onChunk): void` dans `AiContentService` exploitant l'option de streaming de `HttpClientInterface` vers OpenAI / Gemini.
  2. Dans `ContentAiStreamController`, transmettre chaque fragment reçu au client HTTP via le format SSE standard (`data: {"chunk": "..."}`).
  3. Gérer proprement les erreurs du provider distant sans couper brutalement la connexion.
- **Prérequis** : FIX-010.
- **Tests de validation** : Test d'intégration avec client HTTP mocké simulant l'arrivée progressive de tokens.
- **Critères d'acceptation** : Le flux SSE émet les vrais tokens générés par le modèle distant.
- **Priorité** : P1 | **Effort estimé** : 6h.

#### [FIX-014] Implémentation de l'endpoint `/authorize` pour le provider OAuth2
- **Problème source** : SIM-003.
- **Objectif technique** : Rendre le flux OAuth2 `authorization_code` pleinement opérationnel de bout en bout.
- **Fichiers concernés** : `src/Controller/ProjectOAuthController.php`, `src/Service/ProjectOAuthService.php`, `templates/oauth/authorize.html.twig`.
- **Modifications à réaliser** :
  1. Créer la route `GET|POST /api/v1/projects/{projectUuid}/oauth/authorize`.
  2. Valider le `client_id`, `redirect_uri`, `response_type=code`, `code_challenge` (PKCE).
  3. Si l'utilisateur final n'est pas connecté, afficher le formulaire d'authentification du projet.
  4. Après accord/authentification, appeler `ProjectOAuthService::createAuthorizationCode()` et rediriger vers `redirect_uri?code=...&state=...`.
- **Prérequis** : `EndUserRepository`.
- **Tests de validation** : Test fonctionnel WebTestCase simulant le parcours complet d'autorisation et d'échange de code contre jeton.
- **Critères d'acceptation** : Un client tiers standard peut mener à terme une connexion OIDC/OAuth2.
- **Priorité** : P1 | **Effort estimé** : 8h.

#### [FIX-015] Activation et injection de la Sandbox Twig hermétique
- **Problème source** : SIM-004.
- **Objectif technique** : Activer réellement `NativeTwigSecurityPolicy` sur les rendus de templates dynamiques.
- **Fichiers concernés** : `src/Twig/NativeTwigSecurityPolicy.php`, `config/packages/twig.yaml`, `config/services.yaml`.
- **Modifications à réaliser** :
  1. Déclarer `NativeTwigSecurityPolicy` comme service Symfony.
  2. Enregistrer l'extension `Twig\Extension\SandboxExtension` configurée avec cette politique.
  3. Créer un service dédié `SafeTemplateRenderer` pour encapsuler le rendu de tout contenu éditable par les utilisateurs.
- **Prérequis** : Aucun.
- **Tests de validation** : Test unitaire vérifiant qu'un appel `{{ app.user.password }}` dans un template dynamique lève une `SecurityError`.
- **Critères d'acceptation** : Aucune évaluation dynamique de template sans bac à sable hermétique.
- **Priorité** : P2 | **Effort estimé** : 3h.

#### [FIX-016] Consolidation de l'architecture des répéteurs et élimination du code orphelin
- **Problème source** : SIM-002, Décision D-01.
- **Objectif technique** : Aligner le modèle de données, les formateurs d'API et la documentation.
- **Fichiers concernés** : `src/Service/EavDataFormatterService.php`, `src/Entity/ContentFieldGroup.php`, `README.md`, `ROADMAP.md`.
- **Modifications à réaliser** :
  1. Selon l'arbitrage D-01, durcir la validation de schéma sur `ContentFieldValue::jsonValue` pour les répéteurs.
  2. Retirer ou brancher définitivement l'entité `ContentFieldGroup` pour ne laisser aucun code mort.
  3. Mettre à jour `README.md` et `ROADMAP.md` pour refléter l'implémentation effective.
- **Prérequis** : Décision D-01.
- **Tests de validation** : CRUD complet sur une collection contenant un répéteur imbriqué.
- **Critères d'acceptation** : Zéro incohérence entre documentation, modèle de données et API.
- **Priorité** : P2 | **Effort estimé** : 6h.

---

### PHASE 4 — Opérabilité, Infrastructure, Stockage & CI/CD

#### [FIX-017] Correction de la configuration Nginx CDN et fiabilisation Docker
- **Problème source** : OPS-003.
- **Objectif technique** : Assurer le fonctionnement dynamique du CDN et la réussite du build conteneurisé.
- **Fichiers concernés** : `docker/nginx.conf`, `Dockerfile`.
- **Modifications à réaliser** :
  1. Dans `docker/nginx.conf`, corriger la directive `/cdn/media/` pour tenter le fichier local puis basculer vers PHP :
     `try_files $uri /index.php$is_args$args;`
  2. Dans `Dockerfile`, organiser un build multi-étapes séparant le build des assets Node.js de l'image finale PHP.
  3. Définir des variables d'environnement factices pour que `cache:warmup` n'exige pas de connexion MySQL active au build.
- **Prérequis** : FIX-002.
- **Tests de validation** : `docker build -t jamboapi .` réussit et le conteneur démarre sur le port 8080.
- **Critères d'acceptation** : Conteneur de production autonome et fonctionnel.
- **Priorité** : P1 | **Effort estimé** : 4h.

#### [FIX-018] Abstraction du stockage des médias pour compatibilité S3 / R2 dans le CDN
- **Problème source** : OPS-006.
- **Objectif technique** : Permettre au service de transformation d'image d'opérer sur des fichiers distants S3/R2.
- **Fichiers concernés** : `src/Controller/ImageTransformController.php`, `src/Service/ImageTransformService.php`.
- **Modifications à réaliser** :
  1. Dans `ImageTransformController`, si le fichier n'existe pas localement, utiliser `StorageManager` (Flysystem) pour le rapatrier temporairement dans un répertoire de cache local.
  2. Appliquer la transformation GD sur le fichier local mis en cache.
- **Prérequis** : `StorageManager`.
- **Tests de validation** : Requête de redimensionnement d'une image dont la source est déclarée sur S3.
- **Critères d'acceptation** : Le CDN répond avec succès quel que soit le backend de stockage configuré.
- **Priorité** : P2 | **Effort estimé** : 4h.

#### [FIX-019] Restauration du pipeline CI/CD automatisé (GitHub Actions)
- **Problème source** : SIM-005.
- **Objectif technique** : Automatiser le contrôle qualité et la suite de tests à chaque commit.
- **Fichiers concernés** : `.github/workflows/ci.yml`.
- **Modifications à réaliser** :
  1. Créer `.github/workflows/ci.yml` avec matrice PHP 8.4, service MySQL et Node.js.
  2. Exécuter les étapes : `composer validate`, `composer install`, `npm ci && npm run build`, `doctrine:migrations:migrate`, `php bin/phpunit`, `npm run test:e2e`.
- **Prérequis** : FIX-002, FIX-003.
- **Tests de validation** : Validation locale via `act` ou sur branche de test distante.
- **Critères d'acceptation** : Pipeline vert et bloquant pour tout merge sur `main`.
- **Priorité** : P2 | **Effort estimé** : 3h.

#### [FIX-020] Remplacement du script de déploiement par un protocole sécurisé et atomique
- **Problème source** : OPS-005.
- **Objectif technique** : Déployer en production de façon reproductible, avec migrations, compilation et purge de cache.
- **Fichiers concernés** : `deploy.py`, `scripts/deploy.sh`.
- **Modifications à réaliser** :
  1. Structurer un script de déploiement complet :
     - `composer install --no-dev --optimize-autoloader`
     - `npm ci && npm run build`
     - `php bin/console doctrine:migrations:migrate --no-interaction`
     - `php bin/console cache:clear && php bin/console cache:warmup`
     - `sudo systemctl reload php8.4-fpm`
  2. Mettre en place un mécanisme de release par liens symboliques (type Deployer ou GitHub Actions SSH release) pour garantir le zéro downtime.
- **Prérequis** : Accès SSH serveur.
- **Tests de validation** : Test à blanc sur environnement de staging.
- **Critères d'acceptation** : Déploiement sans interruption de service ni résidu de cache obsolète.
- **Priorité** : P1 | **Effort estimé** : 5h.

---

### PHASE 5 — Validation, Tests de Non-Régression & Préparation Prod

#### [FIX-021] Enrichissement de la couverture de tests sur les failles corrigées
- **Objectif technique** : Garantir la non-régression sur tous les constats P0/P1.
- **Fichiers concernés** : `tests/Security/*Test.php`, `tests/Controller/*Test.php`.
- **Modifications à réaliser** :
  1. Ajouter les tests de régression spécifiques : traversal dans `PublishedSiteStorageTest`, isolation MCP dans `McpSecurityTest`, accès non authentifié sur `UserManagementSecurityTest`.
  2. Exécuter la suite complète `php bin/phpunit`.
- **Prérequis** : Toutes les tâches précédentes.
- **Tests de validation** : 100% des tests unitaires et d'intégration réussis.
- **Critères d'acceptation** : Aucune régression détectée.
- **Priorité** : P1 | **Effort estimé** : 6h.

#### [FIX-022] Validation de la checklist de sortie et recette finale
- **Objectif technique** : Valider objectivement chaque critère de passage en production.
- **Fichiers concernés** : `CHECKLIST_PRODUCTION.md`.
- **Modifications à réaliser** :
  1. Vérification formelle point par point de la checklist (Section J).
  2. Signature du procès-verbal technique d'audit et de conformité.
- **Prérequis** : FIX-001 à FIX-021 terminés.
- **Critères d'acceptation** : Zéro anomalie P0/P1 ouverte.
- **Priorité** : P1 | **Effort estimé** : 4h.

---

## H. MATRICE DE TRAÇABILITÉ

| Anomalie ID | Cause Racine | Tâche de Correction | Fichiers Cibles | Tests Associés | Critères d'Acceptation |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SEC-001** | Suivi git de `.env` avec mots de passe réels | **FIX-001** | `.env`, `.env.example`, `.gitignore` | `git status` / inspection commits | Aucun secret dans git ; `.env` ignoré. |
| **SEC-002** | Absence de contrôle anti-traversal dans `publish()` | **FIX-004** | `src/Service/PublishedSiteStorage.php` | `PublishedSiteStorageTest` | Rejet strict de `../` et RCE impossible. |
| **SEC-003** | Règle `PUBLIC_ACCESS` sur `^/api` sans `#[IsGranted]` | **FIX-005** | `UserController.php`, `security.yaml` | `UserManagementSecurityTest` | `GET /api/users` retourne 401 anonyme. |
| **SEC-004** | Route MCP sans authentification ni voter tenant | **FIX-006** | `McpController.php`, `JamboApiMcpServer.php` | `McpSecurityTest` | Rejet 401/403 et isolation cross-tenant. |
| **SEC-005** | Attribut `{uuid}` non résolu par le subscriber | **FIX-007** | `EnsureProjectMemberSubscriber.php` | `TenantIsolationTest` | 403 sur routes `{uuid}` pour non-membres. |
| **SEC-006** | Utilisation de `VIEW` au lieu de `MANAGE` | **FIX-009** | `CollectionController.php`, `ProjectController.php` | `AdminApiPrivilegeTest` | Un « Viewer » ne peut ni modifier ni supprimer. |
| **SEC-007** | `@file_get_contents` synchrone et non filtré | **FIX-010** | `src/Controller/StudioController.php` | `SsrfProtectionTest` | IP privées et protocoles non-https rejetés. |
| **SIM-001** | Mock cosmétique avec `array_chunk` et `usleep` | **FIX-013** | `ContentAiStreamController.php` | `AiStreamControllerTest` | Vrai flux SSE branché aux providers LLM. |
| **SIM-002** | Code mort `ContentFieldGroup` et doc en avance | **FIX-016** | `EavDataFormatterService.php`, docs | `EavFormatterTest` | Répéteurs validés, schéma DB cohérent. |
| **SIM-003** | Absence d'endpoint `/authorize` interactif | **FIX-014** | `ProjectOAuthController.php` | `OAuth2AuthorizationFlowTest` | Flux RFC 6749 PKCE complet opérationnel. |
| **SIM-004** | Classe de sécurité non déclarée dans le conteneur | **FIX-015** | `NativeTwigSecurityPolicy.php`, `twig.yaml` | `NativeTwigSecurityPolicyTest` | Évaluation Twig protégée par sandbox active. |
| **SIM-005** | Dossier `.github/` supprimé | **FIX-019** | `.github/workflows/ci.yml` | Exécution GitHub Actions | Pipeline CI vert sur chaque push/PR. |
| **OPS-001** | `spomky-labs/otphp` manquant dans lockfile | **FIX-002** | `composer.json`, `composer.lock` | `composer validate --strict` | `composer install` 100% automatisable. |
| **OPS-002** | Double `CREATE TABLE` dans les migrations | **FIX-003** | `Version20260629084616.php` | `doctrine:migrations:migrate` | Migrations linéaires sans erreur sur base neuve. |
| **OPS-003** | `try_files` Nginx figé et build Composer cassé | **FIX-017** | `docker/nginx.conf`, `Dockerfile` | `docker build` & curl CDN | Conteneur compilable et CDN dynamique. |
| **OPS-004** | `/install` bloqué par `^/` ROLE_USER | **FIX-008** | `security.yaml`, `InstallController.php` | `InstallControllerTest` | Install accessible à froid, verrouillée ensuite. |
| **OPS-005** | Simple `git pull` sans cache/migration | **FIX-020** | `deploy.py`, scripts de déploiement | Test de staging | Déploiement complet sans régression de cache. |
| **OPS-006** | Accès disque direct incompatible S3 | **FIX-018** | `ImageTransformController.php` | `ImageTransformStorageTest` | CDN fonctionnel sur fichiers distants S3/R2. |
| **BUG-001** | Appel `$user->...` sur user null | **FIX-011** | `PasswordController.php`, `ProfileController.php` | `SettingsSecurityTest` | Erreur 401 propre au lieu de 500 unhandled. |
| **BUG-002** | Exécution synchrone d'automatisations | **FIX-012** | `AutomationTriggerSubscriber.php` | `AutomationAsyncTest` | Traitement déporté dans worker Messenger. |

---

## I. PLAN DE TESTS ET STRATÉGIE DE NON-RÉGRESSION

### 1. Organisation de la suite de tests
Les tests doivent être exécutés via la commande standard :
```bash
php bin/phpunit --testdox
```

### 2. Scénarios critiques obligatoires
- **Sécurité & Isolation Multi-Tenant** :
  - *Scénario SEC-T1* : Requête non authentifiée sur chaque endpoint de `UserController`, `RoleController`, `PermissionController` -> Résultat attendu : 401 Unauthorized.
  - *Scénario SEC-T2* : Requête GET/POST sur `/api/projects/{uuid}/*` avec un token appartenant à un autre projet -> Résultat attendu : 403 Forbidden.
  - *Scénario SEC-T3* : Appel à l'outil MCP `list_end_users` sans token de projet -> Résultat attendu : 401/403.
  - *Scénario SEC-T4* : Tentative d'écriture dans `PublishedSiteStorage` avec chemin `../../config/secrets.yaml` -> Résultat attendu : `InvalidArgumentException`.
- **Flux Métier & Intégrations Réelles** :
  - *Scénario BIZ-T1* : Parcours OAuth2 complet : requête `/authorize` -> soumission identifiants -> obtention code -> échange sur `/token` -> appel `/userinfo` -> Résultat attendu : profil EndUser retourné conforme RFC 6749.
  - *Scénario BIZ-T2* : Appel streaming IA sur `POST /admin/api/content-ai/stream` avec mock du LLM -> Résultat attendu : flux SSE avec données dynamiques distinctes de l'entrée.
  - *Scénario BIZ-T3* : Création d'entrée avec automatisation -> Résultat attendu : création immédiate < 50ms et dispatch d'un message dans la file Messenger.
- **Infrastructure & Exploitation** :
  - *Scénario OPS-T1* : Base MySQL vierge -> `php bin/console doctrine:migrations:migrate --no-interaction` -> Résultat attendu : succès total sans collision.
  - *Scénario OPS-T2* : Requête `/cdn/media/{uuid}?w=100&h=100` via Nginx pour une image non encore mise en cache -> Résultat attendu : Nginx délègue à PHP, l'image est générée et retournée avec HTTP 200.

---

## J. CRITÈRES OBJECTIFS DE PRÉPARATION À LA PRODUCTION

Avant tout déploiement en environnement de production, la checklist suivante doit être validée sans exception :

- [ ] **Secrets & Identifiants** : Aucun mot de passe réel n'apparaît dans git ; `.env` est ignoré ; les identifiants compromis ont été renouvelés chez les hébergeurs.
- [ ] **Zéro anomalie P0 ouverte** : Toutes les anomalies P0 (SEC-001, SEC-002, SEC-003, SEC-004, OPS-001, OPS-002) sont corrigées et vérifiées par tests.
- [ ] **Traitement des anomalies P1** : Les défauts d'isolation tenant, les privilèges de suppression et les implémentations simulées sont résolus ou formellement documentés.
- [ ] **Build conteneur & Dépendances** : `composer validate --strict` retourne 0 ; l'image Docker se compile proprement sans avertissement.
- [ ] **Migrations & Schéma de Données** : Les migrations s'exécutent de façon linéaire et idempotente sur une base neuve.
- [ ] **Assistant d'installation** : Accessible à l'initialisation et hermétiquement verrouillé après création du premier administrateur.
- [ ] **Tests Automatisés & Non-Régression** : 100% de la suite PHPUnit réussie ; pipeline GitHub Actions actif et validé.
- [ ] **Pipeline de Déploiement** : Procédure de déploiement automatisée incluant migrations, compilation des assets et warmup du cache sans interruption de service.
- [ ] **CDN & Médias** : Redimensionnement d'images fonctionnel en environnement conteneurisé et compatible stockage S3.
- [ ] **Documentation à jour** : Aucune fonctionnalité fictive ou simulée n'est présentée comme opérationnelle dans `README.md` ou `ROADMAP.md`.

---

## K. RISQUES RÉSIDUELS ET PROCHAINES ÉTAPES

### 1. Risques résiduels identifiés
- **Dette technique sur le modèle EAV** : Le volume important de champs EAV peut engendrer des lenteurs sur les collections dépassant 50 000 entrées sans indexation dédiée ou passage au partitionnement JSON.
- **Dépendance aux quotas LLM externes** : L'intégration d'un streaming réel vers OpenAI/Gemini introduit une dépendance aux temps de latence et aux politiques de limitation de débit des tiers.
- **Configuration réseau des hébergements mutualisés** : En cas de déploiement hors conteneur (ex. cPanel / o2switch), les droits de redémarrage de PHP-FPM doivent être configurés via sudoers ou scripts dédiés.

### 2. Prochaines étapes immédiates (Ordre d'exécution recommandé)
1. **Étape 1 (Immédiate)** : Exécuter **FIX-001** (révocation des mots de passe exposés et assainissement de git) et **FIX-002** (resynchronisation `composer.lock`).
2. **Étape 2** : Corriger le blocage des migrations avec **FIX-003** pour disposer d'un environnement de base de données local sain.
3. **Étape 3** : Déployer en priorité absolue les correctifs de sécurité **FIX-004** (Path Traversal), **FIX-005** (Fuite PII) et **FIX-006** (Failles MCP).
4. **Étape 4** : Réaligner le garde-fou multi-tenant avec **FIX-007** et débloquer l'installation avec **FIX-008**.
5. **Étape 5** : Mettre en œuvre le reste des tâches selon le jalonnement de la Phase 2 à la Phase 5.
