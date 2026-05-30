# Jambo Email/SMTP + Captcha — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Permettre aux apps hébergées dans Jambo d'envoyer des emails via le SMTP configuré par le propriétaire du projet, protégé par un captcha stateless + rate limiting.

**Architecture:** (1) ProjectMailerSettings (entité, password chiffré AES-256-GCM). (2) ProjectMailerService (transport SMTP dynamique, envoi async Messenger). (3) CaptchaController (stateless, cache + token). (4) ProjectEmailController (endpoint public protégé captcha + rate limit). (5) CRUD admin + test SMTP.

**Tech Stack:** PHP 8.4, Symfony 8, Doctrine ORM, Messenger, RateLimiter, `gregwar/captcha`, `symfony/mailer`.

---

## File Map

### Créés
| Fichier | Responsabilité |
|---------|---------------|
| `src/Entity/ProjectMailerSettings.php` | Config SMTP par projet (host/port/user/password chiffré/from) |
| `src/Repository/ProjectMailerSettingsRepository.php` | Accès DB |
| `src/Entity/EmailLog.php` | Log d'audit des emails envoyés |
| `src/Repository/EmailLogRepository.php` | Accès DB |
| `src/Service/ProjectMailerService.php` | Transport SMTP dynamique, chiffrement/déchiffrement, envoi async |
| `src/Message/SendProjectEmailMessage.php` | Message Messenger |
| `src/MessageHandler/SendProjectEmailMessageHandler.php` | Handler async |
| `src/Controller/Api/CaptchaController.php` | GET captcha stateless (image + token) |
| `src/Controller/Api/ProjectEmailController.php` | POST email (contact) |
| `src/Controller/Admin/ProjectMailerSettingsController.php` | CRUD admin + test SMTP |
| `tests/Service/ProjectMailerServiceTest.php` | Tests unitaires |

### Modifiés
| Fichier | Modification |
|---------|-------------|
| `config/packages/rate_limiter.yaml` | + `captcha` + `project_email` limiters |
| `config/packages/messenger.yaml` | + `SendProjectEmailMessage` routing |
| `config/services.yaml` | + `ProjectMailerService` |
| `translations/messages.fr.php` | + `workbench.mailer.*` + `workbench.captcha.*` |
| `translations/messages.en.php` | idem |
| `translations/messages.es.php` | idem |
| `translations/messages.ar.php` | idem |

---

## Task 1: Entités ProjectMailerSettings + EmailLog

- [ ] **Step 1: Créer ProjectMailerSettings**
```php
// src/Entity/ProjectMailerSettings.php
#[ORM\Entity(repositoryClass: ProjectMailerSettingsRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProjectMailerSettings
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    public string $host = '';

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 65535)]
    public int $port = 587;

    #[ORM\Column(length: 255)]
    public string $username = '';

    #[ORM\Column(type: 'text')]
    public string $encryptedPassword = '';

    #[ORM\Column(length: 10)]
    #[Assert\Choice(['tls', 'ssl', 'none'])]
    public string $encryption = 'tls';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $fromEmail = '';

    #[ORM\Column(length: 255)]
    public string $fromName = '';

    #[ORM\Column]
    public bool $enabled = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct() { /* ... */ }
    #[ORM\PreUpdate] public function touch(): void { /* ... */ }
}
```

- [ ] **Step 2: Créer ProjectMailerSettingsRepository**
```php
// src/Repository/ProjectMailerSettingsRepository.php
class ProjectMailerSettingsRepository extends ServiceEntityRepository
{
    public function findByProject(Project $project): ?ProjectMailerSettings { /* ... */ }
}
```

- [ ] **Step 3: Créer EmailLog**
- [ ] **Step 4: Créer EmailLogRepository**
- [ ] **Step 5: Générer + appliquer migration** (`doctrine:migrations:diff`)

---

## Task 2: ProjectMailerService (chiffrement + transport dynamique)

- [ ] **Step 1: Écrire le test (red)**
- [ ] **Step 2: Implémenter ProjectMailerService**

