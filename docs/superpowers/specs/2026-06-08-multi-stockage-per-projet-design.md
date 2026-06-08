# Jambo — Multi-stockage par projet — Design Spec

> Date : 2026-06-08
> Statut : à valider
> Dépend de : SMTP Mailer (implémenté), JWT TTL (implémenté)
> Approche : B — Flysystem + StorageManager natif

## Objectif

Remplacer le stockage local unique (`public`) par un système multi-stockage configurable par projet. Chaque projet peut définir N profils de stockage (local, S3, S3-compatible — R2, MinIO, DigitalOcean Spaces, Scaleway…) et choisir une stratégie d'affectation : stockage unique, mirroring sur tous les profils actifs, ou règles par type de fichier. Les credentials sont chiffrés par projet (même mécanisme que SMTP).

---

## 1. Architecture

```
┌──────────────────────────────────────────────────────────┐
│  Front (React/Inertia)                                    │
│  Settings > Storage : stratégie, profils, règles          │
│  Asset Upload : storage_profile_id optionnel              │
└──────────────────────────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────────┐
│  Jambo (Symfony 8)                                        │
│                                                           │
│  StorageManager                                           │
│    ├─ Résout stratégie (default_only|mirror_all|rules)    │
│    ├─ Orchestre l'écriture multi-filesystem               │
│    └─ Expose getUrl() / read() / delete()                 │
│                                                           │
│  StorageDriverFactory                                     │
│    ├─ Crée Flysystem LocalAdapter (local)                 │
│    └─ Crée Flysystem AwsS3V3Adapter (s3/s3-compatible)    │
│                                                           │
│  VichUploaderBundle (conservé)                            │
│    └─ File bag uniquement : UploadedFile → Media entity   │
│                                                           │
│  league/flysystem-bundle (nouveau)                        │
│    └─ Abstraction filesystem standard PHP                 │
└──────────────────────────────────────────────────────────┘
```

### Flux d'upload

```
1. POST /api/admin/projects/{uuid}/assets + file + ?storage_profile_id
2. VichUploader → attache File → Media (fileName, fileSize, mimeType)
3. Media flush → UUID disponible
4. StorageManager::write() →
   a. Résout filesystems (stratégie ou profil explicite)
   b. Écrit le stream sur chaque filesystem cible
   c. Retourne {profile_uuid: path, ...}
5. Media::storagePaths = paths, Media::storageProfile = primary
6. Flush final
```

---

## 2. Modèle de données

### 2.1 Nouvelle entity : `ProjectStorageProfile`

| Champ | Type | Description |
|---|---|---|
| `id` | int (PK) | |
| `uuid` | uuid | |
| `project` | ManyToOne→Project | |
| `name` | string(100) | Label admin (ex: "AWS S3 Paris") |
| `driver` | string(20) | Enum: `local`, `s3` |
| `priority` | int | Ordre de fallback / affichage |
| `enabled` | bool | Activé / désactivé |
| `is_default` | bool | Profil utilisé quand strategy = default_only |
| `s3_key` | string(255) nullable | Access key (champ en clair) |
| `s3_secret` | text nullable | Secret key (chiffré sodium, comme SMTP password) |
| `s3_region` | string(50) nullable | ex: `eu-west-3` |
| `s3_bucket` | string(255) nullable | |
| `s3_endpoint` | string(255) nullable | Pour S3-compatible (R2, MinIO, …). Null = AWS default |
| `s3_use_path_style` | bool | Pour MinIO et certains S3-compatibles |
| `base_url` | string(255) nullable | CDN ou custom domain. Si null → URL signée S3 |
| `root_path` | string(255) nullable | Pour driver local, override du path |
| `created_at` | DateTimeImmutable | |
| `updated_at` | DateTimeImmutable | |

**Règle métier** : `is_default` est unique par projet (un seul profil par défaut à la fois).

### 2.2 Nouvelle entity : `StorageRule`

Utilisée uniquement si `Project::storageStrategy = rules`.

