# INVENTAIRE FONCTIONNEL DE JAMBO API
**Projet** : Jambo API CMS  
**Référence** : CARTOGRAPHIE-FONCTIONNELLE-2026  
**Objectif** : Établir la réalité opérationnelle de chaque fonctionnalité revendiquée dans le code et la documentation (`README.md`, `ROADMAP.md`).  
**Classification des états** :
- **Opérationnelle et vérifiée** : Implémentation réelle, complète, testée et fonctionnelle de bout en bout.
- **Partiellement opérationnelle** : Logique existante mais incomplète, dépendante de conditions spécifiques ou présentant des failles ciblées.
- **Simulée / Factice** : Code de démonstration ou mock renvoyant des données fictives sans réaliser l'action annoncée.
- **Défaillante** : Code présent mais bloqué par des erreurs fatales ou des incohérences de configuration.
- **Absente** : Fonctionnalité annoncée dans la documentation mais inexistante dans le code source.

---

## TABLEAU RÉCAPITULATIF GLOBAL

| ID | Module / Fonctionnalité | Point d'entrée | Dépendances externes | État réel | Problème majeur identifié |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **MOD-01** | **Authentification Core CMS** | `POST /login`, `/logout` | Session PHP, MySQL | **Opérationnelle et vérifiée** | Requiert un compte préalable ; installation à froid bloquée. |
| **MOD-02** | **Annuaire Utilisateurs CMS** | `GET|POST /api/users` | Doctrine ORM | **Défaillante / Faille** | Pas d'authentification sur `GET /api/users` (fuite PII totale). |
| **MOD-03** | **Gestion des Rôles & Permissions** | `GET|POST /api/roles` | Doctrine ORM | **Défaillante / Faille** | Pas d'authentification sur `GET /api/roles` ni `/permissions`. |
| **MOD-04** | **Assistant d'Installation Web** | `GET|POST /install` | `InstallController` | **Défaillante** | Non exempté de sécurité : redirige vers `/login` à froid. |
| **MOD-05** | **Admin API (Tokens PAT)** | `/admin-api/v1/*` | Personal Access Token | **Partiellement opérationnelle** | Droit `VIEW` suffisant pour détruire des collections et projets. |
| **MOD-06** | **Isolation Multi-Tenant** | Event `KernelEvents::REQUEST` | `EnsureProjectMemberSubscriber` | **Défaillante** | Biais d'isolation sur routes `{uuid}` au lieu de `{projectUuid}`. |
| **MOD-07** | **Studio IA — Streaming Inline** | `POST /admin/api/content-ai/stream` | Provider LLM | **Simulée / Factice** | Découpe le texte client en paquets de 4 mots (`usleep 30ms`). N'appelle aucun LLM. |
| **MOD-08** | **Studio IA — Génération Images** | `POST /studio/ai-generate-image` | DALL-E / Gemini | **Partiellement opérationnelle** | Appel `@file_get_contents` non assaini (risque SSRF/LFI). |
| **MOD-09** | **Studio IA — Génération Schéma** | `POST /studio/ai-schema` | OpenAI / Anthropic / Gemini | **Opérationnelle et vérifiée** | Génération de structure JSON de collection opérationnelle. |
| **MOD-10** | **Provider OAuth2 / IdP** | `/api/v1/projects/{uuid}/oauth/*` | EndUser JWT, PKCE | **Partiellement opérationnelle** | **Pas d'endpoint `/authorize`**. Flux `authorization_code` inutilisable. |
| **MOD-11** | **Bac à sable Twig Hermétique** | Moteur Twig | `NativeTwigSecurityPolicy` | **Absente (en production)** | Classe créée mais jamais injectée dans Twig ni Symfony. |
| **MOD-12** | **Publication de Sites Statiques** | `POST /studio/publish` | Filesystem local | **Défaillante / Faille P0** | Écriture arbitraire de fichiers via path traversal (`../../public`). |
| **MOD-13** | **Serveur MCP (Agents IA)** | `POST /mcp`, `/api/projects/{uuid}/mcp` | Protocole JSON-RPC MCP | **Défaillante / Faille P0** | Accessible anonymement, fuite de tous les EndUsers et projets. |
| **MOD-14** | **CDN & Transformation Images** | `GET /cdn/media/{uuid}` | Extension PHP GD | **Défaillante** | Nginx intercepte et renvoie 404 sur images non déjà en cache. |
| **MOD-15** | **Répéteurs Relationnels (v1.20)** | Moteur EAV | `ContentFieldGroup` | **Simulée / Factice** | `ContentFieldGroup` non utilisé. L'API lit toujours des JSON blobs. |
| **MOD-16** | **Moteur d'Automatisations (Flows)** | Événements `ContentEvent` | FlowInterpreter, Messenger | **Partiellement opérationnelle** | Exécution synchrone bloquante dans la requête HTTP client. |
| **MOD-17** | **Recherche Fulltext & Vectorielle** | `GET /api/projects/{uuid}/search` | Index MySQL Fulltext | **Opérationnelle et vérifiée** | Requêtes MATCH AGAINST et filtres par locale opérationnels. |
| **MOD-18** | **Génération Sitemap & Redirections** | `GET /{uuid}/sitemap.xml` | Doctrine ORM | **Opérationnelle et vérifiée** | XML conforme et résolution des règles de redirection. |
| **MOD-19** | **Téléversement Direct Médias** | `POST /media/upload-url` | AWS S3 / Cloudflare R2 | **Opérationnelle et vérifiée** | Signatures d'URL pré-signées AWS S3 / R2 conformes. |
| **MOD-20** | **Migrations Base de Données** | `doctrine:migrations:migrate` | Doctrine Migrations | **Défaillante** | Conflit fatal `Table 'form' already exists` bloquant toute base neuve. |
| **MOD-21** | **Conteneurisation Docker** | `docker build .` | Dockerfile, Nginx, PHP-FPM | **Défaillante** | Build Docker échoue (lockfile Composer et warmup cache). |
| **MOD-22** | **Script de Déploiement** | `python deploy.py` | SSH Paramiko | **Défaillante** | Simple `git pull` sans `composer install`, ni migrations, ni cache. |
| **MOD-23** | **Paramètres Profil & Mot de passe** | `PUT /api/settings/password` | `UserPasswordHasherInterface` | **Défaillante** | Crashe en `TypeError` 500 sur appel non authentifié. |
| **MOD-24** | **Pipeline CI/CD Automatisé** | `.github/workflows/*` | GitHub Actions | **Absente** | Annoncé en release `v1.26.0` mais physiquement supprimé du dépôt. |

