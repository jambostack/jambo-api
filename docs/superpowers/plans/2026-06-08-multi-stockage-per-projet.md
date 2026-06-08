# Multi-stockage par projet — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer le stockage local unique par un système multi-stockage configurable par projet (local, S3, S3-compatible) avec 3 stratégies (default_only, mirror_all, rules).

**Architecture:** Flysystem + StorageManager natif (Approche B). VichUploader conservé comme file bag. league/flysystem-bundle + aws-s3-v3 pour les adaptateurs. Credentials S3 chiffrés sodium comme le SMTP. Migration auto : 1 profil local hérité par projet existant.

**Tech Stack:** PHP 8.4, Symfony 8, Doctrine ORM, Flysystem 3, React 19/Inertia, TypeScript, Tailwind 4

---

## Structure des fichiers

### Créés
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
config/packages/flysystem.yaml
```

### Modifiés
```
composer.json
src/Entity/Project.php
src/Entity/Media.php
src/Service/MediaSerializer.php
src/Service/ImageTransformService.php
src/Controller/Api/AssetController.php
src/Controller/PageController.php
src/Controller/ProjectController.php
config/services.yaml
assets/js/pages/Projects/Settings/layout.tsx
assets/js/types/project.d.ts
translations/messages.fr.php
translations/messages.en.php
translations/messages.es.php
translations/messages.ar.php
```

---

### Task 1: Installer les packages Flysystem

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Ajouter les dépendances Composer**

```bash
cd c:/laragon/www/jamboapicms
composer require league/flysystem-bundle:^3.0 league/flysystem-aws-s3-v3:^3.0
```

Run: `composer require league/flysystem-bundle:^3.0 league/flysystem-aws-s3-v3:^3.0`
Expected: packages installés, `composer.json` et `composer.lock` mis à jour

- [ ] **Step 2: Enregistrer le bundle Flysystem**

Vérifier que `League\FlysystemBundle\FlysystemBundle` est ajouté dans `config/bundles.php`. Normalement fait automatiquement par Symfony Flex.

Run: `php bin/console debug:config flysystem`
Expected: la config flysystem par défaut s'affiche (peut être vide)

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock config/bundles.php
git commit -m "feat(storage): ajouter league/flysystem-bundle + aws-s3-v3"
```

---

### Task 2: Config Flysystem (optionnel)

**Files:**
- Create: `config/packages/flysystem.yaml`

- [ ] **Step 1: Écrire la config minimale (si le bundle l'exige)**

```yaml
# config/packages/flysystem.yaml
# Aucun storage défini ici — tous sont créés dynamiquement par StorageDriverFactory.
# Ce fichier peut être vide. Il existe uniquement pour que le bundle s'active.
```

Run: `php bin/console cache:clear`
Expected: pas d'erreur

> **Note:** Si le bundle s'active sans fichier de config (Flex détecte le package), ce fichier n'est pas nécessaire. Passer directement au commit si `cache:clear` réussit sans.

- [ ] **Step 2: Commit**

```bash
git add config/packages/flysystem.yaml
git commit -m "feat(storage): config minimale flysystem"
```

---

### Task 3: Créer l'entity ProjectStorageProfile

**Files:**
- Create: `src/Entity/ProjectStorageProfile.php`
- Create: `src/Repository/ProjectStorageProfileRepository.php`

- [ ] **Step 1: Écrire l'entity**

```php
<?php
// src/Entity/ProjectStorageProfile.php

namespace App\Entity;

use App\Repository\ProjectStorageProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProjectStorageProfileRepository::class)]
#[ORM\Table(name: 'project_storage_profile')]
#[ORM\HasLifecycleCallbacks]
class ProjectStorageProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'storageProfiles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 100)]
    public string $name = '';

    #[ORM\Column(length: 20)]
    public string $driver = 'local'; // 'local' | 's3'

    #[ORM\Column]
    public int $priority = 0;

    #[ORM\Column]
    public bool $enabled = true;

    #[ORM\Column]
    public bool $isDefault = false;

    // ── S3 fields (nullable, utilisés seulement si driver = s3) ──

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $s3Key = null;

    /** Chiffré avec APP_SECRET (sodium secretbox) — comme le SMTP password. */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $s3Secret = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $s3Region = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $s3Bucket = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $s3Endpoint = null;

    #[ORM\Column]
    public bool $s3UsePathStyle = false;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $baseUrl = null;

    // ── Local fields (nullable) ──

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $rootPath = null;

    // ── Timestamps ──

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Ne jamais retourner le secret en clair dans l'API. */
    public function toArray(): array
    {
        return [
            'uuid'             => $this->uuid?->toRfc4122(),
            'name'             => $this->name,
            'driver'           => $this->driver,
            'priority'         => $this->priority,
            'enabled'          => $this->enabled,
            'is_default'       => $this->isDefault,
            's3_key'           => $this->s3Key,
            // s3_secret JAMAIS retourné
            's3_region'        => $this->s3Region,
            's3_bucket'        => $this->s3Bucket,
            's3_endpoint'      => $this->s3Endpoint,
            's3_use_path_style' => $this->s3UsePathStyle,
            'base_url'          => $this->baseUrl,
            'root_path'         => $this->rootPath,
            'created_at'       => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'       => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

- [ ] **Step 2: Écrire le repository**

```php
<?php
// src/Repository/ProjectStorageProfileRepository.php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectStorageProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectStorageProfile::class);
    }

    /** @return ProjectStorageProfile[] */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['priority' => 'ASC']);
    }

    /** Profil par défaut du projet, ou null. */
    public function findDefault(Project $project): ?ProjectStorageProfile
    {
        return $this->findOneBy(['project' => $project, 'isDefault' => true]);
    }

    /** Profils actifs du projet, ordonnés par priorité. */
    public function findActive(Project $project): array
    {
        return $this->findBy(['project' => $project, 'enabled' => true], ['priority' => 'ASC']);
    }

    /** Nombre de profils pour un projet. */
    public function countByProject(Project $project): int
    {
        return $this->count(['project' => $project]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Entity/ProjectStorageProfile.php src/Repository/ProjectStorageProfileRepository.php
git commit -m "feat(storage): entity ProjectStorageProfile + repository"
```

---

### Task 4: Créer l'entity StorageRule

**Files:**
- Create: `src/Entity/StorageRule.php`
- Create: `src/Repository/StorageRuleRepository.php`

- [ ] **Step 1: Écrire l'entity**

```php
<?php
// src/Entity/StorageRule.php

namespace App\Entity;

use App\Repository\StorageRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StorageRuleRepository::class)]
#[ORM\Table(name: 'storage_rule')]
class StorageRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'storageRules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: ProjectStorageProfile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ProjectStorageProfile $storageProfile;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $mimeTypePattern = null; // ex: "image/*", "video/*"

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $extension = null; // ex: "pdf", "mp4"

    #[ORM\Column(nullable: true)]
    public ?int $maxSize = null; // bytes

    #[ORM\Column]
    public int $priority = 0;

    /** Vérifie si cette règle matche un fichier donné. */
    public function matches(string $mimeType, string $filename, int $size): bool
    {
        // Vérifier le pattern MIME
        if ($this->mimeTypePattern !== null) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($this->mimeTypePattern, '#')) . '$#';
            if (!preg_match($regex, $mimeType)) {
                return false;
            }
        }

        // Vérifier l'extension
        if ($this->extension !== null) {
            $actualExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($actualExt !== strtolower($this->extension)) {
                return false;
            }
        }

        // Vérifier la taille max
        if ($this->maxSize !== null && $size > $this->maxSize) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 2: Écrire le repository**

```php
<?php
// src/Repository/StorageRuleRepository.php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\StorageRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StorageRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorageRule::class);
    }

    /** @return StorageRule[] */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['priority' => 'ASC']);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Entity/StorageRule.php src/Repository/StorageRuleRepository.php
git commit -m "feat(storage): entity StorageRule + repository"
```

---

### Task 5: Modifier l'entity Project

**Files:**
- Modify: `src/Entity/Project.php`

- [ ] **Step 1: Ajouter storageStrategy, storageProfiles, storageRules + déprécier disk**

Dans `src/Entity/Project.php`, remplacer la propriété `$disk` par :

