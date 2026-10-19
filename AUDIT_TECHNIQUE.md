# RAPPORT D'AUDIT TECHNIQUE APPROFONDI — JAMBO API
**Dépôt audité** : [https://github.com/jambostack/jambo-api](https://github.com/jambostack/jambo-api)  
**Emplacement local** : `c:\laragon\www\jamboapicms`  
**Date d'évaluation** : 19 Octobre 2026  
**Auditeur** : Ingénieur Logiciel Senior & Spécialiste Sécurité Applicative  
**Référence du document** : AUDIT-TECH-JAMBO-2026-001  

---

## 1. CONTEXTE ET CADRE D'INGÉNIERIE

### 1.1. Nature et vocation de l'application
Jambo API se présente comme un CMS Headless d'entreprise multi-tenant basé sur **Symfony 7.2** et **PHP 8.4**. L'application a pour objectif de proposer :
- La gestion de contenus via un modèle hybride EAV (Entity-Attribute-Value) et JSON schématisé.
- La gouvernance multi-tenant par projets isolés avec gestion des permissions et tokens d'API.
- Un moteur d'automatisation visuel par graphes de flux (Flow Engine).
- Des fonctionnalités d'assistance par intelligence artificielle (génération de schémas, assistants rédactionnels, streaming SSE).
- Un protocole d'intégration d'agents IA (MCP — Model Context Protocol).
- Une couche CDN de transformation et diffusion d'images à la volée.
- Un fournisseur d'identité OAuth2 / OIDC pour les applications clientes.

### 1.2. Méthodologie d'audit statique et dynamique
L'audit a été mené selon les principes stricts d'ingénierie logicielle :
- **Audit de code source exhaustif (White-Box)** : Examen des 42 contrôleurs, 34 entités Doctrine, services métier, abonnements d'événements, configuration du pare-feu et des fichiers Docker/déploiement.
- **Vérification de cohérence des dépendances et de l'environnement** : Examen de `composer.json`, `composer.lock`, `package.json`, inspection du runtime local (PHP 8.4.12, Composer 2.10.2).
- **Traçabilité de l'historique git** : Analyse des commits récents de release (`v1.20.0` à `v1.26.0`) pour vérifier la réalité des fonctionnalités annoncées.
- **Principe d'intégrité** : Aucune hypothèse non vérifiée n'est qualifiée de fait ; chaque anomalie majeure est appuyée par son fichier et numéro de ligne exacts.

---

## 2. AUDIT DE SÉCURITÉ APPLICATIVE & CONTRÔLE D'ACCÈS

### 2.1. Vulnérabilités d'authentification et gestion des accès

#### [VULN-01] Exposition publique de l'annuaire des utilisateurs et données personnelles (PII)
- **Localisation** : `config/packages/security.yaml:73`, `src/Controller/UserManagement/UserController.php:26,115`, `RoleController.php:26`, `PermissionController.php:18`.
- **Mécanisme de défaillance** :
  La ligne 73 de `security.yaml` définit :
  ```yaml
  - { path: ^/api, roles: PUBLIC_ACCESS }
  ```
  Le pare-feu Symfony n'exige donc aucune authentification préalable pour toute URL débutant par `/api`. Les contrôleurs du sous-dossier `UserManagement` ne définissent aucun attribut `#[IsGranted]` au niveau de la classe ni sur les méthodes de lecture `index()` et `show()`.
- **Preuve technique** :
  Dans `UserController.php` :
  ```php
  26: #[Route('', name: 'index', methods: ['GET'])]
  27: public function index(Request $request): JsonResponse
  28: {
  29:     $search    = (string) $request->query->get('search', '');
  ...
  41:     $qb = $this->userRepository->createQueryBuilder('u')
  42:         ->leftJoin('u.userRoles', 'r')
  43:         ->addSelect('r');
  ...
  75:     return $this->json([
  76:         'data'  => array_map([$this, 'serialize'], $users),
  ...
  ```
  Un appel HTTP non authentifié `GET /api/users` retourne l'ensemble des comptes du CMS, incluant IDs, noms complets, adresses email et rôles assignés.
- **Impact** : Violation critique du RGPD, énumération complète des comptes administrateurs pour attaques ciblées.
- **Gravité** : **CRITIQUE (CVSS 8.6)** | **Priorité** : **P0**.

---

#### [VULN-02] Contournement systématique du contrôle d'accès multi-tenant (Tenant Guard Bypass)
- **Localisation** : `src/EventSubscriber/EnsureProjectMemberSubscriber.php:36-41`.
- **Contrôleurs vulnérables** :
  - `src/Controller/StudioController.php` (10 routes de studio déclarant `{uuid}`)
  - `src/Controller/GraphQLController.php:31` (`/api/projects/{uuid}/graphql`)
  - `src/Controller/SearchController.php:20,52` (`/api/projects/{uuid}/search`, `/reindex`)
  - `src/Controller/AiController.php:23,52,73,92,110,133` (6 routes d'IA)
  - `src/Controller/McpController.php:55` (`/api/projects/{uuid}/mcp`)
  - `src/Controller/Api/EndUserAdminController.php:22` (`/api/projects/{uuid}/end-users`)
- **Mécanisme de défaillance** :
  Le subscriber `EnsureProjectMemberSubscriber` intercepte l'événement `KernelEvents::REQUEST` (priorité 5) pour s'assurer que l'utilisateur ou le token possède les droits sur le projet courant. Or, il extrait exclusivement le paramètre nommé `'projectUuid'` :
  ```php
  36: $request     = $event->getRequest();
  37: $projectUuid = $request->attributes->get('projectUuid');
  38: 
  39: if ($projectUuid === null) {
  40:     return;
  41: }
  ```
  Sur toutes les routes déclarant `{uuid}` au lieu de `{projectUuid}`, `$request->attributes->get('projectUuid')` est strictement `null`. Le subscriber termine immédiatement son exécution à la ligne 40 sans effectuer la moindre vérification d'appartenance. Comme `/api` est en `PUBLIC_ACCESS`, les requêtes parviennent au contrôleur sans contrôle d'accès de projet.
- **Impact** : Rupture d'isolation multi-tenant.
- **Gravité** : **ÉLEVÉE (CVSS 8.2)** | **Priorité** : **P1**.

---

#### [VULN-03] Fuite de données cross-tenant et accès anonyme au serveur MCP
- **Localisation** : `src/Controller/McpController.php:55-72`, `src/Mcp/JamboApiMcpServer.php:68-87, 594-625`.
- **Mécanisme de défaillance** :
  La route `/api/projects/{uuid}/mcp` ne dispose d'aucun contrôle d'accès dans `McpController.php`. De plus, elle utilise `{uuid}`, contournant le subscriber en vertu de VULN-02.
  Lors de la réception d'une requête JSON-RPC, `handleProject()` appelle `$this->mcpServer->handleRequest($body, $context)`.
  Dans `JamboApiMcpServer.php`, l'outil `list_end_users` exécute :
  ```php
  603: function (array $args, array $ctx): array {
  604:     $project = $this->findProject($args['project_uuid']);
  605:     if (!$project) throw new McpException('Projet introuvable', 404);
  606: 
  607:     $qb = $this->em->getRepository(EndUser::class)->createQueryBuilder('u')
  608:         ->where('u.project = :project')->setParameter('project', $project)...
  ```
  Aucun contrôle n'est opéré sur `$ctx['user']` ni sur un jeton d'autorisation. N'importe quel client externe non authentifié peut appeler cet endpoint et récupérer la base complète des utilisateurs finaux (emails, champs personnalisés, statuts, dates).
  De même, l'endpoint global `/mcp` expose l'outil `list_projects` qui retourne l'ensemble des projets de la plateforme sans filtrer par tenant.
- **Impact** : Exfiltration massive de données clients et non-respect de l'étanchéité des projets.
- **Gravité** : **CRITIQUE (CVSS 9.1)** | **Priorité** : **P0**.

---

#### [VULN-04] Élévation de privilèges dans l'Admin API (Suppression non autorisée)
- **Localisation** : `src/Controller/AdminApi/CollectionController.php:67,84,101`, `src/Controller/AdminApi/ProjectController.php:63,76`.
- **Preuve technique** :
  ```php
  // CollectionController.php
  67:  $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project); // create
  84:  $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project); // update
  101: $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project); // delete
  
  // ProjectController.php
  63:  $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project); // update
  76:  $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project); // delete
  ```
- **Mécanisme de défaillance** :
  Le `ProjectVoter` implémente les attributs `VIEW`, `EDIT`, `MANAGE`. Dans les méthodes critiques de destruction (`delete`), le contrôleur vérifie uniquement `ProjectVoter::VIEW`. Tout utilisateur disposant d'un compte avec simple droit de consultation sur le projet et ayant émis un jeton PAT avec le scope requis (`schema:write` ou `projects:write`) est autorisé par Symfony à supprimer définitivement le projet ou ses collections.
- **Impact** : Perte de données majeure et altération non autorisée de la structure de la base par des profils en lecture seule.
- **Gravité** : **ÉLEVÉE (CVSS 7.9)** | **Priorité** : **P1**.

---

### 2.2. Intégrité des systèmes de fichiers et injection de code

#### [VULN-05] Écriture de fichier arbitraire et RCE via Directory Traversal dans la publication statique
- **Localisation** : `src/Service/PublishedSiteStorage.php:29-36`.
- **Preuve technique** :
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
- **Mécanisme de défaillance** :
  Dans la méthode `publish()`, la clé de tableau `$relativePath` fournie dans le payload de publication n'est filtrée que par un simple `ltrim($relativePath, '/')`. Si le chemin relatif contient des séquences `../`, telles que `../../public/webshell.php`, `dirname($filePath)` remonte l'arborescence et `file_put_contents` écrit le fichier directement dans la racine publique du serveur web.
  À titre de comparaison, la méthode `readFile()` (lignes 58-60) implémente expressément une vérification canonique :
  ```php
  if (!str_starts_with($realTargetPath, $realProjectDir . DIRECTORY_SEPARATOR) && $realTargetPath !== $realProjectDir) {
      return null;
  }
  ```
  Cette protection a été omise dans `publish()`.
- **Impact** : Exécution de code arbitraire à distance (RCE) avec les privilèges du processus web (PHP-FPM / Nginx).
- **Gravité** : **CRITIQUE (CVSS 9.8)** | **Priorité** : **P0**.

---

#### [VULN-06] Fuite de secrets de production dans le dépôt git
- **Localisation** : Fichier `.env` (lignes 11, 19, 27).
- **Preuve technique** :
  ```env
  DATABASE_URL="mysql://jprud67_jamboapi:***REDACTED_PASSWORD***@2.24.10.163:3306/jprud67_jamboapi?serverVersion=10.11.8-MariaDB&charset=utf8mb4"
  MAILER_DSN=smtp://noreply@jambostack.site:***REDACTED_PASSWORD***@smtp.hostinger.com:465
  MERCURE_JWT_SECRET="***REDACTED_JWT_SECRET***"
  ```
- **Mécanisme de défaillance** :
  Le fichier `.env` contenant les identifiants de connexion actifs d'un serveur MySQL distant chez OVHcloud (IP `2.24.10.163`) et du serveur SMTP Hostinger est versionné dans git.
- **Impact** : Accès non restreint direct à la base de données distante hébergeant les données de production et usurpation du serveur de messagerie pour du spam ou du phishing.
- **Gravité** : **CRITIQUE (CVSS 9.8)** | **Priorité** : **P0**.

---

#### [VULN-07] Vulnérabilité SSRF / LFI via téléchargement non sécurisé d'images IA
- **Localisation** : `src/Controller/StudioController.php:1402,1696`.
- **Preuve technique** :
  ```php
  1402: $imageData = @file_get_contents($imageUrl);
  ...
  1696: $imageData = @file_get_contents($imageUrl);
  ```
- **Mécanisme de défaillance** :
  L'application appelle la fonction PHP synchrone `@file_get_contents()` sur l'URL `$imageUrl`. Aucun filtre n'interdit l'utilisation de protocoles locaux (`file:///etc/passwd`) ni la connexion à des adresses IP locales de métadonnées de serveurs cloud (`http://169.254.169.254/latest/meta-data/`). De plus, en l'absence de timeout d'expiration explicite, un serveur distant malveillant maintenant la connexion ouverte peut épuiser les workers PHP-FPM (déni de service).
- **Impact** : Fuite de clés cloud AWS/GCP, lecture locale de fichiers ou déni de service serveur.
- **Gravité** : **ÉLEVÉE (CVSS 7.5)** | **Priorité** : **P1**.

---

## 3. AUDIT DES FONCTIONNALITÉS SIMULÉES, FICTIVES OU INCOMPLÈTES

### 3.1. [SIM-01] Assistant IA en streaming : Mock déguisé
- **Documentation officielle** : `README.md:148` et `ROADMAP.md` (v1.25) revendiquent :
  *« Assistant de complétion inline temps-réel via Server-Sent Events (SSE) alimenté par 10 modèles LLM (OpenAI, Anthropic, Gemini, Mistral, Groq, DeepSeek, Ollama...). »*
- **Réalité du code source** : `src/Controller/Admin/ContentAiStreamController.php:16, 45-84`.
  Le service `AiContentService $aiService` est injecté dans le constructeur (ligne 16) mais n'est **jamais utilisé**.
  Le contrôleur exécute :
  ```php
  58: $words = explode(' ', $text);
  59: $chunks = array_chunk($words, 4);
  60: 
  61: foreach ($chunks as $chunkIndex => $chunkWords) {
  62:     $fragment = implode(' ', $chunkWords) . ' ';
  ...
  75:     echo "event: chunk\n";
  76:     echo "data: " . json_encode(['chunk' => $fragment]) . "\n\n";
  77:     flush();
  78:     usleep(30000); // 30ms pour un effet streaming naturel
  79: }
  ```
- **Constat d'audit** : La fonctionnalité est entièrement factice. Elle découpe la chaîne fournie par l'utilisateur et la renvoie par paquets de 4 mots avec un délai artificiel de 30 millisecondes. Aucun LLM n'est sollicité.

---

### 3.2. [SIM-02] Stockage relationnel des répéteurs (`ContentFieldGroup`) : Implémentation fantôme
- **Documentation officielle** : `ROADMAP.md` (Phase 2, v1.20) affirme :
  *« Remplacement des JSON blobs par de vraies tables relationnelles avec contraintes FK (ContentFieldGroup, ContentEntryService)... »*
- **Réalité du code source** :
  1. L'entité `src/Entity/ContentFieldGroup.php` et son repository existent en base.
  2. Aucun contrôleur, aucun service de mutation, aucun résolveur GraphQL n'écrit ni ne lit dans cette table.
  3. `src/Service/EavDataFormatterService.php:135-136` continue de lire exclusivement les blobs JSON :
     ```php
     'json', 'array', 'repeater',
     'relation', 'enumeration', 'tags' => $fieldValue->jsonValue,
     ```
  4. Le service `ContentEntryService` annoncé dans la roadmap n'a **jamais été créé** dans le dépôt (absence totale de fichier).
- **Constat d'audit** : L'entité `ContentFieldGroup` est du code mort. Le système repose intégralement sur des chaînes JSON non normalisées.

---

### 3.3. [SIM-03] Fournisseur d'identité OAuth2 (RFC 6749) : Absence du flux d'autorisation
- **Documentation officielle** : `README.md:139` :
  *« OAuth2 Identity Provider (RFC 6749 + PKCE) : JamboApi devient IdP pour les applications tierces avec scopes fins, PKCE S256 et rotation des refresh tokens. »*
- **Réalité du code source** : `src/Controller/ProjectOAuthController.php`.
  Le contrôleur n'expose que trois routes :
  - `POST /token` (échange code -> jeton)
  - `GET|POST /userinfo` (profil utilisateur)
  - `POST /revoke` (révocation)
  **Aucune route `/authorize` n'est définie**.
  La méthode `ProjectOAuthService::createAuthorizationCode()` n'est appelée nulle part dans le code applicatif ; elle n'apparaît que dans le fichier de test unitaire `tests/Service/ProjectOAuthServiceTest.php`.
- **Constat d'audit** : Aucun utilisateur réel ni application tierce ne peut initier un flux d'autorisation OAuth2 standard avec redirection et recueil du consentement. Le flux est incomplet et inopérant.

---

### 3.4. [SIM-04] Bac à sable hermétique Twig : Classe orpheline non intégrée
- **Documentation officielle** : `README.md:150` :
  *« Hermetic Twig Sandbox (NativeTwigSecurityPolicy) : Strict AST token parsing and allowlist for safe dynamic template execution without SSTI / RCE risks. »*
- **Réalité du code source** : `src/Twig/NativeTwigSecurityPolicy.php`.
  La classe est définie et implémente `Twig\Sandbox\SecurityPolicyInterface`. Cependant, une recherche globale dans le conteneur Symfony et les configurations (`config/packages/twig.yaml`, `config/services.yaml`) montre qu'elle n'est enregistrée nulle part. Elle n'est instanciée que dans son propre test unitaire `NativeTwigSecurityPolicyTest.php`.
- **Constat d'audit** : La sandbox de sécurité n'est pas active dans l'environnement d'exécution de l'application.

---

### 3.5. [SIM-05] Release fictive du pipeline CI/CD automatisé
- **Historique git** :
  - Commit `f938c05` : Suppression du dossier `.github/`.
  - Commit `a6c316a` : Intitulé *« release: v1.26.0 - Automated CI/CD pipeline, smoke tests & roadmap complete »*.
- **Réalité du code source** :
  L'inspection du diff du commit `a6c316a` révèle que seules les métadonnées de version dans `package.json`, `composer.json` et le fichier `TEST_ROADMAP.md` ont été éditées. Le dossier `.github/workflows` n'a jamais été recréé.
- **Constat d'audit** : Aucun pipeline CI/CD n'existe dans le dépôt. L'annonce de release est fictive.

---

## 4. AUDIT D'ARCHITECTURE, QUALITÉ DU CODE & MODÈLE DE DONNÉES

### 4.1. Conflit fatal dans les migrations Doctrine
- **Fichiers incriminés** :
  - `migrations/Version20260624070523.php:41,45`
  - `migrations/Version20260629084616.php:23,24`
- **Analyse technique** :
  La migration du 24 juin 2026 crée les tables `form`, `form_submission`, `redirect`, `redirect_revision` et `seo_revision`.
  La migration du 29 juin 2026 tente à nouveau d'exécuter :
  ```sql
  CREATE TABLE form (id INT AUTO_INCREMENT NOT NULL, ...);
  CREATE TABLE form_submission (id INT AUTO_INCREMENT NOT NULL, ...);
  ```
  Sur une base de données neuve, l'exécution de `php bin/console doctrine:migrations:migrate` échoue immédiatement avec l'erreur SQL `1050 Table 'form' already exists`.
- **Impact** : Impossible d'initialiser une instance neuve à partir des migrations standard.

### 4.2. Crashs de type non interceptés dans les paramètres utilisateur
- **Fichiers incriminés** :
  - `src/Controller/Settings/PasswordController.php:28-32`
  - `src/Controller/Settings/ProfileController.php:24-27, 36`
- **Analyse technique** :
  ```php
  // PasswordController.php
  28: /** @var User $user */
  29: $user = $this->getUser();
  30: $data = $request->toArray();
  32: if (!$this->hasher->isPasswordValid($user, $data['current_password'] ?? ''))
  ```
  La route `PUT /api/settings/password` n'exigeant pas d'authentification préalable dans `security.yaml` et ne vérifiant pas `if (!$user instanceof User)`, un appel anonyme assigne `null` à `$user`. La méthode `isPasswordValid()` lève alors une `TypeError` PHP fatale, générant une réponse HTTP 500 unhandled. Le même défaut affecte `ProfileController.php` lors de la lecture de `$user->id`.

### 4.3. Blocage synchrone dans le déclenchement des automatisations
- **Fichiers incriminés** : `src/EventSubscriber/AutomationTriggerSubscriber.php:58`.
- **Analyse technique** :
  Bien que Symfony Messenger soit configuré (`config/packages/messenger.yaml`) avec routage de `ExecuteAutomationMessage` vers le transport asynchrone, le subscriber d'événements appelle directement le moteur de manière synchrone :
  ```php
  58: $this->engine->execute($automation, $payload);
  ```
  Toute action de type requête HTTP externe ou envoi d'email définie dans une automatisation bloque le thread PHP du visiteur ou de l'API.

---

## 5. AUDIT OPÉRATIONNEL, BUILD, DOCKER & DÉPLOIEMENT

### 5.1. Désynchronisation de `composer.lock`
- **Analyse technique** :
  L'exécution de `composer validate` signale :
  `The lock file is not up to date with the latest changes in composer.json.`
  La dépendance `spomky-labs/otphp` a été modifiée dans `composer.json` sans régénération du lockfile. Par conséquent, toute tentative d'installation en environnement de build automatisé (`composer install --no-dev --no-interaction`) échoue.

### 5.2. Dockerfile et configuration Nginx défaillants
- **Dockerfile:25,31** :
  - L'étape `RUN composer install` échoue en raison du lockfile désynchronisé.
  - L'étape `RUN php bin/console cache:warmup --env=prod` échoue car aucune base de données n'est accessible au moment du build de l'image.
- **docker/nginx.conf:24-27** :
  ```nginx
  location /cdn/media/ {
      alias /app/public/uploads/media/cache/;
      try_files $uri =404;
  }
  ```
  Nginx est configuré pour intercepter directement les requêtes vers `/cdn/media/` sur le disque statique sans délégation vers PHP (`index.php`). Lorsqu'une image n'a pas encore été générée en cache par `ImageTransformController`, Nginx retourne immédiatement une erreur 404 au lieu de solliciter le backend pour effectuer le redimensionnement.

### 5.3. Impasse dans l'assistant d'installation `/install`
- **Analyse technique** :
  La route `/install` de `InstallController.php` n'est pas incluse dans les exceptions `PUBLIC_ACCESS` de `security.yaml`. Elle est interceptée par la règle finale :
  `- { path: ^/, roles: ROLE_USER }`.
  Sur une instance neuve où aucun utilisateur n'existe encore, toute tentative de navigation vers `/install` est redirigée vers `/login`. Comme aucun compte n'existe, l'administrateur est dans l'impossibilité totale de finaliser l'installation via l'interface.

### 5.4. Script de déploiement dangereux (`deploy.py`)
- **Analyse technique** :
  Le script `deploy.py` se connecte en SSH et lance un `git pull origin main` directement dans le dossier public de production, suivi d'un `npm run build`.
  - Il n'exécute pas `composer install`.
  - Il n'exécute pas les migrations Doctrine.
  - Il n'exécute pas `cache:clear` ni `cache:warmup`.
  - Il ne propose aucun mécanisme de rollback ni de zéro-downtime.
  Ce script garantit la rupture de l'application en cas de modification du modèle de données ou des dépendances.

---

## 6. SYNTHÈSE DES CONSTATS & PLAN D'ACTION IMMÉDIAT

Le tableau ci-dessous récapitule les constats de l'audit technique par niveau de gravité :

| Identifiant | Intitulé de l'Anomalie | Gravité | Priorité | Fichier Principal |
| :--- | :--- | :--- | :--- | :--- |
| **SEC-001** | Fuite de mots de passe DB et SMTP dans git | Critique | P0 | `.env:11,19` |
| **SEC-002** | Écriture de fichier arbitraire / RCE dans la publication | Critique | P0 | `PublishedSiteStorage.php:30` |
| **SEC-003** | Fuite de données personnelles / annuaire utilisateurs | Critique | P0 | `UserController.php:26`, `security.yaml:73` |
| **SEC-004** | Exfiltration de données cross-tenant via MCP public | Critique | P0 | `McpController.php:55`, `JamboApiMcpServer.php:595` |
| **OPS-001** | Lockfile Composer corrompu / build bloqué | Critique | P0 | `composer.lock` |
| **OPS-002** | Double création de tables dans les migrations | Critique | P0 | `Version20260629084616.php:23` |
| **SEC-005** | Désactivation du tenant guard sur routes `{uuid}` | Élevée | P1 | `EnsureProjectMemberSubscriber.php:37` |
| **SEC-006** | Suppression de projets/collections par de simples viewers | Élevée | P1 | `AdminApi/CollectionController.php:101` |
| **SEC-007** | Risque SSRF et saturation workers via `file_get_contents` | Élevée | P1 | `StudioController.php:1402` |
| **SIM-001** | Assistant streaming IA factice | Élevée | P1 | `ContentAiStreamController.php:58` |
| **SIM-002** | Entité `ContentFieldGroup` fantôme / EAV bloqué | Élevée | P1 | `EavDataFormatterService.php:135` |
| **SIM-003** | Provider OAuth2 sans endpoint `/authorize` | Élevée | P1 | `ProjectOAuthController.php` |
| **OPS-003** | Nginx CDN 404 et Dockerfile non reproductible | Élevée | P1 | `docker/nginx.conf:26` |
| **OPS-004** | Assistant `/install` bloqué par l'authentification | Élevée | P1 | `security.yaml:77` |
| **OPS-005** | Script `deploy.py` incomplet sans cache ni migrations | Élevée | P1 | `deploy.py:44` |
| **SIM-004** | Twig Security Policy non branchée au conteneur | Moyenne | P2 | `NativeTwigSecurityPolicy.php` |
| **SIM-005** | Absence de workflows CI/CD malgré annonce release | Moyenne | P2 | `.github/workflows` |
| **OPS-006** | CDN incompatible avec stockage distant S3/R2 | Moyenne | P2 | `ImageTransformController.php:41` |
| **BUG-001** | Crashs TypeError 500 sur les paramètres utilisateur | Moyenne | P2 | `PasswordController.php:32` |
| **BUG-002** | Automatisations synchrones bloquant les requêtes | Moyenne | P2 | `AutomationTriggerSubscriber.php:58` |

Le plan directeur d'exécution ordonné pour traiter l'intégralité de ces constats est consigné dans le document de référence [ROADMAP_AUDIT_CORRECTIONS.md](file:///c:/laragon/www/jamboapicms/ROADMAP_AUDIT_CORRECTIONS.md).
