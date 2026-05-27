# EndUser — Gestion d'utilisateurs et authentification JWT par projet

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Chaque projet JamboAPI expose nativement une gestion d'utilisateurs finaux (`EndUser`) avec authentification JWT (register, login, logout, forgot-password, reset-password, me), isolée des utilisateurs CMS.

**Architecture:** Nouvelle entité `EndUser` liée à `Project`, hachage de mot de passe via `PasswordHasherInterface`, JWT via `lcobucci/jwt` sans bundle, endpoints REST sous `/api/{projectId}/auth/*`, firewall dédié sans session.

**Tech Stack:** PHP 8.4, Symfony 8.0, Doctrine ORM, lcobucci/jwt ^5.0, Symfony PasswordHasherInterface, PHPUnit 13.1, React 19 + Inertia.js

---

### Task 1: Installer lcobucci/jwt

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Installer la librairie JWT**

Run: `composer require lcobucci/jwt ^5.0`

Expected: Package installé, `composer.json` et `composer.lock` mis à jour.

- [ ] **Step 2: Vérifier l'installation**

Run: `php bin/console cache:clear`
Expected: No errors.

---

### Task 2: Créer l'entité EndUser et migration

**Files:**
- Create: `src/Entity/EndUser.php`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (auto-generated)

- [ ] **Step 1: Créer l'entité EndUser**

```php
<?php

namespace App\Entity;

use App\Repository\EndUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EndUserRepository::class)]
#[ORM\HasLifecycleCallbacks]
class EndUser implements PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 180)]
    public string $email;

    #[ORM\Column(length: 255)]
    public string $password;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $avatarUrl = null;

    #[ORM\Column(length: 20)]
    public string $status = 'active'; // active | banned | pending

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $customFields = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'integer')]
    public int $tokenVersion = 1;

    public function __construct(Project $project, string $email)
    {
        $this->project = $project;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->tokenVersion = 1;
    }

    #[ORM\PrePersist]
    public function setUuid(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }

    /** Required by PasswordAuthenticatedUserInterface */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
```

- [ ] **Step 2: Créer le repository**

```php
<?php

namespace App\Repository;

use App\Entity\EndUser;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EndUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EndUser::class);
    }

    public function findOneByProjectAndEmail(Project $project, string $email): ?EndUser
    {
        return $this->findOneBy(['project' => $project, 'email' => $email]);
    }

    /** @return EndUser[] */
    public function findByProject(Project $project, string $status = null): array
    {
        $criteria = ['project' => $project];
        if ($status !== null) {
            $criteria['status'] = $status;
        }
        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }
}
```

- [ ] **Step 3: Générer et exécuter la migration**

Run: `php bin/console make:migration`
Expected: Nouveau fichier dans `migrations/`.

Run: `php bin/console doctrine:migrations:migrate`
Expected: Table `end_user` créée.

---

### Task 3: Créer le service JWT (EndUserJwtService)

**Files:**
- Create: `src/Service/EndUserJwtService.php`

- [ ] **Step 1: Créer le service JWT**

```php
<?php

namespace App\Service;

use App\Entity\EndUser;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;

class EndUserJwtService
{
    private Configuration $config;

    public function __construct(
        private string $appSecret,
        private ClockInterface $clock,
    ) {
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($appSecret),
        );
    }

    /** Generate access token (15 min) */
    public function createAccessToken(EndUser $endUser): string
    {
        $now = $this->clock->now();
        return $this->config->builder()
            ->issuedBy('jamboapi')
            ->permittedFor('jamboapi')
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->expiresAt($now->modify('+15 minutes'))
            ->withClaim('euid', $endUser->uuid?->toString())
            ->withClaim('pid', $endUser->project->uuid?->toString())
            ->withClaim('tkn', $endUser->tokenVersion)
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }

    /** Generate refresh token (30 days) */
    public function createRefreshToken(EndUser $endUser): string
    {
        $now = $this->clock->now();
        return $this->config->builder()
            ->issuedBy('jamboapi')
            ->permittedFor('jamboapi')
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->expiresAt($now->modify('+30 days'))
            ->withClaim('euid', $endUser->uuid?->toString())
            ->withClaim('pid', $endUser->project->uuid?->toString())
            ->withClaim('tkn', $endUser->tokenVersion)
            ->withClaim('ref', true)
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }

    /** Validate and parse a token. Returns claims or null on failure. */
    public function validateToken(string $jwt): ?array
    {
        try {
            $token = $this->config->parser()->parse($jwt);
            $constraints = [
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new StrictValidAt($this->clock),
            ];
            $this->config->validator()->assert($token, ...$constraints);
            return [
                'euid' => $token->claims()->get('euid'),
                'pid'  => $token->claims()->get('pid'),
                'tkn'  => $token->claims()->get('tkn'),
                'ref'  => $token->claims()->get('ref'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function isRefreshToken(array $claims): bool
    {
        return ($claims['ref'] ?? false) === true;
    }
}
```