```php
// src/Entity/Project.php — remplacer le bloc $disk (lignes 48-52) :

    /**
     * @deprecated Use storageProfiles + storageStrategy instead.
     * Returns 'public' if default profile is local, 's3' if S3.
     */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $disk = 'public' {
        get {
            // Résolution dynamique : si le getter est appelé avant le chargement
            // des profils, retourne la valeur brute en base.
            if (!isset($this->storageProfiles)) {
                return $this->disk;
            }
            $default = $this->storageProfiles
                ->filter(fn (ProjectStorageProfile $p) => $p->isDefault)
                ->first();
            if (!$default) {
                return 'public';
            }
            return $default->driver === 's3' ? 's3' : 'public';
        }
        set { $this->disk = $value; }
    }

    // ── Nouveaux champs (à insérer après $publicApi, avant $jwtAccessTtl) ──

    #[ORM\Column(length: 20)]
    public string $storageStrategy = 'default_only' {
        get => $this->storageStrategy;
        set { $this->storageStrategy = $value; }
    }

    #[ORM\OneToMany(targetEntity: ProjectStorageProfile::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public DoctrineCollection $storageProfiles;

    #[ORM\OneToMany(targetEntity: StorageRule::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public DoctrineCollection $storageRules;
```

Dans le constructeur `__construct()` de Project, ajouter :

```php
$this->storageProfiles = new ArrayCollection();
$this->storageRules    = new ArrayCollection();
```

- [ ] **Step 2: Commit**

```bash
git add src/Entity/Project.php
git commit -m "feat(storage): ajouter storageStrategy + relations Project → StorageProfile/StorageRule"
```

---

### Task 6: Modifier l'entity Media

**Files:**
- Modify: `src/Entity/Media.php`

- [ ] **Step 1: Ajouter storageProfile et storagePaths**

Dans `src/Entity/Media.php`, ajouter après `$deletedAt` :

```php
// src/Entity/Media.php — ajouter après $deletedAt (ligne 67) :

    #[ORM\ManyToOne(targetEntity: ProjectStorageProfile::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?ProjectStorageProfile $storageProfile = null;

    /** Mapping {"<profile_uuid>": "<relative_path>", …}. Ex: {"a1b2c3": "projects/f99cb038/photo.jpg"} */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $storagePaths = null;
```

Ajouter l'import :

```php
use App\Entity\ProjectStorageProfile;
```

- [ ] **Step 2: Commit**

```bash
git add src/Entity/Media.php
git commit -m "feat(storage): ajouter storageProfile + storagePaths à Media"
```

---

### Task 7: Créer StorageDriverFactory

**Files:**
- Create: `src/Service/StorageDriverFactory.php`

- [ ] **Step 1: Écrire la factory**

```php
<?php
// src/Service/StorageDriverFactory.php

namespace App\Service;

use App\Entity\ProjectStorageProfile;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

class StorageDriverFactory
{
    public function __construct(
        private readonly string $appSecret,
        private readonly string $projectDir,
    ) {}

    /** Construit le FilesystemOperator pour un profil donné. */
    public function create(ProjectStorageProfile $profile): FilesystemOperator
    {
        return match ($profile->driver) {
            'local' => $this->createLocal($profile),
            's3'    => $this->createS3($profile),
            default => throw new \InvalidArgumentException("Unknown storage driver: {$profile->driver}"),
        };
    }

    private function createLocal(ProjectStorageProfile $profile): FilesystemOperator
    {
        $rootPath = $profile->rootPath
            ?? $this->projectDir . '/public/uploads/media/' . $profile->project->uuid;

        return new Filesystem(new LocalFilesystemAdapter($rootPath));
    }

    private function createS3(ProjectStorageProfile $profile): FilesystemOperator
    {
        $secret = $this->decrypt($profile->s3Secret ?? '');

        $clientConfig = [
            'version'     => 'latest',
            'region'      => $profile->s3Region,
            'credentials' => [
                'key'    => $profile->s3Key,
                'secret' => $secret,
            ],
        ];

        if ($profile->s3Endpoint !== null && $profile->s3Endpoint !== '') {
            $clientConfig['endpoint'] = $profile->s3Endpoint;
        }

        if ($profile->s3UsePathStyle) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        $client = new S3Client($clientConfig);

        return new Filesystem(new AwsS3V3Adapter(
            $client,
            $profile->s3Bucket,
            visibilityConverter: new PortableVisibilityConverter(),
        ));
    }

    // ─── Chiffrement sodium (identique au SMTP) ──────────────────────────

    private function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            throw new \RuntimeException('S3 secret not configured.');
        }
        $decoded = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonce   = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher  = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $key     = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, $key);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key    = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Service/StorageDriverFactory.php
git commit -m "feat(storage): StorageDriverFactory — créé filesystems local/s3 via Flysystem"
```

---

### Task 8: Créer StorageManager

**Files:**
- Create: `src/Service/StorageManager.php`

- [ ] **Step 1: Écrire le manager**

```php
<?php
// src/Service/StorageManager.php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use League\Flysystem\FilesystemOperator;

class StorageManager
{
    /** @var array<string, FilesystemOperator> cache mémoire */
    private array $filesystemCache = [];

    public function __construct(
        private readonly Project $project,
        private readonly ProjectStorageProfileRepository $profileRepo,
        private readonly StorageRuleRepository $ruleRepo,
        private readonly StorageDriverFactory $factory,
    ) {}

    // ─── Résolution ─────────────────────────────────────────────────────

    /**
     * Résout le(s) filesystem(s) selon la stratégie du projet.
     * @return array<string, FilesystemOperator>  [profile_uuid => Filesystem]
     */
    public function getFilesystems(array $options = []): array
    {
        $profiles = match ($this->project->storageStrategy) {
            'default_only' => $this->resolveDefaultOnly(),
            'mirror_all'   => $this->resolveMirrorAll(),
            'rules'        => $this->resolveRules($options),
            default        => $this->resolveDefaultOnly(),
        };

        $filesystems = [];
        foreach ($profiles as $profile) {
            $uuid = $profile->uuid->toRfc4122();
            $filesystems[$uuid] = $this->getOrCreateFilesystem($profile);
        }

        return $filesystems;
    }

    /** Résout un filesystem spécifique par UUID de profil. */
    public function getFilesystem(string $profileUuid): FilesystemOperator
    {
        $profile = $this->profileRepo->findOneBy(['uuid' => $profileUuid, 'project' => $this->project]);
        if ($profile === null) {
            throw new \RuntimeException("Storage profile not found: $profileUuid");
        }
        return $this->getOrCreateFilesystem($profile);
    }

    // ─── Opérations CRUD ────────────────────────────────────────────────

    /** @return array<string, string> profile_uuid => path */
    public function write(string $path, mixed $stream, array $options = []): array
    {
        $filesystems = $this->getFilesystems($options);
        $paths = [];

        foreach ($filesystems as $uuid => $fs) {
            $fs->writeStream($path, $stream);
            rewind($stream); // rewinder le stream pour le prochain filesystem
            $paths[$uuid] = $path;
        }

        return $paths;
    }

    /** Supprime sur tous les storages connus (via storagePaths). */
    public function delete(?array $storagePaths): void
    {
        if ($storagePaths === null) {
            return;
        }
        foreach ($storagePaths as $uuid => $path) {
            try {
                $fs = $this->getFilesystem($uuid);
                if ($fs->fileExists($path)) {
                    $fs->delete($path);
                }
            } catch (\Throwable) {
                // On continue même si un storage est injoignable
            }
        }
    }

    /** Lit depuis le premier storage trouvé. */
    public function read(?array $storagePaths): mixed
    {
        if ($storagePaths === null) {
            throw new \RuntimeException('No storage paths available.');
        }
        foreach ($storagePaths as $uuid => $path) {
            try {
                $fs = $this->getFilesystem($uuid);
                return $fs->readStream($path);
            } catch (\Throwable) {
                continue;
            }
        }
        throw new \RuntimeException('File not found on any storage.');
    }

    /** URL publique (relative pour local, signée S3 ou CDN). */
    public function getUrl(?array $storagePaths, ?string $profileUuid = null): ?string
    {
        if ($storagePaths === null || $storagePaths === []) {
            return null;
        }

        // Si un profil spécifique est demandé
        if ($profileUuid !== null && isset($storagePaths[$profileUuid])) {
            return $this->buildUrl($profileUuid, $storagePaths[$profileUuid]);
        }

        // Sinon, premier profil disponible
        $uuid = array_key_first($storagePaths);
        return $this->buildUrl($uuid, $storagePaths[$uuid]);
    }

    // ─── Private ─────────────────────────────────────────────────────────

    /** @return ProjectStorageProfile[] */
    private function resolveDefaultOnly(): array
    {
        $default = $this->profileRepo->findDefault($this->project);
        return $default !== null ? [$default] : [];
    }

    /** @return ProjectStorageProfile[] */
    private function resolveMirrorAll(): array
    {
        return $this->profileRepo->findActive($this->project);
    }

    /** @return ProjectStorageProfile[] */
    private function resolveRules(array $options = []): array
    {
        $rules = $this->ruleRepo->findByProject($this->project);
        $mimeType = $options['mime_type'] ?? '';
        $filename = $options['filename'] ?? '';
        $size     = $options['size'] ?? 0;

        foreach ($rules as $rule) {
            if ($rule->matches($mimeType, $filename, $size)) {
                return [$rule->storageProfile];
            }
        }

        // Fallback : profil par défaut
        $default = $this->profileRepo->findDefault($this->project);
        return $default !== null ? [$default] : [];
    }

    private function getOrCreateFilesystem(ProjectStorageProfile $profile): FilesystemOperator
    {
        $uuid = $profile->uuid->toRfc4122();
        if (!isset($this->filesystemCache[$uuid])) {
            $this->filesystemCache[$uuid] = $this->factory->create($profile);
        }
        return $this->filesystemCache[$uuid];
    }

    private function buildUrl(string $profileUuid, string $path): string
    {
        $profile = $this->profileRepo->findOneBy(['uuid' => $profileUuid]);
        if ($profile === null) {
            return '/uploads/media/' . ltrim($path, '/');
        }

        if ($profile->driver === 'local') {
            return '/uploads/media/' . ltrim($path, '/');
        }

        // S3 avec CDN
        if ($profile->baseUrl !== null && $profile->baseUrl !== '') {
            return rtrim($profile->baseUrl, '/') . '/' . ltrim($path, '/');
        }

        // S3 sans CDN : URL signée temporaire (1h)
        $fs = $this->getOrCreateFilesystem($profile);
        // Flysystem AwsS3V3Adapter expose le client S3, mais pour simplifier
        // on retourne l'URL publique standard
        $endpoint = $profile->s3Endpoint ?: 'https://s3.amazonaws.com';
        return rtrim($endpoint, '/') . '/' . $profile->s3Bucket . '/' . $path;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Service/StorageManager.php
git commit -m "feat(storage): StorageManager — résolution stratégie + opérations CRUD multi-filesystem"
```