Points clés :
- `encrypt(string $plaintext): string` — AES-256-GCM via `sodium_crypto_aead_aes256gcm_encrypt`
- `decrypt(string $encrypted): string` — reverse
- `send(Project, to, subject, body, replyTo?): void` — construit le DSN SMTP, dispatch `SendProjectEmailMessage`
- Le DSN est construit dynamiquement : `smtp://user:pass@host:port?encryption=tls`
- `getSettings(Project): ?ProjectMailerSettings` — depuis le repo

- [ ] **Step 3: Tests green**
- [ ] **Step 4: Register dans services.yaml**
- [ ] **Step 5: Commit**

---

## Task 3: Message + Handler async

- [ ] **Step 1: Créer SendProjectEmailMessage**
- [ ] **Step 2: Créer SendProjectEmailMessageHandler**
- [ ] **Step 3: Configurer messenger.yaml**
- [ ] **Step 4: Commit**

---

## Task 4: CaptchaController (stateless)

- [ ] **Step 1: Implémenter GET /api/{projectUuid}/captcha**

```php
class CaptchaController extends AbstractController
{
    #[Route('/api/{projectUuid}/captcha', methods: ['GET'])]
    public function captcha(string $projectUuid, Request $request): JsonResponse
    {
        // 1. Résoudre le projet (vérifier qu'il existe)
        // 2. Rate limit via limiter.captcha
        // 3. Générer image gregwar
        // 4. Générer token (random_bytes 16)
        // 5. Stocker réponse dans le cache: captcha.{token} = réponse, TTL 300s
        // 6. Retourner {token, image: base64}
    }
}
```

- [ ] **Step 2: Configurer rate_limiter.yaml** (+ `captcha` factory)
- [ ] **Step 3: Commit**

---

## Task 5: ProjectEmailController (endpoint contact)

- [ ] **Step 1: Implémenter POST /api/{projectUuid}/email**

```php
class ProjectEmailController extends AbstractController
{
    #[Route('/api/{projectUuid}/email', methods: ['POST'])]
    public function send(string $projectUuid, Request $request): JsonResponse
    {
        // 1. Résoudre le projet
        // 2. Rate limit via limiter.project_email
        // 3. Valider le body (subject, body requis)
        // 4. Valider captchaToken + captchaAnswer via cache
        // 5. Supprimer l'entrée cache (usage unique)
        // 6. Mode (A) : to = ProjectMailerSettings.fromEmail
        // 7. Appeler ProjectMailerService::send()
        // 8. Retourner {sent: true}
    }
}
```

- [ ] **Step 2: Configurer rate_limiter.yaml** (+ `project_email` factory)
- [ ] **Step 3: Commit**

---

## Task 6: CRUD Admin + test SMTP

- [ ] **Step 1: Implémenter ProjectMailerSettingsController**

| Route | Description |
|-------|------------|
| `GET /api/admin/projects/{uuid}/mailer` | Lire les settings (sans password) |
| `PUT /api/admin/projects/{uuid}/mailer` | Mettre à jour (password vide = inchangé) |
| `POST /api/admin/projects/{uuid}/mailer/test` | Envoyer email de test |

- [ ] **Step 2: Commit**

---

## Task 7: i18n

- [ ] **Step 1: Ajouter clés FR/EN/ES/AR**

Clés :
- `workbench.mailer.title`
- `workbench.mailer.host`, `.port`, `.username`, `.password`, `.encryption`, `.from_email`, `.from_name`
- `workbench.mailer.test`, `.test_sent`, `.test_error`
- `workbench.captcha.placeholder`, `.invalid`
- `workbench.errors.mailer_not_configured`, `.captcha_invalid`

---

## Task 8: Validation finale

- [ ] **Step 1: Tests complets** (`php vendor/bin/phpunit`)
- [ ] **Step 2: Container lint + routes**
- [ ] **Step 3: PHP syntax check**
- [ ] **Step 4: Commit final**

---

## Self-Review

| Req spec | Task |
|---|---|
| ProjectMailerSettings (host/port/user/password chiffré) | T1 |
| ProjectMailerService (transport dynamique, AES-256-GCM) | T2 |
| Envoi async via Messenger | T3 |
| Captcha stateless (cache + token) | T4 |
| Rate limiting | T4, T5 |
| Endpoint email contact protégé | T5 |
| CRUD admin + test SMTP | T6 |
| i18n FR/EN/ES/AR | T7 |
| Validation | T8 |
