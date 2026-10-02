# 🗺️ Jambo API — Feuille de Route & Spécifications des Nouvelles Fonctionnalités

Ce document définit la feuille de route technique de **Jambo API** pour la version **v1.20.0 (cycle v1.x)**, intégrant les fonctionnalités clés identifiées lors de l'audit architectural comparatif avec ElmAPI CMS v4.0.0 ainsi que les besoins d'évolution vers une plateforme Headless & Composable CMS d'envergure entreprise.

---

## 📊 Matrice d'Avancement Global

| Phase | Domaine | Fonctionnalité Clé | Priorité | Statut | Cible |
| :--- | :--- | :--- | :---: | :---: | :---: |
| **Phase 1** | Stockage | Upload Direct S3/R2 Multipart Pré-signé | 🔴 Haute | ✅ Implémenté | v1.20.0 |
| **Phase 2** | Modèle EAV | Groupes de Champs Relationnels (`ContentFieldGroup`) | 🔴 Haute | ✅ Implémenté | v1.20.0 |
| **Phase 3** | Sécurité / Auth | Serveur OAuth2 Client complet par projet (PKCE) | 🟡 Moyenne | ✅ Implémenté | v1.20.0 |
| **Phase 4** | IA / Studio | Assistant IA Streaming SSE inline dans l'éditeur | 🟡 Moyenne | ✅ Implémenté | v1.20.0 |
| **Phase 5** | Écosystème | Pack de 11 Starters Frontend & Web Setup Wizard | 🟢 Gain rapide | ✅ Implémenté | v1.20.0 |

---

## 🚀 Phase 1 : Stockage & Upload Direct S3 Multipart Pré-signé (v1.10.0)

### Objectif
Actuellement, le protocole TUS (`TusServer.php`) fait transiter l'ensemble des flux de téléversement par le disque temporaire du serveur (`var/tus/`), consommant inutilement de la bande passante et des ressources PHP. L'objectif est d'offrir un mode **Upload Direct Client-to-Storage** via AWS S3 / Cloudflare R2 / MinIO.

### Spécifications Techniques

1. **Nouvelle entité Doctrine `AssetUploadIntent` :**
   * Emplacement : `src/Entity/AssetUploadIntent.php`
   * Propriétés : `id`, `uuid`, `project` (ManyToOne), `storageKey`, `originalFilename`, `clientMimeType`, `maxBytes`, `mode` (`single_put` | `multipart`), `s3MultipartUploadId`, `expiresAt`, `createdAt`.
   * Repository : `src/Repository/AssetUploadIntentRepository.php`.

2. **Service métier `DirectUploadService` :**
   * Emplacement : `src/Service/DirectUploadService.php`
   * Intégration avec `Aws\S3\S3Client` configuré via `ProjectStorageProfile`.
   * Méthodes :
     * `createIntent(Project $project, array $payload): AssetUploadIntent`
     * `generatePresignedPutUrl(AssetUploadIntent $intent): string`
     * `initiateMultipart(AssetUploadIntent $intent): array`
     * `signPartUrl(AssetUploadIntent $intent, int $partNumber): string`
     * `completeMultipart(AssetUploadIntent $intent, array $parts): Media`
     * `abortMultipart(AssetUploadIntent $intent): void`

3. **Endpoints API REST :**
   * `POST /api/v1/projects/{projectId}/media/direct-upload/intent`
   * `POST /api/v1/projects/{projectId}/media/direct-upload/multipart/sign-part`
   * `POST /api/v1/projects/{projectId}/media/direct-upload/complete`
   * `POST /api/v1/projects/{projectId}/media/direct-upload/abort`

4. **Frontend Studio :**
   * Évolution de `assets/js/pages/Media/Uploader.tsx` : détection automatique du type de stockage et aiguillage vers l'upload direct S3 fragmenté avec barre de progression côté client.

---

## 🧩 Phase 2 : Modélisation Relationnelle Avancée (`ContentFieldGroup`) (v1.10.0)

### Objectif
Actuellement, les répétiteurs de blocs et sous-champs sont persistés sous forme de blob JSON dans `ContentFieldValue::$jsonValue`. Ce stockage limite les filtres complexes, l'indexation SQL et les contraintes d'intégrité référentielle.

### Spécifications Techniques

1. **Nouvelle entité `ContentFieldGroup` :**
   * Emplacement : `src/Entity/ContentFieldGroup.php`
   * Propriétés :
     * `id`, `uuid`
     * `project` (ManyToOne)
     * `collection` (ManyToOne)
     * `contentEntry` (ManyToOne `ContentEntry`, inversedBy `fieldGroups`)
     * `field` (ManyToOne `Field`, le champ parent de type repeater/group)
     * `sortOrder` (int)
     * `values` (OneToMany vers `ContentFieldValue`)

2. **Évolution de `ContentFieldValue` :**
   * Ajout de `groupInstance` (ManyToOne vers `ContentFieldGroup`, nullable).
   * Migration Doctrine pour ajouter `group_instance_id` et index de performance `IDX_cfv_group_instance`.

3. **Évolution du Moteur GraphQL & REST :**
   * Mise à jour de `src/GraphQL/SchemaGenerator.php` pour générer automatiquement les sous-types GraphQL typés pour chaque groupe.
   * Mise à jour de `src/Service/ContentEntryService.php` pour hydrater et valider unitairement chaque sous-champ via le Validateur Symfony.

4. **Composants React :**
   * Refonte de `assets/js/pages/Content/Fields/RepeaterField.tsx` pour gérer l'édition réordonnable avec synchronisation transactionnelle des instances de groupes.