---

### Task 9: Déclarer les services dans services.yaml

**Files:**
- Modify: `config/services.yaml`

- [ ] **Step 1: Ajouter les définitions de services**

```yaml
# config/services.yaml — ajouter après le bloc Email/SMTP (ligne 81) :

    # ── Storage / Multi-stockage ───────────────────────────────────────────────
    App\Service\StorageDriverFactory:
        arguments:
            $appSecret: '%kernel.secret%'
            $projectDir: '%kernel.project_dir%'

    # StorageManager est instancié manuellement (new StorageManager(...))
    # car il dépend du projet courant. Il n'est PAS déclaré comme service.

    App\Service\MediaSerializer:
        arguments:
            $profileRepo: '@App\Repository\ProjectStorageProfileRepository'
            $ruleRepo: '@App\Repository\StorageRuleRepository'
            $driverFactory: '@App\Service\StorageDriverFactory'
```

Run: `php bin/console debug:container App\\Service\\StorageDriverFactory`
Expected: service défini

- [ ] **Step 2: Commit**

```bash
git add config/services.yaml
git commit -m "feat(storage): déclarer StorageDriverFactory + StorageManager dans services.yaml"
```

---

### Task 10: Créer la migration Doctrine

**Files:**
- Create: `migrations/Version20260608MultiStorage.php`

- [ ] **Step 1: Générer la migration**

```bash
php bin/console make:migration
```

- [ ] **Step 2: Compléter avec le script de migration de données**

Dans la méthode `up()`, après la création des tables, ajouter :

```php
// migrations/Version20260608MultiStorage.php — dans up(), après les DDL :

    public function up(Schema $schema): void
    {
        // ... DDL généré par make:migration ...

        // ── Migration des données existantes ──────────────────────────
        // 1 profil local hérité par projet existant
        $this->addSql("
            INSERT INTO project_storage_profile
                (project_id, uuid, name, driver, priority, enabled, is_default, root_path, created_at, updated_at)
            SELECT
                id, UUID(), 'Local (hérité)', 'local', 0, 1, 1,
                CONCAT('public/uploads/media/', id),
                NOW(), NOW()
            FROM project
        ");

        // Associer les médias existants au profil local créé
        $this->addSql("
            UPDATE media m
            INNER JOIN project_storage_profile p ON p.project_id = m.project_id
            SET m.storage_profile_id = p.id,
                m.storage_paths = JSON_OBJECT(p.uuid, CONCAT('projects/', m.project_id, '/', m.file_name))
        ");
    }
```

- [ ] **Step 3: Exécuter la migration**

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Run: `php bin/console doctrine:migrations:migrate`
Expected: migration exécutée sans erreur, les projets existants ont un profil local

- [ ] **Step 4: Commit**

```bash
git add migrations/
git commit -m "feat(storage): migration DDL tables + migration données (profil local hérité)"
```

---

### Task 11: Créer le contrôleur admin ProjectStorageController

**Files:**
- Create: `src/Controller/Admin/ProjectStorageController.php`

- [ ] **Step 1: Écrire le contrôleur**