---

## ANALYSE DÉTAILLÉE PAR MODULE

### 1. Authentification & Gestion des Utilisateurs (MOD-01, MOD-02, MOD-03)
- **Objectif métier** : Permettre aux administrateurs de se connecter au back-office, gérer les comptes, créer des rôles et attribuer des permissions granulaires.
- **Comportement attendu** : Authentification par formulaire avec hachage bcrypt/argon2id, contrôle d'accès strict sur l'annuaire des utilisateurs.
- **Réalité de l'implémentation** :
  - La connexion par session fonctionne (`SecurityController.php`).
  - L'annuaire `GET /api/users` et les rôles `GET /api/roles` sont exposés sans authentification en raison de la directive `- { path: ^/api, roles: PUBLIC_ACCESS }` dans `security.yaml` et de l'absence de `#[IsGranted]` dans `UserController.php:26,115`.
- **Statut** : **Défaillant / Faille de sécurité critique (SEC-003)**.

---

### 2. Isolation Multi-Tenant (MOD-06)
- **Objectif métier** : Cloisonner strictement les projets pour empêcher tout utilisateur d'accéder aux données d'un autre tenant.
- **Comportement attendu** : Tout accès à une ressource de projet est filtré par l'appartenance de l'utilisateur ou du token au projet spécifié.
- **Réalité de l'implémentation** :
  - `EnsureProjectMemberSubscriber.php:37` lit `$request->attributes->get('projectUuid')`.
  - Plus de 15 routes fondamentales (GraphQL, Studio, Search, AI, MCP, EndUsers) déclarent le paramètre `{uuid}`.
  - `$projectUuid` est `null`, entraînant un `return` immédiat qui désactive le garde-fou.
- **Statut** : **Défaillant / Biais d'isolation (SEC-005)**.

---

### 3. Assistant IA en Streaming (MOD-07)
- **Objectif métier** : Fournir une assistance à la rédaction en streaming temps-réel (SSE) connectée aux modèles LLM.
- **Comportement attendu** : Connexion à l'API OpenAI, Gemini ou Anthropic avec transmission continue des tokens au fur et à mesure de leur génération.
- **Réalité de l'implémentation** :
  - Le contrôleur `src/Controller/Admin/ContentAiStreamController.php:58-79` n'appelle jamais le service IA injecté.
  - Il effectue `explode(' ', $text)` et `array_chunk($words, 4)` puis émet les mots saisis par l'utilisateur avec un `usleep(30000)`.
- **Statut** : **Simulée / Factice (SIM-001)**.

---