- [ ] **Step 2: Enregistrer le service dans config/services.yaml**

Modify: `config/services.yaml` — ajouter :

```yaml
    App\Service\EndUserJwtService:
        arguments:
            $appSecret: '%kernel.secret%'
```

- [ ] **Step 3: Vérifier la compilation**

Run: `php bin/console cache:clear`
Expected: No errors.

---

### Task 4: Créer l'authenticator JWT pour EndUser

**Files:**
- Create: `src/Security/EndUserJwtAuthenticator.php`

- [ ] **Step 1: Créer l'authenticator**

```php
<?php

namespace App\Security;

use App\Repository\EndUserRepository;
use App\Service\EndUserJwtService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class EndUserJwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private EndUserJwtService $jwtService,
        private EndUserRepository $endUserRepository,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization')
            && str_starts_with($request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $header = $request->headers->get('Authorization', '');
        $jwt = substr($header, 7);

        $claims = $this->jwtService->validateToken($jwt);
        if ($claims === null) {
            throw new AuthenticationException('Invalid or expired token.');
        }

        // Refresh tokens cannot be used for authentication
        if ($this->jwtService->isRefreshToken($claims)) {
            throw new AuthenticationException('Refresh tokens cannot be used for API access.');
        }

        $endUserUuid = $claims['euid'];
        $tokenVersion = $claims['tkn'];

        return new SelfValidatingPassport(
            new UserBadge($endUserUuid, function ($uuid) use ($tokenVersion) {
                $endUser = $this->endUserRepository->findOneBy(['uuid' => $uuid]);
                if ($endUser === null || !$endUser->isActive()) {
                    throw new AuthenticationException('User not found or inactive.');
                }
                if ($endUser->tokenVersion !== $tokenVersion) {
                    throw new AuthenticationException('Token version mismatch - please re-login.');
                }
                return $endUser;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Attach EndUser to request attributes for controllers
        $request->attributes->set('_end_user', $token->getUser());
        return null; // Continue to controller
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => $exception->getMessage(),
        ], Response::HTTP_UNAUTHORIZED);
    }
}
```

- [ ] **Step 2: Vérifier la compilation**

Run: `php bin/console cache:clear`
Expected: No errors.

---

### Task 5: Ajouter le firewall end_user à security.yaml

**Files:**
- Modify: `config/packages/security.yaml`

- [ ] **Step 1: Ajouter le firewall end_user**

Modifier `config/packages/security.yaml` — remplacer le contenu par :

```yaml
security:
    password_hashers:
        App\Entity\User: { algorithm: auto }
        App\Entity\EndUser: { algorithm: auto }

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email
        end_user_provider:
            entity:
                class: App\Entity\EndUser
                property: email

    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)
            security: false
        end_user:
            pattern: ^/api/[\w-]{36}/auth
            stateless: true
            provider: end_user_provider
            custom_authenticators:
                - App\Security\EndUserJwtAuthenticator
        end_user_public:
            pattern: ^/api/[\w-]{36}/auth/(register|login|forgot-password|reset-password)
            stateless: true
            security: false
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: app_login
                check_path: app_login
                default_target_path: app_home
                username_parameter: email
                password_parameter: password
                enable_csrf: true
            login_throttling:
                max_attempts: 5
                interval: '60 seconds'
            logout:
                path: app_logout
                target: app_login
    access_control:
        - { path: ^/api/[\w-]{36}/auth/(register|login|forgot-password|reset-password), roles: PUBLIC_ACCESS }
        - { path: ^/api/[\w-]{36}/auth, roles: PUBLIC_ACCESS }  # JWT handles auth
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/register, roles: PUBLIC_ACCESS }
        - { path: ^/forgot-password, roles: PUBLIC_ACCESS }
        - { path: ^/reset-password, roles: PUBLIC_ACCESS }
        - { path: ^/public-api, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: PUBLIC_ACCESS }
        - { path: ^/, roles: ROLE_USER }
```

- [ ] **Step 2: Vérifier que les routes auth CMS fonctionnent encore**

Run: `php bin/console cache:clear`
Run: `php bin/console debug:firewall`
Expected: 4 firewalls listés (dev, end_user, end_user_public, main).

---

### Task 6: Créer le contrôleur d'authentification EndUser