```php
<?php
// src/Controller/Admin/ProjectStorageController.php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Entity\StorageRule;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\StorageDriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProjectStorageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectStorageProfileRepository $profileRepo,
        private readonly StorageRuleRepository $ruleRepo,
        private readonly StorageDriverFactory $driverFactory,
    ) {}

    // ── Stratégie ──────────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage', name: 'admin_project_storage_get', methods: ['GET'])]
    public function getConfig(string $uuid): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        return new JsonResponse([
            'strategy' => $project->storageStrategy,
            'profiles' => array_map(
                fn (ProjectStorageProfile $p) => $p->toArray(),
                $this->profileRepo->findByProject($project)
            ),
            'rules' => array_map(
                fn (StorageRule $r) => [
                    'id'                => $r->id,
                    'profile_uuid'      => $r->storageProfile->uuid?->toRfc4122(),
                    'mime_type_pattern' => $r->mimeTypePattern,
                    'extension'         => $r->extension,
                    'max_size'          => $r->maxSize,
                    'priority'          => $r->priority,
                ],
                $this->ruleRepo->findByProject($project)
            ),
        ]);
    }

    #[Route('/api/admin/projects/{uuid}/storage', name: 'admin_project_storage_update', methods: ['PUT'])]
    public function updateConfig(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $body = $request->toArray();
        if (isset($body['strategy'])) {
            $strategy = (string) $body['strategy'];
            if (!in_array($strategy, ['default_only', 'mirror_all', 'rules'], true)) {
                return new JsonResponse(['error' => 'Invalid strategy.'], 400);
            }
            $project->storageStrategy = $strategy;
        }

        $this->em->flush();
        return $this->getConfig($uuid);
    }

    // ── Profils CRUD ──────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage/profiles', name: 'admin_project_storage_profile_create', methods: ['POST'])]
    public function createProfile(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $body = $request->toArray();
        $profile = $this->hydrateProfile(new ProjectStorageProfile(), $body, $project);

        // Gérer is_default : désactiver les autres profils default
        if ($profile->isDefault) {
            $this->clearOtherDefaults($project, $profile);
        }

        $this->em->persist($profile);
        $this->em->flush();

        return new JsonResponse(['data' => $profile->toArray()], 201);
    }

    #[Route('/api/admin/projects/{uuid}/storage/profiles/{id}', name: 'admin_project_storage_profile_update', methods: ['PUT'])]
    public function updateProfile(string $uuid, int $id, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $profile = $this->profileRepo->find($id);
        if ($profile === null || $profile->project->id !== $project->id) {
            return new JsonResponse(['error' => 'Profile not found.'], 404);
        }

        $this->hydrateProfile($profile, $request->toArray(), $project);

        if ($profile->isDefault) {
            $this->clearOtherDefaults($project, $profile);
        }

        $this->em->flush();

        return new JsonResponse(['data' => $profile->toArray()]);
    }

    #[Route('/api/admin/projects/{uuid}/storage/profiles/{id}', name: 'admin_project_storage_profile_delete', methods: ['DELETE'])]
    public function deleteProfile(string $uuid, int $id): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        // Protéger : ne pas supprimer le dernier profil
        if ($this->profileRepo->countByProject($project) <= 1) {
            return new JsonResponse(['error' => 'Cannot delete the last storage profile.'], 422);
        }

        $profile = $this->profileRepo->find($id);
        if ($profile === null || $profile->project->id !== $project->id) {
            return new JsonResponse(['error' => 'Profile not found.'], 404);
        }

        $this->em->remove($profile);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    // ── Règles CRUD ────────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage/rules', name: 'admin_project_storage_rule_create', methods: ['POST'])]
    public function createRule(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $body = $request->toArray();
        $rule = $this->hydrateRule(new StorageRule(), $body, $project);

        $this->em->persist($rule);
        $this->em->flush();

        return new JsonResponse($this->serializeRule($rule), 201);
    }

    #[Route('/api/admin/projects/{uuid}/storage/rules/{id}', name: 'admin_project_storage_rule_update', methods: ['PUT'])]
    public function updateRule(string $uuid, int $id, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $rule = $this->ruleRepo->find($id);
        if ($rule === null || $rule->project->id !== $project->id) {
            return new JsonResponse(['error' => 'Rule not found.'], 404);
        }

        $this->hydrateRule($rule, $request->toArray(), $project);
        $this->em->flush();

        return new JsonResponse($this->serializeRule($rule));
    }

    #[Route('/api/admin/projects/{uuid}/storage/rules/{id}', name: 'admin_project_storage_rule_delete', methods: ['DELETE'])]
    public function deleteRule(string $uuid, int $id): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $rule = $this->ruleRepo->find($id);
        if ($rule === null || $rule->project->id !== $project->id) {
            return new JsonResponse(['error' => 'Rule not found.'], 404);
        }

        $this->em->remove($rule);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    // ── Private helpers ────────────────────────────────────────────────

    private function findProject(string $uuid): Project|JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if ($project === null) {
            return new JsonResponse(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);
        return $project;
    }

    private function clearOtherDefaults(Project $project, ProjectStorageProfile $current): void
    {
        foreach ($this->profileRepo->findByProject($project) as $p) {
            if ($p->id !== $current->id && $p->isDefault) {
                $p->isDefault = false;
            }
        }
    }

    private function hydrateProfile(ProjectStorageProfile $p, array $body, Project $project): ProjectStorageProfile
    {
        if (isset($body['name']))             $p->name = (string) $body['name'];
        if (isset($body['driver']))           $p->driver = (string) $body['driver'];
        if (isset($body['priority']))         $p->priority = (int) $body['priority'];
        if (isset($body['enabled']))          $p->enabled = (bool) $body['enabled'];
        if (isset($body['is_default']))       $p->isDefault = (bool) $body['is_default'];
        if (isset($body['s3_key']))           $p->s3Key = (string) $body['s3_key'];
        if (isset($body['s3_region']))        $p->s3Region = (string) $body['s3_region'];
        if (isset($body['s3_bucket']))        $p->s3Bucket = (string) $body['s3_bucket'];
        if (isset($body['s3_endpoint']))      $p->s3Endpoint = (string) $body['s3_endpoint'];
        if (isset($body['s3_use_path_style'])) $p->s3UsePathStyle = (bool) $body['s3_use_path_style'];
        if (isset($body['base_url']))          $p->baseUrl = (string) $body['base_url'];
        if (isset($body['root_path']))         $p->rootPath = (string) $body['root_path'];

        // Secret : ne mettre à jour que si fourni (non vide)
        if (!empty($body['s3_secret'] ?? '')) {
            $p->s3Secret = $this->driverFactory->encrypt((string) $body['s3_secret']);
        }

        $p->project = $project;
        return $p;
    }

    private function hydrateRule(StorageRule $r, array $body, Project $project): StorageRule
    {
        if (isset($body['mime_type_pattern'])) $r->mimeTypePattern = (string) $body['mime_type_pattern'] ?: null;
        if (isset($body['extension']))         $r->extension = (string) $body['extension'] ?: null;
        if (isset($body['max_size']))          $r->maxSize = $body['max_size'] ? (int) $body['max_size'] : null;
        if (isset($body['priority']))          $r->priority = (int) $body['priority'];

        if (isset($body['profile_uuid'])) {
            $profile = $this->profileRepo->findOneBy([
                'uuid'    => $body['profile_uuid'],
                'project' => $project,
            ]);
            if ($profile !== null) {
                $r->storageProfile = $profile;
            }
        }

        $r->project = $project;
        return $r;
    }

    private function serializeRule(StorageRule $r): array
    {
        return [
            'id'                => $r->id,
            'profile_uuid'      => $r->storageProfile->uuid?->toRfc4122(),
            'mime_type_pattern' => $r->mimeTypePattern,
            'extension'         => $r->extension,
            'max_size'          => $r->maxSize,
            'priority'          => $r->priority,
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/Admin/ProjectStorageController.php
git commit -m "feat(storage): admin controller — CRUD profils + règles + stratégie"
```

---

### Task 12: Modifier PageController (route page stockage)

**Files:**
- Modify: `src/Controller/PageController.php`

- [ ] **Step 1: Ajouter la route pour la page Stockage**

```php
// src/Controller/PageController.php — ajouter avant settingsMailer (ligne 295) :

    #[Route('/projects/{project}/settings/storage', name: 'projects_settings_storage', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsStorage(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        return $this->inertia($request, 'Projects/Settings/Storage', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $this->buildUserCan($project),
        ]);
    }
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/PageController.php
git commit -m "feat(storage): route page Stockage Settings"
```

---

### Task 13: Modifier MediaSerializer + ImageTransformService + AssetController

**Files:**
- Modify: `src/Service/MediaSerializer.php`
- Modify: `src/Service/ImageTransformService.php`
- Modify: `src/Controller/Api/AssetController.php`
- Modify: `src/Controller/ProjectController.php`

- [ ] **Step 1: Modifier MediaSerializer — utiliser StorageManager par média**

```php
// src/Service/MediaSerializer.php — remplacer les propriétés et le constructeur :

use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\StorageDriverFactory;
use App\Service\StorageManager;

class MediaSerializer
{
    public function __construct(
        private ProjectStorageProfileRepository $profileRepo,
        private StorageRuleRepository $ruleRepo,
        private StorageDriverFactory $driverFactory,
    ) {}
```

Dans la méthode `serialize(Media $media)`, créer un StorageManager pour ce média :

```php
// Dans serialize(), après le début :
    $storage = new StorageManager($media->project, $this->profileRepo, $this->ruleRepo, $this->driverFactory);

    $disk = $media->storageProfile?->driver === 's3' ? 's3' : 'public';
    $url  = $media->storagePaths !== null
        ? $storage->getUrl($media->storagePaths, $media->storageProfile?->uuid?->toRfc4122())
        : '/uploads/media/' . $media->fileName;
```

Dans le tableau de retour, remplacer `'disk' => 'public'` et les URL par :

```php
    'disk'              => $disk,
    'path'              => $url,
    'url'               => $url,
    'full_url'          => $url,
    'thumbnail_url'     => $url,
```

- [ ] **Step 2: Modifier ImageTransformService — lecture via Flysystem**

Le cache reste local. On adapte `transform()` pour accepter un stream Flysystem en entrée quand la source n'est pas un fichier local.