### 4. Fournisseur d'Identité OAuth2 (MOD-10)
- **Objectif métier** : Permettre aux applications tierces d'authentifier les utilisateurs finaux (EndUsers) via le protocole OAuth2 / OIDC avec PKCE.
- **Comportement attendu** : Exposition des endpoints `/authorize`, `/token`, `/userinfo`, `/revoke`.
- **Réalité de l'implémentation** :
  - Seuls `/token`, `/userinfo` et `/revoke` sont présents dans `ProjectOAuthController.php`.
  - L'endpoint fondamental `/authorize` est totalement absent.
  - La méthode `ProjectOAuthService::createAuthorizationCode()` n'est appelée que dans les tests unitaires.
- **Statut** : **Partiellement opérationnelle / Inutilisable de bout en bout (SIM-003)**.

---

### 5. Publication de Sites Statiques (MOD-12)
- **Objectif métier** : Permettre l'exportation et le déploiement d'un site statique généré pour un projet.
- **Comportement attendu** : Écriture sécurisée des fichiers HTML/CSS/JS dans le dossier dédié au projet (`/public/sites/{uuid}/`).
- **Réalité de l'implémentation** :
  - `src/Service/PublishedSiteStorage.php:30-35` concatène les chemins de fichiers sans vérifier la traversée de répertoire.
  - Des chemins contenant `../../` permettent d'écrire n'importe où sur le serveur web.
- **Statut** : **Défaillante / Vulnérabilité d'exécution de code arbitraire (SEC-002)**.

---

### 6. Serveur MCP — Model Context Protocol (MOD-13)
- **Objectif métier** : Permettre aux assistants IA (Claude Desktop, Cursor, IDEs) d'interagir avec le CMS via le protocole JSON-RPC MCP.
- **Comportement attendu** : Authentification stricte via jeton de projet pour exécuter les outils d'inspection et de manipulation de données.
- **Réalité de l'implémentation** :
  - L'URL `/api/projects/{uuid}/mcp` ne valide aucune authentification.
  - L'outil `list_end_users` exécute la requête Doctrine sans vérifier l'utilisateur dans le contexte.
  - L'outil `list_projects` sur `/mcp` liste tous les projets multi-tenants sans restriction.
- **Statut** : **Défaillante / Faille d'exfiltration de données (SEC-004)**.

---

### 7. CDN & Transformation d'Images (MOD-14)
- **Objectif métier** : Redimensionner, recadrer et convertir à la volée les images hébergées (`GET /cdn/media/{uuid}?w=800&fmt=webp`).
- **Comportement attendu** : Servir le fichier depuis le cache s'il existe ; sinon, exécuter `ImageTransformController` via PHP pour générer la miniature.
- **Réalité de l'implémentation** :
  - `docker/nginx.conf:24-27` déclare un `alias /app/public/uploads/media/cache/` avec `try_files $uri =404;`.
  - Nginx ne transfère jamais la requête à PHP si le fichier cache n'existe pas encore.
  - De plus, `ImageTransformController.php:41` cherche uniquement sur le disque local, rendant le CDN inopérant avec un stockage distant S3.
- **Statut** : **Défaillante (OPS-003, OPS-006)**.

---

### 8. Répéteurs Relationnels (MOD-15)
- **Objectif métier** : Stocker les champs répéteurs complexes en base relationnelle avec contraintes d'intégrité et clés étrangères.
- **Comportement attendu** : Hydratation des entités `ContentFieldGroup` et `ContentFieldValue` reliées aux entrées de contenu.
- **Réalité de l'implémentation** :
  - L'entité existe en base mais n'est manipulée par aucun contrôleur.
  - `EavDataFormatterService.php:135` lit directement `$fieldValue->jsonValue`.
  - Le service `ContentEntryService` annoncé n'existe pas.
- **Statut** : **Simulée / Factice (SIM-002)**.

---

### 9. Migrations & Déploiement (MOD-20, MOD-21, MOD-22)
- **Objectif métier** : Permettre l'installation automatisée et le déploiement continu du CMS.
- **Comportement attendu** : Migrations idempotentes, build Docker reproductible, déploiement atomique.
- **Réalité de l'implémentation** :
  - Deux migrations créent les mêmes tables (`Version20260624070523.php` et `Version20260629084616.php`), causant un crash fatal SQL sur base vierge.
  - `composer.lock` est désynchronisé (`composer install` échoue en non-interactif).
  - `deploy.py` fait un simple `git pull` sans migrer la base ni vider le cache.
- **Statut** : **Défaillante (OPS-001, OPS-002, OPS-005)**.