**Files:**
- Create: `src/Controller/Api/EndUserAuthController.php`
- Create: `tests/Controller/Api/EndUserAuthControllerTest.php` (test fonctionnel)

- [ ] **Step 1: Créer le contrôleur**

```php
<?php

namespace App\Controller\Api;

use App\Entity\EndUser;
use App\Entity\PasswordResetToken;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Service\EndUserJwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/{projectId}/auth', name: 'enduser_auth_',
    requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
class EndUserAuthController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserRepository $endUserRepository,
        private EndUserJwtService $jwtService,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
    ) {}

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project || !$project->publicApi) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $name = $data['name'] ?? null;

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Valid email is required'], 422);
        }
        if (strlen($password) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], 422);
        }

        $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if ($existing) {
            return $this->json(['error' => 'Email already registered'], 409);
        }

        $endUser = new EndUser($project, $email);
        $endUser->name = $name;
        $endUser->password = $this->hasher->hashPassword($endUser, $password);

        $this->em->persist($endUser);
        $this->em->flush();

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->jwtService->createRefreshToken($endUser);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ], 201);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project || !$project->publicApi) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->json(['error' => 'Email and password are required'], 422);
        }

        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if (!$endUser || !$this->hasher->isPasswordValid($endUser, $password)) {
            return $this->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$endUser->isActive()) {
            return $this->json(['error' => 'Account is ' . $endUser->status], 403);
        }

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->jwtService->createRefreshToken($endUser);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request, string $projectId): JsonResponse
    {
        $data = $request->toArray();
        $refreshJwt = $data['refresh_token'] ?? '';

        if (empty($refreshJwt)) {
            return $this->json(['error' => 'Refresh token is required'], 422);
        }

        $claims = $this->jwtService->validateToken($refreshJwt);
        if ($claims === null || !$this->jwtService->isRefreshToken($claims)) {
            return $this->json(['error' => 'Invalid or expired refresh token'], 401);
        }

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $claims['euid']]);
        if (!$endUser || !$endUser->isActive()) {
            return $this->json(['error' => 'User not found or inactive'], 401);
        }

        if ($endUser->tokenVersion !== $claims['tkn']) {
            return $this->json(['error' => 'Token has been revoked'], 401);
        }

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->jwtService->createRefreshToken($endUser);

        return $this->json([
            'data' => [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    #[Route('/me', name: 'me_update', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $endUser->name = $data['name'];
        }
        if (isset($data['custom_fields'])) {
            $endUser->customFields = $data['custom_fields'];
        }
        if (isset($data['password'])) {
            if (strlen($data['password']) < 8) {
                return $this->json(['error' => 'Password must be at least 8 characters'], 422);
            }
            $endUser->password = $this->hasher->hashPassword($endUser, $data['password']);
            $endUser->tokenVersion++; // Invalidate all existing tokens
        }

        $endUser->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if ($endUser instanceof EndUser) {
            $endUser->tokenVersion++; // Invalidate all existing tokens
            $this->em->flush();
        }

        return $this->json(null, 204);
    }

    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $email = $data['email'] ?? '';

        if (empty($email)) {
            return $this->json(['error' => 'Email is required'], 422);
        }

        // Always return success to prevent email enumeration
        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if ($endUser) {
            // Delete old tokens and create new
            $this->em->getRepository(PasswordResetToken::class)
                ->createQueryBuilder('t')
                ->delete()
                ->where('t.email = :email')
                ->setParameter('email', $email)
                ->getQuery()
                ->execute();

            $token = new PasswordResetToken($email);
            $this->em->persist($token);
            $this->em->flush();

            // TODO: send email with reset link
        }

        return $this->json(['message' => 'If the email exists, a reset link has been sent.']);
    }

    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $tokenStr = $data['token'] ?? '';
        $newPassword = $data['password'] ?? '';

        if (empty($tokenStr) || empty($newPassword)) {
            return $this->json(['error' => 'Token and password are required'], 422);
        }
        if (strlen($newPassword) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], 422);
        }

        $token = $this->em->getRepository(PasswordResetToken::class)
            ->findOneBy(['token' => $tokenStr]);

        if (!$token || $token->isExpired()) {
            return $this->json(['error' => 'Invalid or expired token'], 400);
        }

        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $token->email);
        if (!$endUser) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $endUser->password = $this->hasher->hashPassword($endUser, $newPassword);
        $endUser->tokenVersion++; // Invalidate all existing tokens
        $endUser->updatedAt = new \DateTimeImmutable();
        $this->em->remove($token);
        $this->em->flush();

        return $this->json(['message' => 'Password has been reset successfully.']);
    }

    private function serializeEndUser(EndUser $eu): array
    {
        return [
            'uuid'          => $eu->uuid?->toString(),
            'email'         => $eu->email,
            'name'          => $eu->name,
            'status'        => $eu->status,
            'avatar_url'    => $eu->avatarUrl,
            'custom_fields' => $eu->customFields,
            'created_at'    => $eu->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'    => $eu->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

- [ ] **Step 2: Vérifier les routes**

Run: `php bin/console cache:clear`
Run: `php bin/console debug:router | findstr enduser_auth`
Expected: 9 routes listées (register, login, logout, refresh, me, me_update, forgot_password, reset_password).

---

### Task 7: Créer le contrôleur admin de gestion EndUser

**Files:**
- Create: `src/Controller/Api/EndUserAdminController.php`

- [ ] **Step 1: Créer le contrôleur admin**

```php
<?php