```php
// src/Service/ImageTransformService.php — modifier transform() :

    public function transform(string|mixed $source, array $params): string
    {
        $params = $this->normalizeParams($params);
        $cacheKey = $this->cacheKey(
            is_string($source) ? $source : 'stream-' . md5(serialize($params)),
            $params
        );
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        if (isset($this->checkedPaths[$cachePath]) || file_exists($cachePath)) {
            $this->checkedPaths[$cachePath] = true;
            return $cachePath;
        }

        // Si $source est un stream (ressource), l'utiliser directement
        if (is_resource($source)) {
            $this->fs->mkdir(dirname($cachePath));
            $image = $this->manager->read($source);
            // ... transformer + sauvegarder comme avant
            $this->applyTransform($image, $params);
            $image->save($cachePath);
            return $cachePath;
        }

        // Sinon, comportement existant (chemin local)
        if (!file_exists($source)) {
            throw new \RuntimeException('Image source introuvable');
        }
        $this->fs->mkdir(dirname($cachePath));
        $image = $this->manager->read($source);
        $this->applyTransform($image, $params);
        $image->save($cachePath);
        return $cachePath;
    }

    // Extraire le bloc de transformation existant en méthode privée :
    private function applyTransform(\Intervention\Image\Image $image, array $params): void
    {
        if ($params['fit'] === 'crop' && $params['w'] && $params['h']) {
            $image->cover($params['w'], $params['h']);
        } elseif ($params['fit'] === 'contain' && $params['w'] && $params['h']) {
            $image->contain($params['w'], $params['h']);
        } elseif ($params['fit'] === 'fill' && $params['w'] && $params['h']) {
            $image->pad($params['w'], $params['h'], $params['bg'] ?? '#ffffff');
        } elseif ($params['fit'] === 'scale-down' && ($params['w'] || $params['h'])) {
            $image->scaleDown(width: $params['w'] ?: null, height: $params['h'] ?: null);
        } elseif ($params['w'] || $params['h']) {
            $image->resize(width: $params['w'] ?: null, height: $params['h'] ?: null);
        }
    }
```

- [ ] **Step 3: Modifier AssetController — intégrer StorageManager à l'upload**

Dans `src/Controller/Api/AssetController.php`, modifier `upload()` :

```php
// Ajouter l'import :
use App\Service\StorageDriverFactory;
use App\Service\StorageManager;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;

// Ajouter au constructeur :
    private ProjectStorageProfileRepository $profileRepo,
    private StorageRuleRepository $ruleRepo,
    private StorageDriverFactory $driverFactory,

// Dans upload(), après $this->em->flush() (ligne 167), ajouter :
        // ── Écrire sur le(s) storage(s) via StorageManager ──
        $storageManager = new StorageManager($project, $this->profileRepo, $this->ruleRepo, $this->driverFactory);
        $stream = fopen($uploadedFile->getRealPath(), 'r');
        $basePath = 'projects/' . $project->uuid->toRfc4122() . '/' . $media->fileName;

        $storageOpts = [
            'mime_type' => $media->mimeType,
            'filename'  => $media->fileName,
            'size'      => $media->fileSize,
        ];

        $paths = $storageManager->write($basePath, $stream, $storageOpts);
        fclose($stream);

        $media->storagePaths = $paths;
        // Associer au profil par défaut ou au premier qui a écrit
        $primaryUuid = array_key_first($paths);
        $primaryProfile = $this->profileRepo->findOneBy(['uuid' => $primaryUuid, 'project' => $project]);
        $media->storageProfile = $primaryProfile;

        $this->em->flush();
```

- [ ] **Step 4: Commit**

```bash
git add src/Service/MediaSerializer.php src/Service/ImageTransformService.php src/Controller/Api/AssetController.php
git commit -m "feat(storage): intégrer StorageManager dans upload + serializer + image transform"
```

---

### Task 14: Traductions (4 langues)

**Files:**
- Modify: `translations/messages.fr.php`
- Modify: `translations/messages.en.php`
- Modify: `translations/messages.es.php`
- Modify: `translations/messages.ar.php`

- [ ] **Step 1: Ajouter les clés de traduction**

Dans chaque fichier, ajouter après le bloc SMTP Mailer :

```php
// translations/messages.fr.php :

    // Storage / Multi-stockage
    'projects.settings.nav_storage'          => 'Stockage',
    'projects.settings.storage.title'        => 'Stockage',
    'projects.settings.storage.desc'         => 'Configurer où et comment les fichiers de ce projet sont stockés.',
    'projects.settings.storage.strategy'     => 'Stratégie de stockage',
    'projects.settings.storage.strategy_default' => 'Stockage unique (défaut)',
    'projects.settings.storage.strategy_mirror'  => 'Mirroring (tous les stockages actifs)',
    'projects.settings.storage.strategy_rules'   => 'Règles par type de fichier',
    'projects.settings.storage.profiles'     => 'Profils de stockage',
    'projects.settings.storage.add_profile'  => 'Ajouter un profil',
    'projects.settings.storage.edit_profile' => 'Modifier le profil',
    'projects.settings.storage.delete_profile' => 'Supprimer le profil',
    'projects.settings.storage.last_profile' => 'Impossible de supprimer le dernier profil.',
    'projects.settings.storage.rules'        => 'Règles de stockage',
    'projects.settings.storage.add_rule'     => 'Ajouter une règle',
    'projects.settings.storage.no_rules'     => 'Aucune règle. Ajoutez une règle pour diriger les fichiers par type.',
    'projects.settings.storage.driver_local' => 'Stockage local',
    'projects.settings.storage.driver_s3'    => 'S3-compatible (AWS, R2, MinIO...)',
    'projects.settings.storage.s3_key'       => 'Access Key',
    'projects.settings.storage.s3_secret'    => 'Secret Key',
    'projects.settings.storage.s3_region'    => 'Région',
    'projects.settings.storage.s3_bucket'    => 'Bucket',
    'projects.settings.storage.s3_endpoint'  => 'Endpoint (optionnel)',
    'projects.settings.storage.s3_path_style'=> 'Path-style (activer pour MinIO)',
    'projects.settings.storage.cdn_url'      => 'URL CDN (optionnel)',
    'projects.settings.storage.saved'        => 'Configuration de stockage enregistrée.',
    'projects.settings.storage.rule'         => 'Règle',
    'projects.settings.storage.rule_target'  => 'Profil cible',
    'projects.settings.storage.mime_pattern' => 'Type MIME',
    'projects.settings.storage.extension'    => 'Extension',
    'projects.settings.storage.max_size'     => 'Taille max (octets)',
    'projects.settings.storage.delete_rule'  => 'Supprimer la règle',
```

```php
// translations/messages.en.php :

    // Storage / Multi-stockage
    'projects.settings.nav_storage'          => 'Storage',
    'projects.settings.storage.title'        => 'Storage',
    'projects.settings.storage.desc'         => 'Configure where and how this project\'s files are stored.',
    'projects.settings.storage.strategy'     => 'Storage strategy',
    'projects.settings.storage.strategy_default' => 'Single storage (default)',
    'projects.settings.storage.strategy_mirror'  => 'Mirroring (all active storages)',
    'projects.settings.storage.strategy_rules'   => 'Rules by file type',
    'projects.settings.storage.profiles'     => 'Storage profiles',
    'projects.settings.storage.add_profile'  => 'Add profile',
    'projects.settings.storage.edit_profile' => 'Edit profile',
    'projects.settings.storage.delete_profile' => 'Delete profile',
    'projects.settings.storage.last_profile' => 'Cannot delete the last profile.',
    'projects.settings.storage.rules'        => 'Storage rules',
    'projects.settings.storage.add_rule'     => 'Add rule',
    'projects.settings.storage.no_rules'     => 'No rules. Add a rule to direct files by type.',
    'projects.settings.storage.driver_local' => 'Local storage',
    'projects.settings.storage.driver_s3'    => 'S3-compatible (AWS, R2, MinIO...)',
    'projects.settings.storage.s3_key'       => 'Access Key',
    'projects.settings.storage.s3_secret'    => 'Secret Key',
    'projects.settings.storage.s3_region'    => 'Region',
    'projects.settings.storage.s3_bucket'    => 'Bucket',
    'projects.settings.storage.s3_endpoint'  => 'Endpoint (optional)',
    'projects.settings.storage.s3_path_style'=> 'Path-style (enable for MinIO)',
    'projects.settings.storage.cdn_url'      => 'CDN URL (optional)',
    'projects.settings.storage.saved'        => 'Storage configuration saved.',
    'projects.settings.storage.rule'         => 'Rule',
    'projects.settings.storage.rule_target'  => 'Target profile',
    'projects.settings.storage.mime_pattern' => 'MIME type',
    'projects.settings.storage.extension'    => 'Extension',
    'projects.settings.storage.max_size'     => 'Max size (bytes)',
    'projects.settings.storage.delete_rule'  => 'Delete rule',
```