| Champ | Type | Description |
|---|---|---|
| `id` | int (PK) | |
| `project` | ManyToOne→Project | |
| `storage_profile` | ManyToOne→ProjectStorageProfile | Profil cible |
| `mime_type_pattern` | string(100) nullable | Glob : `image/*`, `video/*`, `application/pdf` |
| `extension` | string(20) nullable | ex: `pdf`, `mp4`, `jpg` |
| `max_size` | int nullable | En bytes, seuil max pour cette règle |
| `priority` | int | Ordre d'évaluation |

### 2.3 Modifications entity `Project`

| Champ | Type | Description |
|---|---|---|
| `storageStrategy` | string(20) | Enum: `default_only` (défaut), `mirror_all`, `rules` |
| `storageProfiles` | OneToMany→ProjectStorageProfile | |
| `storageRules` | OneToMany→StorageRule | |

**Conservé pour rétrocompatibilité** : la colonne `disk` est rendue `nullable`, et le getter `getDisk()` est marqué `@deprecated`. Il retourne `'public'` si le profil par défaut est local, `'s3'` si S3. Les contrôleurs existants qui lisent `$project->disk` continuent de fonctionner.

### 2.4 Modifications entity `Media`

| Champ | Type | Description |
|---|---|---|
| `storageProfile` | ManyToOne→ProjectStorageProfile nullable | Profil principal (premier réussi à l'upload) |
| `storagePaths` | json nullable | Mapping `{"<profile_uuid>": "<relative_path>", …}`. Ex: `{"a1b2c3": "projects/f99cb038/photo.jpg"}` |

**Suppression** : `getPublicUrl()` hardcodé → délègue à `StorageManager::getUrl()`.

---

## 3. Couche service

### 3.1 `StorageManager`

Point d'entrée unique pour toute opération de stockage.

```php
class StorageManager
{
    public function __construct(
        private Project $project,
        private ProjectStorageProfileRepository $profiles,
        private StorageDriverFactory $factory,
    ) {}

    /** @return array<string, FilesystemOperator> */
    public function getFilesystems(array $options = []): array;

    /** @return array<string, string> profile_uuid => path */
    public function write(string $path, mixed $stream, array $options = []): array;

    public function read(string $path): mixed;
    public function delete(string $path): void;
    public function getUrl(string $path, ?string $profileUuid = null): string;
}
```

### 3.2 Résolution — logique par stratégie

| Stratégie | `getFilesystems()` |
|---|---|
| `default_only` | Le profil `is_default=true` uniquement |
| `mirror_all` | Tous les profils `enabled=true`, ordonnés par `priority` |
| `rules` | Profil matché par StorageRule (mime/extension/taille), fallback `is_default` |
| Upload explicite | Le `profile_uuid` passé en paramètre HTTP (override) |

### 3.3 `StorageDriverFactory`

Crée l'adapter Flysystem pour chaque profil :

```php
class StorageDriverFactory
{
    // Construit le filesystem à partir d'un profil
    public function create(ProjectStorageProfile $profile): FilesystemOperator;

    // Déchiffre le s3_secret avec sodium (identique au SMTP password)
    public function __construct(private string $appSecret) {}
}
```

**Local** → `new LocalFilesystemAdapter($profile->rootPath ?? defaultLocalPath)`  
**S3** → `new AwsS3V3Adapter($s3Client, $profile->s3Bucket)` avec :
- `endpoint` personnalisé si fourni (R2, MinIO, DigitalOcean)
- `use_path_style_endpoint` si activé (MinIO)
- `region` depuis le profil

### 3.4 Gestion des URLs

- **Local** : URL relative `/uploads/media/…`
- **S3 sans base_url** : URL signée temporaire (1h) via `S3Client::getObjectUrl()` + `PreSignedUrl`
- **S3 avec base_url** : URL directe CDN `{base_url}/{path}` sans signature

---

## 4. Interface utilisateur

### 4.1 Nouvel onglet « Stockage » dans Settings

Ajouté dans la sidebar `ProjectSettingsLayout` entre « Projet » et « Localisation ».

### 4.2 Page Storage.tsx

```
┌──────────────────────────────────────────────────┐
│  Stockage                                [ + ]   │
│  Configurer où et comment les fichiers sont      │
│  stockés                                         │
├──────────────────────────────────────────────────┤
│                                                  │
│  Stratégie                                       │
│  ○ Stockage unique (défaut)                      │
│  ○ Mirroring (tous les stockages actifs)          │
│  ○ Règles par type de fichier                    │
│                                                  │
│  ── Profils ─────────────────────────────        │
│  [Liste des profils avec nom, driver, statut]    │
│  Chaque profil : bouton éditer / supprimer       │
│  [+ Ajouter un profil]                           │
│                                                  │
│  ── Règles ──────────────────────────────        │
│  (visible si stratégie = règles)                 │
│  [Liste des règles avec pattern, cible]          │
│  [+ Ajouter une règle]                           │
│                                                  │
│  [Enregistrer]                                   │
└──────────────────────────────────────────────────┘
```

### 4.3 Modal StorageProfileForm.tsx

- Champ : nom, driver (select local/s3-compatible)
- Si S3 : access key, secret key (champ password, laisser vide = conserver), region, bucket, endpoint optionnel, path-style checkbox, CDN URL optionnelle
- Si local : root path optionnel
- Checkbox « Définir comme stockage par défaut »

### 4.4 Modal StorageRuleForm.tsx

- Sélecteur de profil cible
- Pattern MIME (select + custom : `image/*`, `video/*`, `application/pdf`, custom)
- Extension (optionnel, ex: `pdf`)
- Taille max (optionnel)

### 4.5 Routes API

| Méthode | Route | Description |
|---|---|---|
| `GET` | `/api/admin/projects/{uuid}/storage` | Récupérer config (stratégie, profils, règles) |
| `PUT` | `/api/admin/projects/{uuid}/storage` | Mettre à jour la stratégie |
| `POST` | `/api/admin/projects/{uuid}/storage/profiles` | Créer un profil |
| `PUT` | `/api/admin/projects/{uuid}/storage/profiles/{id}` | Modifier un profil |
| `DELETE` | `/api/admin/projects/{uuid}/storage/profiles/{id}` | Supprimer un profil (bloqué si c'est le seul profil du projet) |
| `POST` | `/api/admin/projects/{uuid}/storage/rules` | Créer une règle |
| `PUT` | `/api/admin/projects/{uuid}/storage/rules/{id}` | Modifier une règle |
| `DELETE` | `/api/admin/projects/{uuid}/storage/rules/{id}` | Supprimer une règle |

---

## 5. Infrastructure

### 5.1 Packages

```json
{
    "league/flysystem-bundle": "^3.0",
    "league/flysystem-aws-s3-v3": "^3.0"
}
```

- `league/flysystem-bundle` : intégration Symfony native
- `league/flysystem-aws-s3-v3` : adaptateur S3 via AWS SDK (inclus `aws/aws-sdk-php`)
- **Pas besoin** d'installer `aws/aws-sdk-php` séparément, c'est une dépendance transitive

### 5.2 Configuration `flysystem.yaml` minimale

```yaml
flysystem:
    storages:
        # Les storages sont créés dynamiquement par StorageDriverFactory.
        # La config YAML est gardée vide — tout passe par le code.
```

### 5.3 Services déclarés dans `services.yaml`

```yaml
App\Service\StorageManager:
    arguments:
        $project: ~           # Injecté à la volée via setProject()

App\Service\StorageDriverFactory:
    arguments:
        $appSecret: '%kernel.secret%'

App\Service\MediaSerializer:
    arguments:
        $storageManager: '@App\Service\StorageManager'
```

---

## 6. Migration

### 6.1 Migration Doctrine

1. Création table `project_storage_profile`
2. Création table `storage_rule`
3. Ajout `storage_strategy VARCHAR(20) DEFAULT 'default_only'` sur `project`
4. Ajout `storage_profile_id INT NULL` (FK) sur `media`
5. Ajout `storage_paths JSON NULL` sur `media`
6. **Colonne `disk` sur `project` rendue nullable** (conservée pour rétrocompatibilité, marquée `@deprecated`)

### 6.2 Script de migration des données (dans le `up()` de la migration)

```sql
-- 1 profil local hérité par projet existant
INSERT INTO project_storage_profile
    (project_id, uuid, name, driver, priority, enabled, is_default, root_path, created_at, updated_at)
SELECT
    id, UUID(), 'Local (hérité)', 'local', 0, 1, 1,
    CONCAT('%kernel.project_dir%/public/uploads/media/', id),
    NOW(), NOW()
FROM project;

-- Associer les médias existants au profil local créé
UPDATE media m
JOIN project_storage_profile p ON p.project_id = m.project_id
SET m.storage_profile_id = p.id,
    m.storage_paths = JSON_OBJECT(p.uuid, CONCAT('projects/', m.project_id, '/', m.file_name));
```

### 6.3 Rétrocompatibilité

| Composant | Action |
|---|---|
| `Project::$disk` | Getter déprécié conservé, mapping vers nouveau système |
| `Media::getPublicUrl()` | Délègue à `StorageManager::getUrl()` |
| `MediaSerializer::serialize()` | `disk` → lit le profil réel, `url` → StorageManager |
| `ImageTransformService` | Lit le stream via Flysystem au lieu du système de fichiers direct. Cache local inchangé. |
| `VichUploader` | Mapping `media_files` conservé (file bag uniquement) |
| `ProjectDirNamer` | Inchangé |
| Upload existant (`AssetController`) | Adapté : appelle `StorageManager::write()` après VichUploader |

---

## 7. Considérations de sécurité

- **Credentials S3 chiffrés** avec le même mécanisme que SMTP (`sodium_crypto_secretbox`, clé = `APP_SECRET`)
- **Validation backend** : seuls `local` et `s3` (S3-compatible) sont acceptés comme driver
- **Pas de secrets en clair dans l'API** : `GET /storage` ne retourne jamais le `s3_secret` déchiffré
- **Password "laisser vide pour conserver"** : même UX que le SMTP — si le champ secret est vide au PUT, on garde l'ancien
- **Suppression de profil protégée** : on ne peut pas supprimer le dernier profil d'un projet
- **Un seul `is_default` par projet** : contrainte d'unicité au niveau applicatif

---

## 8. Fichiers concernés

### Nouveaux

```
src/Entity/ProjectStorageProfile.php
src/Entity/StorageRule.php
src/Repository/ProjectStorageProfileRepository.php
src/Repository/StorageRuleRepository.php
src/Service/StorageManager.php
src/Service/StorageDriverFactory.php
src/Controller/Admin/ProjectStorageController.php
assets/js/pages/Projects/Settings/Storage.tsx
assets/js/pages/Projects/Settings/StorageProfileForm.tsx
assets/js/pages/Projects/Settings/StorageRuleForm.tsx
migrations/Version20260608MultiStorage.php
```

### Modifiés

```
src/Entity/Project.php
src/Entity/Media.php
src/Service/MediaSerializer.php
src/Service/ImageTransformService.php
src/Controller/Api/AssetController.php
src/Controller/PageController.php
config/services.yaml
config/packages/flysystem.yaml
assets/js/pages/Projects/Settings/layout.tsx
assets/js/types/project.d.ts
translations/messages.fr.php
translations/messages.en.php
translations/messages.es.php
translations/messages.ar.php
composer.json
```

---

## 9. Non inclus dans cette version

- Migration asynchrone des fichiers entre stockages (Messenger)
- Stockage additionnel : FTP, Google Cloud Storage, Azure Blob (l'architecture le permet via Flysystem, mais pas d'UI)
- Cache d'image distribué (reste local pour l'instant)
- Statistiques de stockage par profil (espace utilisé, nombre de fichiers)