namespace App\Controller\Api;

use App\Entity\EndUser;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/{projectId}/users', name: 'enduser_admin_',
    requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
class EndUserAdminController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserRepository $endUserRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, string $projectId): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $project = $endUser->project;
        $status = $request->query->get('status');

        $users = $this->endUserRepository->findByProject($project, $status);

        return $this->json([
            'data' => array_map(fn (EndUser $u) => $this->serializeEndUser($u), $users),
        ]);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(Request $request, string $uuid): JsonResponse
    {
        $endUser = $this->resolveEndUser($request, $uuid);
        if ($endUser instanceof JsonResponse) return $endUser;

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    #[Route('/{uuid}/status', name: 'status', methods: ['PATCH'])]
    public function status(Request $request, string $uuid): JsonResponse
    {
        $endUser = $this->resolveEndUser($request, $uuid);
        if ($endUser instanceof JsonResponse) return $endUser;

        $data = $request->toArray();
        $newStatus = $data['status'] ?? '';
        if (!in_array($newStatus, ['active', 'banned', 'pending'], true)) {
            return $this->json(['error' => 'Invalid status'], 422);
        }

        $endUser->status = $newStatus;
        if ($newStatus === 'banned') {
            $endUser->tokenVersion++; // Invalidate tokens
        }
        $endUser->updatedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    private function resolveEndUser(Request $request, string $uuid): EndUser|JsonResponse
    {
        $admin = $request->attributes->get('_end_user');
        if (!$admin instanceof EndUser) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $target = $this->endUserRepository->findOneBy(['uuid' => $uuid, 'project' => $admin->project]);
        if (!$target) {
            return $this->json(['error' => 'User not found'], 404);
        }

        return $target;
    }

    private function serializeEndUser(EndUser $eu): array
    {
        return [
            'uuid'          => $eu->uuid?->toString(),
            'email'         => $eu->email,
            'name'          => $eu->name,
            'status'        => $eu->status,
            'avatar_url'    => $eu->avatarUrl,
            'custom_fields' => $eu->customFields,
            'created_at'    => $eu->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'    => $eu->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

- [ ] **Step 2: Vérifier les routes**

Run: `php bin/console cache:clear`
Run: `php bin/console debug:router | findstr enduser_admin`
Expected: 3 routes listées (index, show, status).

---

### Task 8: Tests — EndUserAuthController

**Files:**
- Create: `tests/Controller/Api/EndUserAuthControllerTest.php`

- [ ] **Step 1: Écrire le test d'intégration**

```php
<?php

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EndUserAuthControllerTest extends WebTestCase
{
    public function testRegisterCreatesEndUserAndReturnsTokens(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/register', [
            'email' => 'testuser@example.com',
            'password' => 'password123',
            'name' => 'Test User',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
        $this->assertSame('testuser@example.com', $data['data']['user']['email']);
    }

    public function testLoginReturnsTokens(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/login', [
            'email' => 'testuser@example.com',
            'password' => 'password123',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data['data']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/login', [
            'email' => 'testuser@example.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsUserWithValidToken(): void
    {
        $client = static::createClient();

        // First login
        $client->jsonRequest('POST', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/login', [
            'email' => 'testuser@example.com',
            'password' => 'password123',
        ]);
        $token = json_decode($client->getResponse()->getContent(), true)['data']['access_token'];

        // Then access /me
        $client->jsonRequest('GET', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/me', [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('testuser@example.com', $data['data']['email']);
    }

    public function testMeReturns401WithoutToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/me');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRegisterWithInvalidEmailReturns422(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/register', [
            'email' => 'notanemail',
            'password' => 'password123',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithShortPasswordReturns422(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/register', [
            'email' => 'test2@example.com',
            'password' => '123',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }
}
```

- [ ] **Step 2: Lancer les tests**

Run: `php vendor/bin/phpunit tests/Controller/Api/EndUserAuthControllerTest.php`
Expected: 6 tests passent.

---

### Task 9: Tests — EndUserAdminController

**Files:**
- Create: `tests/Controller/Api/EndUserAdminControllerTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EndUserAdminControllerTest extends WebTestCase
{
    private function getToken(): string
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/auth/login', [
            'email' => 'testuser@example.com',
            'password' => 'password123',
        ]);
        return json_decode($client->getResponse()->getContent(), true)['data']['access_token'];
    }

    public function testIndexListsUsers(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/users', [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getToken(),
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data['data']);
        $this->assertGreaterThan(0, count($data['data']));
    }

    public function testIndexRequiresAuth(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/8bb7eb7c-81de-4ed4-b101-0b522be443d6/users');

        $this->assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 2: Lancer les tests**

Run: `php vendor/bin/phpunit tests/Controller/Api/EndUserAdminControllerTest.php`
Expected: 2 tests passent.

---

### Task 10: Tests — EndUserJwtService

**Files:**
- Create: `tests/Service/EndUserJwtServiceTest.php`

- [ ] **Step 1: Écrire le test unitaire**

```php
<?php

namespace App\Tests\Service;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Service\EndUserJwtService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

class EndUserJwtServiceTest extends TestCase
{
    public function testCreateAndValidateAccessToken(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('test-secret-key-for-jwt', $clock);

        $project = new Project();
        $project->uuid = Uuid::v4();

        $endUser = new EndUser($project, 'user@test.com');
        // Force UUID via reflection
        $r = new \ReflectionClass($endUser);
        $p = $r->getProperty('uuid');
        $p->setAccessible(true);
        $p->setValue($endUser, Uuid::v4());

        $token = $service->createAccessToken($endUser);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $claims = $service->validateToken($token);
        $this->assertNotNull($claims);
        $this->assertSame($endUser->uuid->toString(), $claims['euid']);
        $this->assertSame($project->uuid->toString(), $claims['pid']);
        $this->assertFalse($service->isRefreshToken($claims));
    }

    public function testRefreshTokenHasRefClaim(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('test-secret', $clock);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $endUser = new EndUser($project, 'user@test.com');
        $r = new \ReflectionClass($endUser);
        $p = $r->getProperty('uuid');
        $p->setAccessible(true);
        $p->setValue($endUser, Uuid::v4());

        $token = $service->createRefreshToken($endUser);
        $claims = $service->validateToken($token);
        $this->assertTrue($service->isRefreshToken($claims));
    }

    public function testInvalidTokenReturnsNull(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());
        $service = new EndUserJwtService('test-secret', $clock);

        $this->assertNull($service->validateToken('invalid.jwt.token'));
    }
}
```

- [ ] **Step 2: Lancer les tests**

Run: `php vendor/bin/phpunit tests/Service/EndUserJwtServiceTest.php`
Expected: 3 tests passent.

---

## Ordre d'exécution

1. Task 1 → Installer lcobucci/jwt
2. Task 2 → Créer EndUser entity + migration
3. Task 3 → Créer EndUserJwtService
4. Task 4 → Créer EndUserJwtAuthenticator
5. Task 5 → Modifier security.yaml (nouveau firewall)
6. Task 6 → Créer EndUserAuthController + test
7. Task 7 → Créer EndUserAdminController + test
8. Task 8 → Tests auth controller (dépend de Task 6)
9. Task 9 → Tests admin controller (dépend de Task 7)
10. Task 10 → Tests unitaires JWT (dépend de Task 3)

### Résumé des endpoints créés

| Méthode | Endpoint | Auth |
|---|---|---|
| `POST` | `/api/{projectId}/auth/register` | Public |
| `POST` | `/api/{projectId}/auth/login` | Public |
| `POST` | `/api/{projectId}/auth/logout` | JWT |
| `POST` | `/api/{projectId}/auth/refresh` | Public (avec refresh token) |
| `POST` | `/api/{projectId}/auth/forgot-password` | Public |
| `POST` | `/api/{projectId}/auth/reset-password` | Public |
| `GET` | `/api/{projectId}/auth/me` | JWT |
| `PATCH` | `/api/{projectId}/auth/me` | JWT |
| `GET` | `/api/{projectId}/users` | JWT |
| `GET` | `/api/{projectId}/users/{uuid}` | JWT |
| `PATCH` | `/api/{projectId}/users/{uuid}/status` | JWT (admin) |