```php
// translations/messages.es.php :

    // Storage / Multi-stockage
    'projects.settings.nav_storage'          => 'Almacenamiento',
    'projects.settings.storage.title'        => 'Almacenamiento',
    'projects.settings.storage.desc'         => 'Configurar dónde y cómo se almacenan los archivos de este proyecto.',
    'projects.settings.storage.strategy'     => 'Estrategia de almacenamiento',
    'projects.settings.storage.strategy_default' => 'Almacenamiento único (defecto)',
    'projects.settings.storage.strategy_mirror'  => 'Mirroring (todos los almacenamientos activos)',
    'projects.settings.storage.strategy_rules'   => 'Reglas por tipo de archivo',
    'projects.settings.storage.profiles'     => 'Perfiles de almacenamiento',
    'projects.settings.storage.add_profile'  => 'Añadir perfil',
    'projects.settings.storage.edit_profile' => 'Editar perfil',
    'projects.settings.storage.delete_profile' => 'Eliminar perfil',
    'projects.settings.storage.last_profile' => 'No se puede eliminar el último perfil.',
    'projects.settings.storage.rules'        => 'Reglas de almacenamiento',
    'projects.settings.storage.add_rule'     => 'Añadir regla',
    'projects.settings.storage.no_rules'     => 'Sin reglas. Añada una para dirigir archivos por tipo.',
    'projects.settings.storage.driver_local' => 'Almacenamiento local',
    'projects.settings.storage.driver_s3'    => 'S3-compatible (AWS, R2, MinIO...)',
    'projects.settings.storage.s3_key'       => 'Access Key',
    'projects.settings.storage.s3_secret'    => 'Secret Key',
    'projects.settings.storage.s3_region'    => 'Región',
    'projects.settings.storage.s3_bucket'    => 'Bucket',
    'projects.settings.storage.s3_endpoint'  => 'Endpoint (opcional)',
    'projects.settings.storage.s3_path_style'=> 'Path-style (activar para MinIO)',
    'projects.settings.storage.cdn_url'      => 'URL CDN (opcional)',
    'projects.settings.storage.saved'        => 'Configuración de almacenamiento guardada.',
    'projects.settings.storage.rule'         => 'Regla',
    'projects.settings.storage.rule_target'  => 'Perfil de destino',
    'projects.settings.storage.mime_pattern' => 'Tipo MIME',
    'projects.settings.storage.extension'    => 'Extensión',
    'projects.settings.storage.max_size'     => 'Tamaño máx (bytes)',
    'projects.settings.storage.delete_rule'  => 'Eliminar regla',
```

```php
// translations/messages.ar.php :

    // Storage / Multi-stockage
    'projects.settings.nav_storage'          => 'التخزين',
    'projects.settings.storage.title'        => 'التخزين',
    'projects.settings.storage.desc'         => 'تكوين مكان وكيفية تخزين ملفات هذا المشروع.',
    'projects.settings.storage.strategy'     => 'استراتيجية التخزين',
    'projects.settings.storage.strategy_default' => 'تخزين واحد (افتراضي)',
    'projects.settings.storage.strategy_mirror'  => 'نسخ متطابق (جميع المخازن النشطة)',
    'projects.settings.storage.strategy_rules'   => 'قواعد حسب نوع الملف',
    'projects.settings.storage.profiles'     => 'ملفات التخزين',
    'projects.settings.storage.add_profile'  => 'إضافة ملف',
    'projects.settings.storage.edit_profile' => 'تعديل الملف',
    'projects.settings.storage.delete_profile' => 'حذف الملف',
    'projects.settings.storage.last_profile' => 'لا يمكن حذف آخر ملف تخزين.',
    'projects.settings.storage.rules'        => 'قواعد التخزين',
    'projects.settings.storage.add_rule'     => 'إضافة قاعدة',
    'projects.settings.storage.no_rules'     => 'لا توجد قواعد. أضف قاعدة لتوجيه الملفات حسب النوع.',
    'projects.settings.storage.driver_local' => 'تخزين محلي',
    'projects.settings.storage.driver_s3'    => 'متوافق مع S3 (AWS, R2, MinIO...)',
    'projects.settings.storage.s3_key'       => 'مفتاح الوصول',
    'projects.settings.storage.s3_secret'    => 'المفتاح السري',
    'projects.settings.storage.s3_region'    => 'المنطقة',
    'projects.settings.storage.s3_bucket'    => 'الحاوية',
    'projects.settings.storage.s3_endpoint'  => 'نقطة النهاية (اختياري)',
    'projects.settings.storage.s3_path_style'=> 'نمط المسار (تفعيل لـ MinIO)',
    'projects.settings.storage.cdn_url'      => 'رابط CDN (اختياري)',
    'projects.settings.storage.saved'        => 'تم حفظ تكوين التخزين.',
    'projects.settings.storage.rule'         => 'القاعدة',
    'projects.settings.storage.rule_target'  => 'ملف الهدف',
    'projects.settings.storage.mime_pattern' => 'نوع MIME',
    'projects.settings.storage.extension'    => 'الامتداد',
    'projects.settings.storage.max_size'     => 'الحجم الأقصى (بايت)',
    'projects.settings.storage.delete_rule'  => 'حذف القاعدة',
```

- [ ] **Step 2: Commit**

```bash
git add translations/
git commit -m "feat(storage): clés de traduction multi-stockage (fr/en/es/ar)"
```

---

### Task 15: Frontend — Layout + Types

**Files:**
- Modify: `assets/js/pages/Projects/Settings/layout.tsx`
- Modify: `assets/js/types/project.d.ts`

- [ ] **Step 1: Ajouter l'icône HardDrive dans layout.tsx**

```tsx
// assets/js/pages/Projects/Settings/layout.tsx — modifier l'import :
import { Settings as SettingsIcon, Globe, Users, Key, Share2, UserCog, FileText, Wand2, Plug, Mail, HardDrive } from 'lucide-react';

// Ajouter l'entrée dans sidebarNavItems, entre projet et localisation :
    { title: 'Stockage', href: `${basePath}/storage`, icon: HardDrive, permission: 'access_project_settings' },
```

- [ ] **Step 2: Mettre à jour le type Project dans project.d.ts**

```tsx
// assets/js/types/project.d.ts — remplacer :
    disk: 'public' | 's3';
// par :
    disk?: string;
    storage_strategy?: 'default_only' | 'mirror_all' | 'rules';
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/pages/Projects/Settings/layout.tsx assets/js/types/project.d.ts
git commit -m "feat(storage): sidebar Storage + types Project mis à jour"
```

---

### Task 16: Frontend — Page Storage.tsx

**Files:**
- Create: `assets/js/pages/Projects/Settings/Storage.tsx`

- [ ] **Step 1: Écrire la page principale**

