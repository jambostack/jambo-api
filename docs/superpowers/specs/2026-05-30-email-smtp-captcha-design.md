# Jambo Email/SMTP + Captcha — Design Spec

> Date : 2026-05-30
> Statut : à valider
> Dépend de : Jambo Sites (implémenté)
> Suite à : [Jambo Platform Vision](../jambo-platform-vision.html)

## Objectif

Permettre aux apps hébergées dans Jambo d'envoyer des emails (formulaire de contact, notifications…) via le **SMTP configuré par le propriétaire du projet**, protégé par un **captcha stateless** + **rate limiting**. Le tout sans infrastructure externe — Jambo est le backend complet.

---

## 1. Architecture

```
┌──────────────────────────────────────────────────────┐
│  Front statique (app générée par le Workbench)        │
│  GET /api/{projectUuid}/captcha  → image + token     │
│  POST /api/{projectUuid}/email    → envoi async       │
└──────────────────────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────┐
│  Jambo (Symfony 8)                                    │
│                                                       │
│  CaptchaController  →  gregwar/captcha (stateless)    │
│  EmailController    →  ProjectMailerService            │
│  RateLimiter        →  limiter.project_email           │
│                                                       │
│  ProjectMailerSettings (entité)                        │
│    host, port, username, password (chiffré),          │
│    encryption, fromEmail, fromName                     │
│                                                       │
│  ProjectMailerService                                  │
│    Transport::fromDsn() → MailerInterface              │
│    → async (Messenger)                                │
└──────────────────────────────────────────────────────┘
```

---

## 2. Entités

### 2.1 ProjectMailerSettings

```php
#[ORM\Entity]
#[ORM\Table(name: 'project_mailer_settings')]
class ProjectMailerSettings
{
    #[ORM\Id, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 255)]
    public string $host = '';

    #[ORM\Column]
    public int $port = 587;

    #[ORM\Column(length: 255)]
    public string $username = '';

    /** Chiffré avec APP_SECRET (AES-256-GCM) — jamais exposé en clair dans l'API. */
    #[ORM\Column(type: 'text')]
    public string $encryptedPassword = '';

    #[ORM\Column(length: 10)]
    public string $encryption = 'tls'; // tls, ssl, none

    #[ORM\Column(length: 255)]
    public string $fromEmail = '';

    #[ORM\Column(length: 255)]
    public string $fromName = '';

    #[ORM\Column]
    public bool $enabled = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;
}
```

**Chiffrement password** : AES-256-GCM avec `APP_SECRET` comme clé (via `Symfony\Component\Encryption\EncryptorInterface` si dispo, sinon gestion manuelle avec `sodium_crypto_aead_aes256gcm_*`).

### 2.2 EmailLog (optionnel, pour audit)

```php
#[ORM\Entity]
class EmailLog
{
    #[ORM\Id, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    public Project $project;

    #[ORM\Column(length: 255)]
    public string $to;

    #[ORM\Column(length: 255)]
    public string $subject;

    #[ORM\Column]
    public \DateTimeImmutable $sentAt;

    /** null = succès, string = message d'erreur */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $error = null;
}
```

---

## 3. API Endpoints

### 3.1 Captcha `GET /api/{projectUuid}/captcha`

- **Auth** : aucune (endpoint public)
- **Rate limit** : `limiter.captcha` (5/min par IP)
- **Réponse** :
```json
{
  "token": "abc123...",
  "image": "data:image/jpeg;base64,..."
}
```
- **Implémentation** : génère une image via `gregwar/captcha`, stocke la réponse attendue dans le **cache Symfony** avec clé `captcha.{token}`, TTL 5 minutes, usage unique (supprimé après validation).
- **Stateless** : pas de session PHP. Le front envoie le token + la réponse utilisateur.

### 3.2 Email `POST /api/{projectUuid}/email`

- **Auth** : aucune (endpoint public — formulaire de contact)
- **Rate limit** : `limiter.project_email` (3/min par IP + 20/min par projet)
- **Body** :
```json
{
  "to": "contact@example.com",      // ignoré en mode (A) — destinataire fixe
  "subject": "Message du formulaire",
  "body": "Contenu de l'email",
  "replyTo": "user@example.com",     // optionnel
  "captchaToken": "abc123...",
  "captchaAnswer": "k8x2"
}
```
- **Validation captcha** : vérifie `captcha.{token}` dans le cache, compare la réponse (case-insensitive), supprime l'entrée cache.
- **Mode (A) — formulaire de contact** : le `to` est ignoré, l'email est envoyé à l'adresse de contact configurée (`ProjectMailerSettings.fromEmail`).
- **Mode (B) — authentifié** (futur) : le `to` est utilisé si l'utilisateur est authentifié via JWT end-user.
- **Réponse** :
```json
{ "sent": true }
```
- **Implémentation** : `ProjectMailerService::send(project, to, subject, body)` → construit le transport SMTP depuis `ProjectMailerSettings`, envoie via Messenger.