---

## 🔐 Phase 3 : Serveur OAuth2 Client Dédié par Projet (v1.11.0)

### Objectif
Permettre à chaque projet Jambo API de faire office de fournisseur d'identité OAuth2 complet (RFC 6749 + PKCE) pour des applications web tierces, applications mobiles ou microservices.

### Spécifications Techniques

1. **Entités d'autorisation :**
   * `ProjectAuthClient` : `id`, `project`, `name`, `clientId`, `clientSecretHash`, `redirectUris` (json), `allowedScopes` (json), `isConfidential` (bool), `isActive` (bool).
   * `ProjectAuthAuthorizationCode` : `code`, `client`, `endUser`, `redirectUri`, `codeChallenge`, `codeChallengeMethod`, `expiresAt`, `usedAt`.
   * `ProjectAuthRefreshToken` : `tokenHash`, `client`, `endUser`, `expiresAt`, `isRevoked`.
   * `ProjectAuthSession` : `sessionId`, `endUser`, `ipAddress`, `userAgent`, `lastActiveAt`.

2. **Sécurité & Protection Anti-Bruteforce :**
   * Écouteur d'événements `ProjectAuthSecuritySubscriber.php` : verrouillage automatique (lockout temporaire de 15 minutes) après 5 échecs consécutifs d'authentification par IP/utilisateur.
   * Dashboard d'audit des sessions actives avec révocation unitaire ou globale.

3. **Endpoints du Fournisseur OAuth2 :**
   * `GET  /api/v1/projects/{projectId}/oauth/authorize` (écran de consentement / prompt)
   * `POST /api/v1/projects/{projectId}/oauth/token` (échange code -> token + refresh_token + support PKCE)
   * `GET  /api/v1/projects/{projectId}/oauth/userinfo` (profil utilisateur standardisé)
   * `POST /api/v1/projects/{projectId}/oauth/revoke` (révocation de token)

---

## 🤖 Phase 4 : Assistant IA Inline en Streaming SSE (v1.11.0)

### Objectif
Compléter le serveur MCP natif (`JamboApiMcpServer.php`) par une expérience utilisateur d'édition assistée en temps réel directement intégrée au cœur des champs de saisie du Studio.

### Spécifications Techniques

1. **Contrôleur Streaming SSE :**
   * Emplacement : `src/Controller/Admin/ContentAiStreamController.php`
   * Route : `POST /admin/api/content-ai/stream`
   * Réponse : `Symfony\Component\HttpFoundation\StreamedResponse` avec en-têtes `text/event-stream`.
   * Actions supportées :
     * `summarize` : Résumé automatique d'un texte long.
     * `expand` : Développement d'un paragraphe ou d'une ébauche.
     * `rewrite` : Reformulation selon un ton donné (professionnel, décontracté, persuasif).
     * `fix_grammar` : Correction orthographique et stylistique.
     * `translate` : Traduction contextuelle vers les langues cibles du projet.

2. **Interface Utilisateur Studio :**
   * Composant React `FloatingAiToolbar.tsx` injecté au-dessus des champs `rich_text`, `markdown` et `text`.
   * Affichage du texte généré au fil de l'eau avec boutons "Appliquer", "Régénérer" ou "Annuler".

---

## 📦 Phase 5 : Starters Frontend & Web Setup Wizard (v1.12.0)

### Objectif
Accélérer le démarrage des développeurs et simplifier le déploiement sur tout type d'environnement (y compris mutualisé).

### Spécifications Techniques

1. **Kits de Démarrage Frontend (11 templates) :**
   * Adaptation du catalogue de templates (Astro, Next.js 15, Nuxt 3) pour consommer directement le SDK TypeScript et l'API GraphQL de Jambo API.
   * Commande CLI : `bin/console jambo:template:import <template-slug>` pour importer instantanément schémas et données d'exemple.

2. **Web Setup Wizard (`/install`) :**
   * Contrôleur autonome `InstallController.php` actif uniquement en l'absence de configuration DB valide.
   * Vérification des prérequis PHP (extensions, permissions d'écriture, memory_limit).
   * Assistant de connexion base de données, exécution des migrations Doctrine et création du premier compte super-administrateur.

3. **Export / Import de Blueprints :**
   * Commande `bin/console jambo:blueprint:export [project-slug]` -> exporte un package `.jambo.json` ou archive `.zip` contenant schémas, validations et profils de stockage.

---

## 📌 Synthèse & Dépendances

```
┌─────────────────────────────────────────────────────────┐
│                    Jambo API v1.9.5                     │
│  (REST + GraphQL + MCP Server + Meilisearch + Mercure)  │
└───────────────────────────┬─────────────────────────────┘
                            │
        ┌───────────────────┴───────────────────┐
        ▼                                       ▼
┌──────────────────────────────┐      ┌──────────────────────────────┐
│  Phase 1 : S3 Direct Upload  │      │ Phase 2 : ContentFieldGroup  │
│  (v1.10 - Priorité Haute)    │      │ (v1.10 - Priorité Haute)     │
└───────────────┬──────────────┘      └──────────────┬───────────────┘
                │                                    │
                └───────────────────┬────────────────┘
                                    ▼
┌─────────────────────────────────────────────────────────────┐
│ Phase 3 : OAuth2 IdP + Phase 4 : Inline AI Streaming (v1.11) │
└─────────────────────────────┬───────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ Phase 5 : 11 Frontend Starters + Web Installer (v1.12)      │
└─────────────────────────────────────────────────────────────┘
```