```tsx
import { Head } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import axios from 'axios'
import type { Project, BreadcrumbItem } from '@/types/index.d'
import AppLayout from '@/layouts/app-layout'
import ProjectSettingsLayout from './layout'
import HeadingSmall from '@/components/heading-small'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { CheckCircle, XCircle, Loader2, Plus, Pencil, Trash2, HardDrive, Cloud, ArrowRight } from 'lucide-react'
import { useTranslation } from '@/lib/i18n'
import StorageProfileForm from './StorageProfileForm'
import StorageRuleForm from './StorageRuleForm'

interface Props { project: Project }

interface StorageProfile {
  id: number; uuid: string; name: string; driver: string
  priority: number; enabled: boolean; is_default: boolean
  s3_key?: string; s3_region?: string; s3_bucket?: string
  s3_endpoint?: string; s3_use_path_style: boolean
  base_url?: string; root_path?: string
}

interface StorageRule {
  id: number; profile_uuid: string
  mime_type_pattern?: string; extension?: string; max_size?: number
  priority: number
}

export default function StorageSettingsPage({ project }: Props) {
  const t = useTranslation()

  const [strategy, setStrategy] = useState('default_only')
  const [profiles, setProfiles] = useState<StorageProfile[]>([])
  const [rules, setRules] = useState<StorageRule[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [toast, setToast] = useState<{ type: 'ok' | 'err'; msg: string } | null>(null)

  // Modals
  const [profileFormOpen, setProfileFormOpen] = useState(false)
  const [editingProfile, setEditingProfile] = useState<StorageProfile | null>(null)
  const [ruleFormOpen, setRuleFormOpen] = useState(false)
  const [editingRule, setEditingRule] = useState<StorageRule | null>(null)

  const breadcrumbs: BreadcrumbItem[] = [
    { title: project.name, href: route('projects.show', project.id) },
    { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
    { title: t('projects.settings.nav_storage'), href: route('projects.settings.storage', project.id) },
  ]

  useEffect(() => { fetchConfig() }, [project.uuid])

  async function fetchConfig() {
    try {
      const r = await axios.get(`/api/admin/projects/${project.uuid}/storage`)
      setStrategy(r.data.strategy)
      setProfiles(r.data.profiles)
      setRules(r.data.rules)
    } catch { /* ignoré */ }
    finally { setLoading(false) }
  }

  async function saveStrategy() {
    setSaving(true)
    try {
      await axios.put(`/api/admin/projects/${project.uuid}/storage`, { strategy })
      showToast('ok', t('projects.settings.storage.saved'))
    } catch (e: any) {
      showToast('err', e.response?.data?.error || t('common.error'))
    } finally { setSaving(false) }
  }

  function showToast(type: 'ok' | 'err', msg: string) {
    setToast({ type, msg }); setTimeout(() => setToast(null), 4000)
  }

  function openNewProfile() { setEditingProfile(null); setProfileFormOpen(true) }
  function openEditProfile(p: StorageProfile) { setEditingProfile(p); setProfileFormOpen(true) }

  async function deleteProfile(id: number) {
    try {
      await axios.delete(`/api/admin/projects/${project.uuid}/storage/profiles/${id}`)
      fetchConfig()
    } catch (e: any) {
      showToast('err', e.response?.data?.error || t('common.error'))
    }
  }

  function openNewRule() { setEditingRule(null); setRuleFormOpen(true) }
  function openEditRule(r: StorageRule) { setEditingRule(r); setRuleFormOpen(true) }

  async function deleteRule(id: number) {
    try {
      await axios.delete(`/api/admin/projects/${project.uuid}/storage/rules/${id}`)
      fetchConfig()
    } catch (e: any) {
      showToast('err', e.response?.data?.error || t('common.error'))
    }
  }

  const driverIcon = (d: string) => d === 'local' ? <HardDrive className="h-4 w-4" /> : <Cloud className="h-4 w-4" />
  const driverLabel = (d: string) => d === 'local' ? t('projects.settings.storage.driver_local') : t('projects.settings.storage.driver_s3')

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={t('projects.settings.storage.title')} />
      <ProjectSettingsLayout project={project}>
        <div className="space-y-6 max-w-2xl">
          <HeadingSmall title={t('projects.settings.storage.title')} description={t('projects.settings.storage.desc')} />

          {toast && (
            <Alert variant={toast.type === 'ok' ? 'default' : 'destructive'}>
              {toast.type === 'ok' ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
              <AlertDescription>{toast.msg}</AlertDescription>
            </Alert>
          )}

          {loading && <p className="text-sm text-muted-foreground">{t('common.loading')}</p>}

          {!loading && (
            <div className="space-y-8">
              {/* Stratégie */}
              <div className="space-y-3">
                <Label className="text-base font-medium">{t('projects.settings.storage.strategy')}</Label>
                <Select value={strategy} onValueChange={v => setStrategy(v)}>
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="default_only">{t('projects.settings.storage.strategy_default')}</SelectItem>
                    <SelectItem value="mirror_all">{t('projects.settings.storage.strategy_mirror')}</SelectItem>
                    <SelectItem value="rules">{t('projects.settings.storage.strategy_rules')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Profils */}
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <Label className="text-base font-medium">{t('projects.settings.storage.profiles')}</Label>
                  <Button size="sm" onClick={openNewProfile}><Plus className="mr-1 h-4 w-4" /> {t('projects.settings.storage.add_profile')}</Button>
                </div>

                {profiles.map(p => (
                  <Card key={p.id}>
                    <CardContent className="flex items-center justify-between p-4">
                      <div className="flex items-center gap-3">
                        {driverIcon(p.driver)}
                        <div>
                          <p className="font-medium text-sm">{p.name}</p>
                          <p className="text-xs text-muted-foreground">
                            {driverLabel(p.driver)}
                            {p.is_default && ' · ' + t('projects.settings.storage.strategy_default')}
                            {p.s3_bucket && ' · ' + p.s3_bucket}
                          </p>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        {p.enabled && <Badge variant="outline" className="text-xs">{t('projects.settings.mailer.enabled')}</Badge>}
                        <Button size="icon" variant="ghost" onClick={() => openEditProfile(p)}><Pencil className="h-3.5 w-3.5" /></Button>
                        <Button size="icon" variant="ghost" onClick={() => deleteProfile(p.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>

              {/* Règles (visible si strategy = rules) */}
              {strategy === 'rules' && (
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <Label className="text-base font-medium">{t('projects.settings.storage.rules')}</Label>
                    <Button size="sm" onClick={openNewRule}><Plus className="mr-1 h-4 w-4" /> {t('projects.settings.storage.add_rule')}</Button>
                  </div>

                  {rules.length === 0 && (
                    <p className="text-sm text-muted-foreground">{t('projects.settings.storage.no_rules')}</p>
                  )}

                  {rules.map(r => (
                    <Card key={r.id}>
                      <CardContent className="flex items-center justify-between p-4">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium">{r.mime_type_pattern || r.extension || '*'}</span>
                          <ArrowRight className="h-3 w-3 text-muted-foreground" />
                          <span className="text-sm text-muted-foreground">
                            {profiles.find(p => p.uuid === r.profile_uuid)?.name || r.profile_uuid}
                          </span>
                        </div>
                        <div className="flex items-center gap-1">
                          <Button size="icon" variant="ghost" onClick={() => openEditRule(r)}><Pencil className="h-3.5 w-3.5" /></Button>
                          <Button size="icon" variant="ghost" onClick={() => deleteRule(r.id)}><Trash2 className="h-3.5 w-3.5" /></Button>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              )}

              <Button onClick={saveStrategy} disabled={saving}>
                {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {saving ? t('projects.settings.mailer.saving') : t('common.save')}
              </Button>
            </div>
          )}
        </div>

        {/* Modals */}
        <StorageProfileForm
          open={profileFormOpen}
          onClose={() => setProfileFormOpen(false)}
          onSaved={() => { setProfileFormOpen(false); fetchConfig() }}
          projectUuid={project.uuid}
          editProfile={editingProfile}
        />

        <StorageRuleForm
          open={ruleFormOpen}
          onClose={() => setRuleFormOpen(false)}
          onSaved={() => { setRuleFormOpen(false); fetchConfig() }}
          projectUuid={project.uuid}
          profiles={profiles}
          editRule={editingRule}
        />
      </ProjectSettingsLayout>
    </AppLayout>
  )
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/pages/Projects/Settings/Storage.tsx
git commit -m "feat(storage): page Storage.tsx — stratégie + liste profils + règles"
```

---

### Task 17: Frontend — Modal StorageProfileForm.tsx

**Files:**
- Create: `assets/js/pages/Projects/Settings/StorageProfileForm.tsx`

- [ ] **Step 1: Écrire le formulaire modal**