### 3.3 Admin SMTP Settings (CRUD)

Routes gérées via le contrôleur Admin existant :

| Méthode | Route | Permission |
|---------|-------|------------|
| `GET` | `/api/admin/projects/{uuid}/mailer` | `project.manage` |
| `PUT` | `/api/admin/projects/{uuid}/mailer` | `project.manage` |
| `POST` | `/api/admin/projects/{uuid}/mailer/test` | `project.manage` |

- `GET` : retourne les settings (sans le password).
- `PUT` : met à jour les settings. Si `password` est vide, conserve l'ancien.
- `POST test` : envoie un email de test à l'adresse configurée.

---

## 4. Service ProjectMailerService

```php
class ProjectMailerService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private string $appSecret,
    ) {}

    /**
     * Envoie un email via le SMTP du projet, de manière asynchrone.
     */
    public function send(Project $project, string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        $settings = $this->getSettings($project);
        if ($settings === null || !$settings->enabled) {
            throw new MailerNotConfiguredException();
        }

        $password = $this->decrypt($settings->encryptedPassword);

        // Dispatch async via Messenger
        $this->bus->dispatch(new SendProjectEmailMessage(
            $settings->host,
            $settings->port,
            $settings->username,
            $password,
            $settings->encryption,
            $settings->fromEmail,
            $settings->fromName,
            $to,
            $subject,
            $body,
            $replyTo,
            $project->id,
        ));
    }

    private function getSettings(Project $project): ?ProjectMailerSettings { /* ... */ }
    private function decrypt(string $encrypted): string { /* AES-256-GCM */ }
    private function encrypt(string $plaintext): string { /* AES-256-GCM */ }
}
```

---

## 5. Rate Limiting

Deux factories enregistrées dans `config/packages/rate_limiter.yaml` :

```yaml
framework:
    rate_limiter:
        captcha:
            policy: sliding_window
            limit: 5
            interval: 1 minute
        project_email:
            policy: sliding_window
            limit: 3
            interval: 1 minute
```

Les limites par projet sont gérées dans le code (compteur cache par `project_id`).

---

## 6. Garde-fous

| Garde-fou | Implémentation |
|-----------|----------------|
| 🔒 Mot de passe SMTP chiffré au repos | AES-256-GCM avec `APP_SECRET` |
| 🔒 Pas de relais ouvert | Mode (A) : destinataire fixe = `fromEmail` |
| 🔒 Rate limiting IP + projet | `limiter.project_email` + compteur cache |
| 🔒 Captcha obligatoire | Token à usage unique, TTL 5 min, supprimé après validation |
| 🔒 Défense en profondeur | Captcha + rate limit + *honeypot* (champ caché dans le formulaire) |

---

## 7. Fichiers créés/modifiés

### Créés

| Fichier | Responsabilité |
|---------|---------------|
| `src/Entity/ProjectMailerSettings.php` | Entité de config SMTP par projet |
| `src/Entity/EmailLog.php` | Log d'audit des emails envoyés |
| `src/Service/ProjectMailerService.php` | Service d'envoi (transport dynamique, chiffrement) |
| `src/Message/SendProjectEmailMessage.php` | Message Messenger async |
| `src/MessageHandler/SendProjectEmailMessageHandler.php` | Handler async |
| `src/Controller/Api/CaptchaController.php` | Endpoint captcha stateless |
| `src/Controller/Api/ProjectEmailController.php` | Endpoint email |
| `src/Controller/Admin/ProjectMailerSettingsController.php` | CRUD admin SMTP |
| `tests/Service/ProjectMailerServiceTest.php` | Tests unitaires |

### Modifiés

| Fichier | Modification |
|---------|-------------|
| `config/packages/rate_limiter.yaml` | + captcha, project_email limiters |
| `config/packages/messenger.yaml` | + SendProjectEmailMessage routing |
| `config/services.yaml` | + ProjectMailerService |

---

## 8. Non-périmètre (YAGNI)

- Templates d'email (le front envoie le body déjà formaté)
- Pièces jointes
- Mode (B) authentifié / Mode (C) hybride (futur)
- File d'attente d'emails avec statut (juste async + EmailLog)
- DKIM/SPF (c'est le SMTP configuré qui les gère)
- Stripe / paiement (roadmap #3)
