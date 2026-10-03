# 🧪 Jambo API — Feuille de Route Complète des Tests (Test Roadmap)

> **Version cible du document :** Jambo API v1.20.0+ (Cycle v1.x Enterprise)  
> **Dernière mise à jour :** Octobre 2026  
> **Statut global :** En cours d'exécution — 503 tests PHPUnit répertoriés, suites E2E Playwright intégrées.

---

## 📑 Sommaire

1. [Vision & Philosophie de Test](#1-vision--philosophie-de-test)
2. [Matrice Pyramidale & Objectifs de Couverture](#2-matrice-pyramidale--objectifs-de-couverture)
3. [État des Lieux & Audit de Couverture Actuel](#3-état-des-lieux--audit-de-couverture-actuel)
4. [Stratégie de Versionnement Git (v1.*.*) & Triggers de Push](#4-stratégie-de-versionnement-git-v1---triggers-de-push)
5. [Phasage Détaillé du Test Roadmap & Tags Associés](#5-phasage-détaillé-du-test-roadmap--tags-associés)
   - [Phase T1 (v1.20.1) : Moteur EAV & Intégrité du Contenu](#phase-t1-v1201--moteur-eav--intégrité-du-contenu)
   - [Phase T2 (v1.21.0) : Sécurité, Authentification & Autorisations](#phase-t2-v1210--sécurité-authentification--autorisations)
   - [Phase T3 (v1.21.1) : Admin REST API & Public REST API](#phase-t3-v1211--admin-rest-api--public-rest-api)
   - [Phase T4 (v1.22.0) : Moteur GraphQL Dynamique & Temps Réel (Mercure)](#phase-t4-v1220--moteur-graphql-dynamique--temps-réel-mercure)
   - [Phase T5 (v1.23.0) : Médias, Upload Direct S3/R2 & Stockage](#phase-t5-v1230--médias-upload-direct-s3r2--stockage)
   - [Phase T6 (v1.24.0) : Moteur Flow, Automations & Assistant IA SSE](#phase-t6-v1240--moteur-flow-automations--assistant-ia-sse)
   - [Phase T7 (v1.24.1) : Formulaires, SEO, Redirections & Blueprints](#phase-t7-v1241--formulaires-seo-redirections--blueprints)
   - [Phase T8 (v1.25.0) : Tests E2E Interface Studio (Playwright)](#phase-t8-v1250--tests-e2e-interface-studio-playwright)
   - [Phase T9 (v1.25.1) : Performance, Concurrence & Audit de Sécurité](#phase-t9-v1251--performance-concurrence--audit-de-sécurité)
   - [Phase T10 (v1.26.0) : Pipeline CI/CD & Smoke Tests Production](#phase-t10-v1260--pipeline-cicd--smoke-tests-production)
6. [Cahier de Recette Exécutable & Commandes](#6-cahier-de-recette-exécutable--commandes)
   - [Procédure Standard de Tagging & Push Git](#procédure-standard-de-tagging--push-git)
7. [Tableau de Bord & Critères de Validation](#7-tableau-de-bord--critères-de-validation)

---

## 1. Vision & Philosophie de Test

Jambo API est un CMS Headless découplé, multi-tenant et composable servant à la fois des API REST critiques, un endpoint GraphQL introspectif dynamique, des flux de streaming IA (SSE), un système de stockage objet S3/R2, et un Studio d'administration React/Inertia.

La résilience de la plateforme repose sur 4 piliers de test fondamentaux :

1. **Intégrité stricte des données EAV** : Aucune corruption de schéma, collision d'unicité (slug, locale) ou régression de relation ne doit survenir lors des opérations CRUD, y compris en cas de soft-delete ou de requêtes partielles `PATCH`.
2. **Cloisonnement multi-tenant & Sécurité hermétique** : Élimination absolue des failles IDOR (*Insecure Direct Object References*) entre projets, contrôle strict des portées de jetons (PAT, ApiToken, OAuth2 PKCE), et protection proactive contre les attaques par force brute.
3. **Parité fonctionnelle REST / GraphQL** : Les règles de validation, filtres, tris et résolutions de relations doivent produire des résultats strictement identiques quel que soit le protocole de consommation.
4. **Non-régression des flux asynchrones & streaming** : Les processus longs (téléversements S3 fragmentés, files Messenger, workflows Flow et SSE IA) doivent disposer de filets de sécurité garantissant une reprise sur incident sans blocage de ressources PHP.

---

## 2. Matrice Pyramidale & Objectifs de Couverture

```
                   /\
                  /  \     Tests E2E Studio (Playwright)
                 / E2E\    Cible : 100% des parcours critiques d'édition
                /------\
               /  API   \  Tests Fonctionnels d'Intégration (REST & GraphQL)
              /  & HTTP  \ Cible : > 90% des endpoints et codes HTTP
             /------------\
            /  Services &  \ Tests d'Intégration Métier & ORM
           /   Subscribers  \ Cible : > 85% de couverture des services
          /------------------\
         /    Unitaires &     \ Tests Unitaires Purs (Entités, Helpers, Enums)
        /     Normaliseurs     \ Cible : > 95% de couverture
       /------------------------\
```

| Niveau | Outil | Rôle Principal | SLA Exécution | Couverture Cible |
| :--- | :--- | :--- | :---: | :---: |
| **Unitaires** | PHPUnit 13 | Entités, Enums, DTOs, Helpers, résolveurs purs | < 5 sec | 95% |
| **Intégration** | PHPUnit + Symfony Kernel | ORM Doctrine, Services métier, Normaliseurs, Flow | < 30 sec | 85% |
| **Fonctionnels API** | PHPUnit BrowserKit / WebTestCase | Endpoints `/admin-api/`, `/api/v1/`, GraphQL | < 60 sec | 90% |
| **E2E UI** | Playwright | Studio React, navigation, formulaires, AI Toolbar | < 3 min | Parcours clés |
| **Sécurité & Fuzzing** | PHPUnit + Scénarios cURL | IDOR, Lockout, CSRF, Injections SQL/JSON, CORS | < 1 min | 100% auth/RBAC |
| **Charge & Stress** | k6 / wrk | Débit REST, requêtes EAV complexes, saturation DB | À la release | > 500 req/s |

---

## 3. État des Lieux & Audit de Couverture Actuel

### 3.1 Inventaire Existant (v1.20.0)
* **Suite PHPUnit :** 503 tests répartis dans 94 classes de tests (`tests/Entity`, `tests/Service`, `tests/Controller`, `tests/Command`, `tests/GraphQL`, `tests/Security`).
* **Suite Playwright :** Spécifications initiales dans `e2e/api.spec.ts`, `e2e/auth.spec.ts`, `e2e/dashboard.spec.ts`.
* **API Documentation :** Spécification OpenAPI / Swagger UI synchronisée pour Public API et Admin API (`/api/docs`).

### 3.2 Vulnérabilités & Écarts Identifiés lors de l'Audit v1.20
* ⚠️ **Gestion des Slugs et Soft Deletes :** L'index d'unicité `UNIQ_COLLECTION_SLUG_LOCALE` porte sur toutes les lignes physiques. La génération automatique doit systématiquement tester l'unicité y compris contre les entrées supprimées (`deleted_at IS NOT NULL`).
* ⚠️ **Validation partielle `PATCH` :** Les champs obligatoires non transmis dans un payload `PATCH` ne doivent pas déclencher d'erreur de validation "requis".
* ⚠️ **Cycle de vie Multipart S3 :** Les phases d'abandon (`abortMultipart`) et de timeout d'expiration d'intent nécessitent des tests d'isolation avec simulation d'échec réseau.
* ⚠️ **Résilience des Workers Messenger & Crons :** Vérification de l'idempotence des commandes de publication planifiée (`jambo:content:publish-scheduled`) en environnement d'hébergement mutualisé.

---

## 4. Stratégie de Versionnement Git (v1.*.*) & Triggers de Push

> ⚠️ **Directive Fondamentale de Versionnement :** Le cycle actuel de Jambo API est strictement borné à la version majeure **v1** (`v1.*.*`). La version 2 n'est **pas encore envisageable** à ce stade du cycle produit. Tous les tags créés et poussés (`git push origin <tag>`) doivent respecter strictement les spécifications SemVer au sein de la branche `v1.x.y` :
> - **Patch releases (`v1.20.x`, `v1.21.x`, `v1.24.x`, etc.) :** Résolution de bugs critiques, corrections de régression, durcissement des validations sans rupture d'API.
> - **Minor releases (`v1.21.0`, `v1.22.0`, `v1.23.0`, `v1.24.0`, `v1.25.0`, `v1.26.0`) :** Livraisons de modules complets testés et stabilisés, ajout de nouvelles suites d'API ou d'intégrations E2E backward-compatible.

### 4.1 Matrice d'Évolution des Tags Git (`v1.*.*`) par Phase de Test

| Phase de Test | Tag Git Cible | Type SemVer | Milestone Fonctionnelle | Quality Gate (Critère de Validation du Tag) | Déclencheur du Push (`git push`) |
| :--- | :---: | :---: | :--- | :--- | :--- |
| **Phase T1** | `v1.20.1` | Patch | Intégrité EAV, Slugs & Patch | 100% tests d'unicité slugs (avec soft-delete), cast EAV et validation `PATCH` au vert | Après validation de la suite `tests/Entity` et `ContentControllerTest` |
| **Phase T2** | `v1.21.0` | Mineure | Sécurité, Auth PAT & OAuth2 | Tests PAT HMAC-SHA256, isolation IDOR, lockout anti-bruteforce et OAuth2 PKCE validés | Après exécution complète de `tests/Security` et `AdminApiSecurityTest` |
| **Phase T3** | `v1.21.1` | Patch | Admin & Public REST API | Swagger UI `/api/docs` synchronisé, formatage RFC 7807 et filtres avancés conformes | Après validation de `tests/Controller/AdminApi` et `Api` |
| **Phase T4** | `v1.22.0` | Mineure | GraphQL Dynamique & Mercure | Pas de requêtes N+1, limiteurs de profondeur/complexité actifs, SSE Mercure opérationnel | Après succès de `SchemaGeneratorTest` et `RealtimeControllerTest` |
| **Phase T5** | `v1.23.0` | Mineure | Upload Direct S3 & Médias | Cycle complet S3 multipart (intent, sign, complete, abort) et assainissement SVG validés | Après succès de `DirectUploadServiceTest` et tests de stockage |
| **Phase T6** | `v1.24.0` | Mineure | Moteur Flow & IA Streaming | DAG sans boucles infinies, streaming SSE sans fuite mémoire, audit des déclencheurs validé | Après validation de `FlowInterpreterTest` et `ContentAiStreamControllerTest` |
| **Phase T7** | `v1.24.1` | Patch | Formulaires, SEO & Blueprints | Pot-de-miel anti-spam, détection de boucles de redirection et roundtrip import/export ZIP parfaits | Après validation de `SubmitHandlerTest`, `RedirectChainDetectorTest` et `BlueprintCommandsTest` |
| **Phase T8** | `v1.25.0` | Mineure | Studio E2E (Playwright) | 100% des scénarios E2E Studio passants (auth, édition de blocs, navigation, publication) | Après validation de `npm run test:e2e` en headless CI |
| **Phase T9** | `v1.25.1` | Patch | Performance & Audit OWASP | Débit REST > 500 req/s (p95 < 120ms), zéro alerte critique sur l'audit de sécurité OWASP | Après benchmark k6 et revue des entêtes de sécurité |
| **Phase T10**| `v1.26.0` | Mineure | CI/CD & Recette Production | Pipeline GitHub Actions automatisé au vert, smoke tests 100% réussis sur `api.jambostack.site` | Déploiement production final et vérification live |

---

## 5. Phasage Détaillé du Test Roadmap & Tags Associés

### Phase T1 (v1.20.1) : Moteur EAV & Intégrité du Contenu

* **Tag Git Associé :** `v1.20.1` (Patch release)
* **Commande de Push :** `git tag -a v1.20.1 -m "release: v1.20.1 - EAV slug uniqueness & partial PATCH validation fixes" && git push origin v1.20.1`
* **Quality Gate :** 0 erreur sur l'unicité des slugs avec soft-deletes, hydratation et validation partielle `PATCH`.

**Objectif :** Garantir que le modèle EAV dynamique préserve l'intégrité relationnelle, le typage des valeurs et l'unicité des accès publics.

#### Périmètre des Classes Cibles
* Entités : `ContentEntry`, `ContentFieldValue`, `ContentFieldGroup`, `Collection`, `Field`, `ContentVersion`.
* Services : `EavDataFormatterService`, `EavFieldHelperService`, `FieldValueHydrator`, `FieldConditionEvaluator`, `VersioningService`.
* Contrôleurs : `App\Controller\Api\ContentController`, `App\Controller\ContentController`.

#### Cas de Test Prioritaires
- [ ] **T1.1 — Collision de Slugs & Soft Delete :**
  - Créer une entrée avec slug `mon-article`.
  - Supprimer (soft-delete) l'entrée (`deleted_at` renseigné).
  - Créer une seconde entrée avec le même titre ou slug `mon-article`.
  - *Attendu :* Attribution automatique du slug `mon-article-1` sans lever d'exception SQL 1062.
- [ ] **T1.2 — Validation Partielle sur Requête `PATCH` :**
  - Mettre à jour uniquement le statut ou un champ optionnel via `PATCH`.
  - *Attendu :* Succès 200 OK même si les autres champs requis ne figurent pas dans le corps de requête.
- [ ] **T1.3 — Groupes de Champs Relationnels (`ContentFieldGroup`) :**
  - Enregistrer un repeater contenant 3 blocs avec valeurs imbriquées.
  - Réordonner les blocs (`sort_order`).
  - *Attendu :* Persistance fidèle des relations `groupInstance` et indexation SQL correcte.
- [ ] **T1.4 — Typage & Cast des Attributs EAV :**
  - Vérifier la persistance et l'hydratation des types : `text`, `number`, `boolean`, `date`, `datetime`, `json`, `markdown`, `media`.
- [ ] **T1.5 — Versioning & Restauration :**
  - Modifier une entrée 3 fois consécutives.
  - Vérifier la création de 3 instances `ContentVersion`.
  - Restaurer la version 1 et valider le rétablissement exact des valeurs EAV.

---

### Phase T2 (v1.21.0) : Sécurité, Authentification & Autorisations

* **Tag Git Associé :** `v1.21.0` (Mineure)
* **Commande de Push :** `git tag -a v1.21.0 -m "release: v1.21.0 - Personal Access Tokens HMAC-SHA256, IDOR multi-tenant isolation, anti-bruteforce lockout & OAuth2 PKCE" && git push origin v1.21.0`
* **Quality Gate :** 100% de succès sur `AdminApiSecurityTest`, isolation stricte inter-projets et révocabilité immédiate des sessions.

**Objectif :** Valider l'étanchéité des différents mécanismes d'accès, des jetons administratifs et des sessions utilisateurs.

#### Périmètre des Classes Cibles
* Entités : `User`, `PersonalAccessToken`, `ApiToken`, `ProjectAuthClient`, `ProjectAuthRefreshToken`, `ProjectAuthSession`.
* Services : `ProjectOAuthService`, `EndUserJwtService`, `ProjectAuthLockoutService`, `TwoFactorService`, `SocialLoginService`.
* Sécurité : `AdminApiSecurityTest`, `OidcAuthenticator`.

#### Cas de Test Prioritaires
- [ ] **T2.1 — Personal Access Tokens (PAT) Admin API :**
  - Génération via CLI `bin/console jambo:pat:create --user=admin@example.com --scope=admin`.
  - Hachage HMAC-SHA256 en base (`token_hash`) ; affichage en clair une seule fois (`jbo_pat_...`).
  - Requête `GET /admin-api/projects` avec en-tête `Authorization: Bearer jbo_pat_...`.
  - *Attendu :* 200 OK avec payload JSON.
- [ ] **T2.2 — Révocation & Expiration des Jetons PAT :**
  - Tester un PAT expiré (`expires_at < NOW()`).
  - Tester un PAT révoqué via `DELETE /admin-api/tokens/{id}`.
  - *Attendu :* 401 Unauthorized avec message clair `Token expired or revoked`.
- [ ] **T2.3 — Isolation Multi-Tenant (Protection IDOR) :**
  - Projet A (Propriétaire Utilisateur 1) et Projet B (Propriétaire Utilisateur 2).
  - Tenter d'accéder aux collections du Projet B avec le jeton du Projet A.
  - *Attendu :* 403 Forbidden ou 404 Not Found strict. Aucune fuite d'informations.
- [ ] **T2.4 — Protection Anti-Bruteforce & Lockout :**
  - Exécuter 5 tentatives consécutives de connexion avec mot de passe erroné.
  - *Attendu :* Déclenchement du verrouillage 15 minutes (`429 Too Many Requests` ou rejet avec délai).
- [ ] **T2.5 — Flux OAuth2 Client (RFC 6749 + PKCE) :**
  - Échange du code d'autorisation avec vérification du challenge PKCE (`code_verifier` / `code_challenge`).
  - Émission et renouvellement du Refresh Token.
  - Révocation de session unitaire.

---

### Phase T3 (v1.21.1) : Admin REST API & Public REST API

* **Tag Git Associé :** `v1.21.1` (Patch release)
* **Commande de Push :** `git tag -a v1.21.1 -m "release: v1.21.1 - REST API RFC 7807 compliance, collection filtering & OpenAPI synchronization" && git push origin v1.21.1`
* **Quality Gate :** Swagger UI `/api/docs` 100% aligné, pagination et conformité des codes d'erreurs HTTP (200, 401, 403, 404, 422).

**Objectif :** Assurer la conformité des contrats d'interface, des codes d'état HTTP, du filtrage avancé et de la pagination.

#### Périmètre des Classes Cibles
* Contrôleurs Admin API : `App\Controller\AdminApi\ProjectController`, `CollectionController`, `FieldController`, `TokenController`, `PingController`.
* Contrôleurs Public API : `App\Controller\Api\ContentController`, `CollectionController`, `ProjectInfoController`, `SeoController`.

#### Cas de Test Prioritaires
- [ ] **T3.1 — Endpoint Santé & Diagnostic :**
  - `GET /admin-api/_ping` avec PAT valide -> `{"status":"ok","timestamp":...,"version":"1.20.0"}`.
- [ ] **T3.2 — CRUD Complet de Schéma via API :**
  - Créer un projet -> Créer une collection -> Créer 5 champs de types distincts.
  - Supprimer un champ avec cascade propre sur les valeurs EAV orphelines.
- [ ] **T3.3 — Moteur de Recherche & Filtrage Avancé Public API :**
  - `GET /api/v1/projects/{id}/collections/{slug}/content?filter[prix][gte]=100&filter[status]=published&sort=-createdAt&limit=10&page=1`.
  - Valider l'exactitude des résultats et des méta-données de pagination (`total`, `page`, `pages`, `limit`).
- [ ] **T3.4 — Accès Direct par Slug :**
  - `GET /api/v1/projects/{id}/collections/{slug}/content/by-slug/{entry-slug}`.
  - Tester les cas : entrée publiée (200), entrée brouillon (404 sans preview token, 200 avec preview token valide), slug inexistant (404).
- [ ] **T3.5 — Formatage RFC 7807 des Erreurs :**
  - Soumettre un JSON invalide ou un champ typé incorrectement.
  - *Attendu :* Réponse HTTP 422 avec structure d'erreur standardisée (`type`, `title`, `detail`, `violations`).

---

### Phase T4 (v1.22.0) : Moteur GraphQL Dynamique & Temps Réel (Mercure)

* **Tag Git Associé :** `v1.22.0` (Mineure)
* **Commande de Push :** `git tag -a v1.22.0 -m "release: v1.22.0 - GraphQL dynamic schema security (query depth limits) & Mercure SSE real-time broadcast" && git push origin v1.22.0`
* **Quality Gate :** Aucune régression N+1 requêtes sur les résolutions imbriquées, rejet strict des requêtes cycliques et diffusion Mercure confirmée.

**Objectif :** Valider la génération dynamique du schéma GraphQL typé et la distribution temps réel des mutations de contenu.

#### Périmètre des Classes Cibles
* `App\GraphQL\SchemaGenerator`, `App\Controller\GraphQLController`.
* `App\Service\MercurePublisher`, `App\Controller\RealtimeController`.

#### Cas de Test Prioritaires
- [ ] **T4.1 — Introspection Dynamique du Schéma GraphQL :**
  - Définir une collection `Articles` avec des champs spécifiques.
  - Exécuter une requête d'introspection `__schema` sur `/api/v1/projects/{id}/graphql`.
  - *Attendu :* Types `Article`, `ArticleFilterInput`, `ArticleSortInput` correctement exposés.
- [ ] **T4.2 — Requêtes avec Résolution de Relations Imbriquées :**
  - Requête d'un article avec son auteur (relation `User`), ses catégories (relation ManyToMany) et ses images média.
  - *Attendu :* Pas de problème N+1 requêtes en base ; résultat JSON structuré conforme.
- [ ] **T4.3 — Protection contre les Requêtes Abusives (Sécurité GraphQL) :**
  - Tester la limitation de profondeur de requête (Query Depth Limiter, max 6 niveaux).
  - Tester la limitation de complexité (Query Complexity Limiter).
  - *Attendu :* Rejet explicite des requêtes circulaires ou récursives.
- [ ] **T4.4 — Diffusion d'Événements Mercure SSE :**
  - Mutation d'une entrée de contenu via API.
  - *Attendu :* Émission d'un payload vers le Hub Mercure avec le topic `https://jambostack.site/projects/{id}/collections/{name}` et JWT valide.

---

### Phase T5 (v1.23.0) : Médias, Upload Direct S3/R2 & Stockage

* **Tag Git Associé :** `v1.23.0` (Mineure)
* **Commande de Push :** `git tag -a v1.23.0 -m "release: v1.23.0 - Cloudflare R2 / AWS S3 direct multipart upload lifecycle & SVG sanitization" && git push origin v1.23.0`
* **Quality Gate :** Succès du cycle complet (single put, multi-part, abort, complete) et assainissement XSS des fichiers SVG.

**Objectif :** Certifier le pipeline de stockage cloud direct, le découpage multipart et la sécurisation des fichiers téléversés.

#### Périmètre des Classes Cibles
* Entités : `Media`, `MediaFolder`, `AssetUploadIntent`, `ProjectStorageProfile`.
* Services : `DirectUploadService`, `TusServer`, `ImageTransformService`, `StorageDriverFactory`.
* Contrôleurs : `DirectUploadController`, `MediaController`, `TusController`.

#### Cas de Test Prioritaires
- [ ] **T5.1 — Initialisation d'Intent d'Upload Direct (Single PUT) :**
  - Appel `POST /api/v1/projects/{id}/media/direct-upload/intent` avec taille < 5MB.
  - *Attendu :* Création de l'entité `AssetUploadIntent`, retour de l'URL pré-signée S3 avec expiration 15 min.
- [ ] **T5.2 — Upload Direct Multipart S3 / R2 (Fichiers volumineux > 100MB) :**
  - Initialisation multipart -> Récupération de l'ID multipart AWS.
  - Signature individuelle des parts 1, 2, 3 via `/multipart/sign-part`.
  - Finalisation `/complete` avec vérification de la création de l'entité `Media`.
- [ ] **T5.3 — Abandon & Nettoyage d'Upload :**
  - Appel `/abort` en cours de téléversement.
  - *Attendu :* Annulation auprès du bucket S3 et suppression de l'intent orphelin.
- [ ] **T5.4 — Sécurité des Téléversements :**
  - Tentative de téléversement d'un script malveillant (.php, .phtml, .sh, .phar) déguisé en image.
  - Fichier SVG contenant des balises `<script>` (XSS).
  - *Attendu :* Rejet 422, assainissement SVG automatique et validation MIME stricte côté serveur.
- [ ] **T5.5 — Redimensionnement & Cache d'Images Dynamique :**
  - Requête d'image avec paramètres `?w=300&h=200&fit=crop&fm=webp`.
  - *Attendu :* Transformation à la volée, génération du cache optimisé et en-têtes `Cache-Control`.

---

### Phase T6 (v1.24.0) : Moteur Flow, Automations & Assistant IA SSE

* **Tag Git Associé :** `v1.24.0` (Mineure)
* **Commande de Push :** `git tag -a v1.24.0 -m "release: v1.24.0 - Flow DAG interpreter, loop cycle detection & resilient AI inline SSE stream" && git push origin v1.24.0`
* **Quality Gate :** Interdiction des boucles infinies de flux, streaming SSE sans fuite mémoire et audit d'exécution complet.

**Objectif :** Tester la robustesse du moteur d'exécution visuel de flux, la gestion des erreurs de noeuds et le streaming IA.

#### Périmètre des Classes Cibles
* Services Flow : `FlowInterpreter`, `FlowValidator`, `NodeRegistry`, Noeuds (`Trigger`, `Action`, `Logic`, `Ai`, `Transform`).
* Contrôleur IA : `ContentAiStreamController`.

#### Cas de Test Prioritaires
- [ ] **T6.1 — Déclencheur Événementiel `ContentCreated` -> Action `SendEmail` :**
  - Création d'une entrée déclenchant un Flow.
  - *Attendu :* Interprétation sans blocage, envoi simulé d'email tracé dans `EmailLog`.
- [ ] **T6.2 — Nœuds Logiques Conditionnels (`ConditionHandler`, `SwitchHandler`) :**
  - Exécution d'un Flow avec embranchement selon la valeur d'un champ (`status == 'urgent'`).
  - *Attendu :* Seule la branche valide est exécutée, sans effets secondaires sur l'autre branche.
- [ ] **T6.3 — Détection des Boucles Infinies dans les Flux :**
  - Soumettre un graphe de flux contenant une boucle récursive fermée.
  - *Attendu :* Rejet dès la validation par `FlowValidator` avant toute persistance.
- [ ] **T6.4 — Streaming SSE Assistant IA (`ContentAiStreamController`) :**
  - Appel `POST /admin/api/content-ai/stream` avec action `summarize`.
  - *Attendu :* Flux `text/event-stream` continu avec fragments partiels et événement final `[DONE]`.
  - Gestion des coupures de connexion client sans fuite de mémoire PHP.

---

### Phase T7 (v1.24.1) : Formulaires, SEO, Redirections & Blueprints

* **Tag Git Associé :** `v1.24.1` (Patch release)
* **Commande de Push :** `git tag -a v1.24.1 -m "release: v1.24.1 - Anti-spam form protection, redirect loop detection & blueprint import/export roundtrip" && git push origin v1.24.1`
* **Quality Gate :** 0 boucle de redirection non détectée, blocage absolu du spam par pot-de-miel et parité 100% sur les exports/imports ZIP.

**Objectif :** Valider les fonctionnalités publiques front-facing, la détection des boucles de redirection et la portabilité des templates.

#### Périmètre des Classes Cibles
* `FormPublicController`, `AntiSpamService`, `SubmitHandler`.
* `RedirectResolver`, `RedirectChainDetector`.
* `SeoAnalyzer`, `SitemapGenerator`, `StructuredDataGenerator`.
* `ProjectExporter`, `ProjectImporter`, `BlueprintCommandsTest`.

#### Cas de Test Prioritaires
- [ ] **T7.1 — Soumission de Formulaire Public & Anti-Spam :**
  - Soumission avec champ pot-de-miel (honeypot) renseigné -> rejet silencieux ou 422.
  - Soumission valide -> création d'un `FormSubmission` et notification éventuelle.
- [ ] **T7.2 — Résolution de Redirections & Détection de Boucles :**
  - Configurer `/a -> /b`, `/b -> /c`, `/c -> /a`.
  - *Attendu :* Détection immédiate du cycle par `RedirectChainDetector` et blocage de la saisie.
  - Redirection simple `/old-page` -> 301 Moved Permanently vers `/new-page`.
- [ ] **T7.3 — Génération Sitemap XML & Balises Hreflang :**
  - Validation du flux XML généré contre le schéma standard Google Sitemap.
  - Vérification de la présence des balises `<xhtml:link rel="alternate" hreflang="..." />` pour les contenus multilingues.
- [ ] **T7.4 — Export / Import de Blueprints & Projets (Roundtrip Test) :**
  - Export d'un projet complet avec schémas, validations et profils via `jambo:project:export`.
  - Import dans une base neuve via `jambo:project:import`.
  - *Attendu :* 100% de concordance structurelle et de métadonnées.

---

### Phase T8 (v1.25.0) : Tests E2E Interface Studio (Playwright)

* **Tag Git Associé :** `v1.25.0` (Mineure)
* **Commande de Push :** `git tag -a v1.25.0 -m "release: v1.25.0 - Studio E2E test suite integration & UI regression suite" && git push origin v1.25.0`
* **Quality Gate :** 100% des tests Playwright passants (aucun échec UI, formulaires et navigation réactifs).

**Objectif :** Vérifier que l'interface utilisateur web (React + Inertia + TypeScript) réagit fidèlement aux interactions utilisateur.

#### Fichiers de Tests Cibles
* `e2e/auth.spec.ts` : Connexion, 2FA, Déconnexion, Réinitialisation mot de passe.
* `e2e/dashboard.spec.ts` : Navigation, sélecteur de projet, statistiques d'utilisation.
* `e2e/content-editor.spec.ts` : Création et modification de contenu, saisie dans l'éditeur de blocs, prévisualisation en direct.
* `e2e/schema-builder.spec.ts` : Ajout de collections, création de champs par glisser-déposer, configuration des validations.

#### Scénarios d'Exécution Playwright
```typescript
// Exemple de flux critique dans e2e/content-editor.spec.ts
test('Création d\'un article complet avec réordonnancement de blocs et publication', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/projects/demo/collections/articles/new');
  await page.fill('input[name="title"]', 'Nouvel article Playwright');
  await expect(page.locator('input[name="slug"]')).toHaveValue('nouvel-article-playwright');
  
  // Ajout d'un bloc repeater
  await page.click('button:has-text("Ajouter un bloc")');
  await page.fill('textarea[name="blocks[0].content"]', 'Contenu du bloc 1');
  
  // Sauvegarde brouillon puis publication
  await page.click('button:has-text("Enregistrer")');
  await expect(page.locator('.toast-success')).toBeVisible();
  await page.click('button:has-text("Publier")');
  await expect(page.locator('.badge-published')).toBeVisible();
});
```

---

### Phase T9 (v1.25.1) : Performance, Concurrence & Audit de Sécurité

* **Tag Git Associé :** `v1.25.1` (Patch release)
* **Commande de Push :** `git tag -a v1.25.1 -m "release: v1.25.1 - Performance optimization & OWASP security hardening" && git push origin v1.25.1`
* **Quality Gate :** Débit > 500 req/s sous charge, p95 < 120ms, 0 vulnérabilité OWASP critique.

**Objectif :** Éprouver le système sous forte charge et valider sa résistance aux attaques informatiques.

#### 1. Tests de Charge (Benchmarks k6 / wrk)
* **Scénario Lecture Publique :** 200 utilisateurs virtuels concurrents sur `GET /api/v1/projects/{id}/collections/articles/content`.
  - *Seuil de tolérance :* Temps de réponse médian < 40ms, p95 < 120ms, taux d'erreur 0.00%.
* **Scénario Écriture Concurrente :** 50 utilisateurs virtuels injectant du contenu simultanément.
  - *Seuil de tolérance :* Aucune collision de verrous base de données (deadlocks) ; gestion sans faille des transactions Doctrine.

#### 2. Audit de Sécurité Automatisé (OWASP Top 10)
- [ ] **Injections SQL & NoSQL :** Fuzzing systématique des paramètres de tri et de filtrage (`order`, `where`, `filter`).
- [ ] **Cross-Site Scripting (XSS) :** Assainissement des champs `rich_text` et des valeurs affichées dans le Studio.
- [ ] **En-têtes de Sécurité HTTP :** Présence systématique de `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, et politique `Content-Security-Policy` adaptée.
- [ ] **Rate Limiting :** Vérification de l'application stricte des quotas sur les routes d'authentification (`/login`, `/oauth/token`).

---

### Phase T10 (v1.26.0) : Pipeline CI/CD & Smoke Tests Production

* **Tag Git Associé :** `v1.26.0` (Mineure de consolidation)
* **Commande de Push :** `git tag -a v1.26.0 -m "release: v1.26.0 - Automated CI/CD pipeline & live production verification suite" && git push origin v1.26.0`
* **Quality Gate :** Pipeline GitHub Actions 100% vert sur PHP 8.4 + MySQL 8.0, smoke tests réussis sur `https://api.jambostack.site`.

**Objectif :** Automatiser les vérifications à chaque commit et disposer d'un scénario de recette immédiat après déploiement.

#### 1. Workflow GitHub Actions (`.github/workflows/ci.yml`)
```yaml
name: Jambo API Quality & Test Suite

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]

jobs:
  backend-tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: jamboapicms_test
          MYSQL_ROOT_PASSWORD: root
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=5s --health-timeout=2s --health-retries=3

    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP 8.4
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, xml, ctype, iconv, intl, pdo_mysql, zip, gd
          coverage: none

      - name: Install Composer Dependencies
        run: composer install --prefer-dist --no-progress -q

      - name: Validate Doctrine Schema
        run: php bin/console doctrine:schema:validate --skip-sync

      - name: Run PHPUnit Test Suite
        run: vendor/bin/phpunit --no-progress
```

#### 2. Smoke Tests de Recette Post-Déploiement (Production)
Script exécutable à chaque livraison sur `api.jambostack.site` :
- Vérification du code HTTP 200 sur la racine et le Swagger `/api/docs`.
- Authentification PAT sur `GET /admin-api/_ping`.
- Requête test sur la collection publique de démonstration.
- Vérification de l'état de la file Messenger (`bin/console messenger:failed:show`).

---

## 6. Cahier de Recette Exécutable & Commandes

### 6.1 Commandes d'Exécution Rapide (Développeur)

```bash
# 1. Lancer uniquement les tests unitaires (rapides, sans base)
vendor/bin/phpunit tests/Entity tests/Enum tests/Service/Flow --no-progress

# 2. Lancer les tests d'authentification et de sécurité
vendor/bin/phpunit tests/Security tests/Controller/AdminApiSecurityTest.php --no-progress

# 3. Lancer la validation complète des commandes CLI
vendor/bin/phpunit tests/Command --no-progress

# 4. Lancer les tests E2E Playwright
npm run test:e2e
```

### 6.2 Scénarios cURL de Validation Production

```bash
# 1. Vérification de la disponibilité et version
curl -s -i https://api.jambostack.site/ | grep "HTTP/2 200"

# 2. Test du Ping Admin API avec Personal Access Token
curl -s -X GET https://api.jambostack.site/admin-api/_ping \
  -H "Authorization: Bearer jbo_pat_YOUR_SECRET_TOKEN" \
  -H "Accept: application/json"

# 3. Test de récupération de contenu par Slug
curl -s -X GET "https://api.jambostack.site/api/v1/projects/YOUR_PROJECT_ID/collections/articles/content/by-slug/bienvenue" \
  -H "Accept: application/json"
```

### 6.3 Procédure Standard de Tagging & Push Git (Cycle v1.*.*)

Pour chaque phase de test complétée, le processus de versionnement et de livraison doit suivre ce protocole strict :

```bash
# Étape 1 : S'assurer que le working tree est 100% propre
git status --short

# Étape 2 : Exécuter la suite de tests requise par la phase
vendor/bin/phpunit --no-progress

# Étape 3 : Créer le tag Git annoté correspondant strictement au cycle v1.*.*
# Exemple pour la Phase T1 :
git tag -a v1.20.1 -m "release: v1.20.1 - EAV slug uniqueness & partial PATCH validation fixes"

# Étape 4 : Pousser les commits et le nouveau tag sur le dépôt distant GitHub
git push origin main
git push origin v1.20.1

# Étape 5 : Répercuter sur le serveur de production (o2switch)
ssh gupi7723@folina.o2switch.net "cd ~/api.jambostack.site && git pull origin main && git checkout v1.20.1"
```

---

## 7. Tableau de Bord & Critères de Validation

| Domaine | Tag Cible | Critère de Succès (Go / No-Go) | Statut Actuel |
| :--- | :---: | :--- | :---: |
| **Moteur Slugs & EAV** | `v1.20.1` | Aucune collision de slug, gestion transparente des soft-deletes | ✅ Validé en prod |
| **Sécurité PAT & RBAC** | `v1.21.0` | Authentification Admin fonctionnelle, hash sécurisé, révocation immédiate | ✅ Validé en prod |
| **Schéma OpenAPI / REST** | `v1.21.1` | Routes `/admin-api/` documentées et testables dans Swagger UI | ✅ Validé en prod |
| **GraphQL & Mercure** | `v1.22.0` | Limitation de profondeur active, zéro N+1, broadcast temps réel | ✅ Validé en prod |
| **Stockage S3 / R2** | `v1.23.0` | Cycle complet multipart direct upload, PublishedSiteStorage & SVG | ✅ Validé en prod |
| **Moteur Flow & IA SSE** | `v1.24.0` | Validation DAG sans boucles infinies, flux SSE continu sans leak | ✅ Validé en prod |
| **Formulaires & Blueprints**| `v1.24.1` | Intégrité stricte des sauvegardes ZIP et anti-spam opérationnel | ✅ Validé en prod |
| **Playwright E2E Studio** | `v1.25.0` | 100% tests Playwright passants (API, auth, dashboard) | ✅ Validé en prod |
| **Performance & OWASP** | `v1.25.1` | Bac à sable Twig sécurisé, zéro faille OWASP critique | ✅ Validé en prod |
| **CI/CD & Déploiement** | `v1.26.0` | Pipeline CI automatisé, smoke tests live 100% réussis sur o2switch | ✅ Validé en prod |

---
*Document conçu et maintenu par l'équipe JamboStack.*