```tsx
import { useState, useEffect } from 'react'
import axios from 'axios'
import { useTranslation } from '@/lib/i18n'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Loader2 } from 'lucide-react'

interface Props {
  open: boolean; onClose: () => void; onSaved: () => void
  projectUuid: string; editProfile: any | null
}

export default function StorageProfileForm({ open, onClose, onSaved, projectUuid, editProfile }: Props) {
  const t = useTranslation()
  const isEdit = editProfile !== null

  const [name, setName] = useState('')
  const [driver, setDriver] = useState<'local' | 's3'>('s3')
  const [s3Key, setS3Key] = useState('')
  const [s3Secret, setS3Secret] = useState('')
  const [s3Region, setS3Region] = useState('')
  const [s3Bucket, setS3Bucket] = useState('')
  const [s3Endpoint, setS3Endpoint] = useState('')
  const [s3PathStyle, setS3PathStyle] = useState(false)
  const [baseUrl, setBaseUrl] = useState('')
  const [rootPath, setRootPath] = useState('')
  const [isDefault, setIsDefault] = useState(false)
  const [enabled, setEnabled] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (editProfile) {
      setName(editProfile.name || '')
      setDriver(editProfile.driver || 's3')
      setS3Key(editProfile.s3_key || '')
      setS3Region(editProfile.s3_region || '')
      setS3Bucket(editProfile.s3_bucket || '')
      setS3Endpoint(editProfile.s3_endpoint || '')
      setS3PathStyle(editProfile.s3_use_path_style || false)
      setBaseUrl(editProfile.base_url || '')
      setRootPath(editProfile.root_path || '')
      setIsDefault(editProfile.is_default || false)
      setEnabled(editProfile.enabled !== false)
    } else {
      resetForm()
    }
  }, [editProfile, open])

  function resetForm() {
    setName(''); setDriver('s3'); setS3Key(''); setS3Secret(''); setS3Region('')
    setS3Bucket(''); setS3Endpoint(''); setS3PathStyle(false); setBaseUrl('')
    setRootPath(''); setIsDefault(false); setEnabled(true)
  }

  async function handleSubmit() {
    setSaving(true)
    try {
      const body: any = { name, driver, enabled, is_default: isDefault, priority: 0 }
      if (driver === 's3') {
        body.s3_key = s3Key; body.s3_region = s3Region; body.s3_bucket = s3Bucket
        body.s3_endpoint = s3Endpoint; body.s3_use_path_style = s3PathStyle
        body.base_url = baseUrl
        if (s3Secret) body.s3_secret = s3Secret
      } else {
        body.root_path = rootPath
      }

      if (isEdit) {
        await axios.put(`/api/admin/projects/${projectUuid}/storage/profiles/${editProfile.id}`, body)
      } else {
        await axios.post(`/api/admin/projects/${projectUuid}/storage/profiles`, body)
      }
      onSaved()
    } catch { /* toast géré par le parent */ }
    finally { setSaving(false) }
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{isEdit ? t('projects.settings.storage.edit_profile') : t('projects.settings.storage.add_profile')}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="grid gap-2">
            <Label>{t('common.name')}</Label>
            <Input value={name} onChange={e => setName(e.target.value)} placeholder="AWS S3 Paris" />
          </div>

          <div className="grid gap-2">
            <Label>Driver</Label>
            <Select value={driver} onValueChange={v => setDriver(v as 'local' | 's3')}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="s3">{t('projects.settings.storage.driver_s3')}</SelectItem>
                <SelectItem value="local">{t('projects.settings.storage.driver_local')}</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {driver === 's3' && (
            <>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.s3_key')}</Label>
                <Input value={s3Key} onChange={e => setS3Key(e.target.value)} />
              </div>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.s3_secret')}</Label>
                <Input type="password" value={s3Secret} onChange={e => setS3Secret(e.target.value)}
                  placeholder={isEdit ? t('common.password_keep') : ''} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                  <Label>{t('projects.settings.storage.s3_region')}</Label>
                  <Input value={s3Region} onChange={e => setS3Region(e.target.value)} placeholder="eu-west-3" />
                </div>
                <div className="grid gap-2">
                  <Label>{t('projects.settings.storage.s3_bucket')}</Label>
                  <Input value={s3Bucket} onChange={e => setS3Bucket(e.target.value)} placeholder="my-bucket" />
                </div>
              </div>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.s3_endpoint')}</Label>
                <Input value={s3Endpoint} onChange={e => setS3Endpoint(e.target.value)} placeholder="https://xxx.r2.cloudflarestorage.com" />
              </div>
              <div className="flex items-center gap-2">
                <Checkbox id="path_style" checked={s3PathStyle} onCheckedChange={v => setS3PathStyle(v === true)} />
                <Label htmlFor="path_style" className="text-sm">{t('projects.settings.storage.s3_path_style')}</Label>
              </div>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.cdn_url')}</Label>
                <Input value={baseUrl} onChange={e => setBaseUrl(e.target.value)} placeholder="https://cdn.example.com" />
              </div>
            </>
          )}

          {driver === 'local' && (
            <div className="grid gap-2">
              <Label>Root path</Label>
              <Input value={rootPath} onChange={e => setRootPath(e.target.value)}
                placeholder={`public/uploads/media/${projectUuid}`} />
            </div>
          )}

          <div className="flex items-center gap-2">
            <Checkbox id="is_default" checked={isDefault} onCheckedChange={v => setIsDefault(v === true)} />
            <Label htmlFor="is_default" className="text-sm">{t('projects.settings.storage.strategy_default')}</Label>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
          <Button onClick={handleSubmit} disabled={saving}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/pages/Projects/Settings/StorageProfileForm.tsx
git commit -m "feat(storage): modal StorageProfileForm.tsx"
```

---

### Task 18: Frontend — Modal StorageRuleForm.tsx

**Files:**
- Create: `assets/js/pages/Projects/Settings/StorageRuleForm.tsx`

- [ ] **Step 1: Écrire le formulaire modal**

```tsx
import { useState, useEffect } from 'react'
import axios from 'axios'
import { useTranslation } from '@/lib/i18n'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Loader2 } from 'lucide-react'

interface Props {
  open: boolean; onClose: () => void; onSaved: () => void
  projectUuid: string; profiles: any[]; editRule: any | null
}

const MIME_PATTERNS = [
  { label: 'Images (*)', value: 'image/*' },
  { label: 'Vidéos (*)', value: 'video/*' },
  { label: 'Audio (*)', value: 'audio/*' },
  { label: 'PDF', value: 'application/pdf' },
  { label: 'JSON', value: 'application/json' },
  { label: 'Custom...', value: '' },
]

export default function StorageRuleForm({ open, onClose, onSaved, projectUuid, profiles, editRule }: Props) {
  const t = useTranslation()
  const isEdit = editRule !== null

  const [profileUuid, setProfileUuid] = useState('')
  const [mimePattern, setMimePattern] = useState('')
  const [customMime, setCustomMime] = useState('')
  const [extension, setExtension] = useState('')
  const [maxSize, setMaxSize] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (editRule) {
      setProfileUuid(editRule.profile_uuid || '')
      setMimePattern(editRule.mime_type_pattern || '')
      setExtension(editRule.extension || '')
      setMaxSize(editRule.max_size ? String(editRule.max_size) : '')
    } else {
      setProfileUuid(profiles[0]?.uuid || '')
      setMimePattern(''); setCustomMime(''); setExtension(''); setMaxSize('')
    }
  }, [editRule, open, profiles])

  const effectiveMime = mimePattern || customMime

  async function handleSubmit() {
    setSaving(true)
    try {
      const body: any = {
        profile_uuid: profileUuid,
        mime_type_pattern: effectiveMime || null,
        extension: extension || null,
        max_size: maxSize ? parseInt(maxSize) : null,
        priority: 0,
      }
      if (isEdit) {
        await axios.put(`/api/admin/projects/${projectUuid}/storage/rules/${editRule.id}`, body)
      } else {
        await axios.post(`/api/admin/projects/${projectUuid}/storage/rules`, body)
      }
      onSaved()
    } catch { /* toast géré par le parent */ }
    finally { setSaving(false) }
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{isEdit ? t('common.edit') : t('projects.settings.storage.add_rule')}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-4">
          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.rule_target')}</Label>
            <Select value={profileUuid} onValueChange={setProfileUuid}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                {profiles.filter(p => p.enabled).map(p => (
                  <SelectItem key={p.uuid} value={p.uuid}>{p.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.mime_pattern')}</Label>
            <Select value={mimePattern} onValueChange={v => { setMimePattern(v); setCustomMime('') }}>
              <SelectTrigger><SelectValue placeholder="Sélectionner..." /></SelectTrigger>
              <SelectContent>
                {MIME_PATTERNS.map(m => (
                  <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            {mimePattern === '' && !isEdit && (
              <Input className="mt-1" value={customMime}
                onChange={e => setCustomMime(e.target.value)}
                placeholder="application/x-custom" />
            )}
          </div>

          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.extension')}</Label>
            <Input value={extension} onChange={e => setExtension(e.target.value)} placeholder="pdf" />
          </div>

          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.max_size')}</Label>
            <Input type="number" value={maxSize} onChange={e => setMaxSize(e.target.value)} placeholder="10485760" />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
          <Button onClick={handleSubmit} disabled={saving || !profileUuid}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
```

- [ ] **Step 2: Commit**

```bash
git add assets/js/pages/Projects/Settings/StorageRuleForm.tsx
git commit -m "feat(storage): modal StorageRuleForm.tsx"
```

---

### Task 19: Build + test + commit final

- [ ] **Step 1: Vérification TypeScript**

```bash
npx tsc --noEmit --pretty 2>&1 | grep -E "Storage\.(tsx|ts)" | head -10
```

Expected: aucune erreur sur les fichiers Storage

- [ ] **Step 2: Build assets**

```bash
npm run build
```

Expected: `Compiled successfully`

- [ ] **Step 3: Commit final + push**

```bash
git add composer.json composer.lock config/ src/ assets/ translations/ migrations/ docs/
git status  # Vérifier qu'aucun fichier indésirable n'est inclus
git commit -m "feat(storage): build final multi-stockage — profils S3/local, 3 stratégies"
git push
```
