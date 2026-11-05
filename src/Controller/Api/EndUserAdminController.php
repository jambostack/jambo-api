<?php

namespace App\Controller\Api;

use App\Entity\EndUser;
use App\Repository\EndUserRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\EndUserCustomFieldsSanitizer;
use App\Service\ProjectAuthLockoutService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Controller\Api\ProjectAwareControllerTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin CRUD for EndUsers — JSON API used by admin UI (Inertia) AND CRM apps via ApiToken.
 */
#[Route('/api/projects/{uuid}/end-users', name: 'api_admin_end_users_')]
class EndUserAdminController extends AbstractController
{
    use ProjectAwareControllerTrait;

    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserRepository $endUserRepository,
        private ProjectMemberRepository $memberRepo,
        private EntityManagerInterface $em,
        private Security $security,
        private ApiTokenChecker $tokenChecker,
        private UserPasswordHasherInterface $hasher,
        private ?\App\Repository\ProjectAuthSessionRepository $sessionRepo = null,
        private ?\App\Repository\ProjectAuthRefreshTokenRepository $refreshTokenRepo = null,
        private ?TranslatorInterface $translator = null,
        private ?EndUserCustomFieldsSanitizer $customFieldsSanitizer = null,
        private ?ProjectAuthLockoutService $lockoutService = null,
        private ?AuditService $auditService = null,
    ) {
        $this->customFieldsSanitizer ??= new EndUserCustomFieldsSanitizer($this->translator);
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    // ─── LIST ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $status  = $request->query->get('status', '');
        $search  = $request->query->get('search', '');
        $uuids   = $request->query->all('uuids');
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->createQueryBuilder()
            ->select('eu')
            ->from(EndUser::class, 'eu')
            ->where('eu.project = :project')
            ->setParameter('project', $project)
            ->orderBy('eu.createdAt', 'DESC');

        // Résolution ciblée pour les champs relation : uuids[] court-circuite
        // la pagination (le nombre d'uuids demandés borne le résultat).
        if ($uuids !== []) {
            $uuidObjects = [];
            foreach (array_slice($uuids, 0, 100) as $u) {
                try {
                    $uuidObjects[] = \Symfony\Component\Uid\Uuid::fromString((string) $u)->toBinary();
                } catch (\InvalidArgumentException) {
                    // uuid mal formé → ignoré
                }
            }
            $qb->andWhere('eu.uuid IN (:uuids)')->setParameter('uuids', $uuidObjects);
            $perPage = max(count($uuidObjects), 1);
            $page = 1;
        }

        if ($status !== '' && in_array($status, ['active', 'banned', 'pending'], true)) {
            $qb->andWhere('eu.status = :status')->setParameter('status', $status);
        }
        if ($search !== '') {
            $qb->andWhere('eu.email LIKE :search OR eu.username LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(eu.id)')->getQuery()->getSingleScalarResult();

        $qb->setMaxResults($perPage)->setFirstResult(($page - 1) * $perPage);
        $endUsers = $qb->getQuery()->getResult();

        return $this->json([
            'data' => array_map(fn (EndUser $eu) => $this->serializeUser($eu), $endUsers),
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'pages'     => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    // ─── SHOW ────────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}', name: 'show', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function show(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => $this->trans('enduser.admin.not_found', [], 'Not found')], 404);

        return $this->json(['data' => $this->serializeUser($endUser)]);
    }

    // ─── CREATE ──────────────────────────────────────────────────────────────

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('enduser.admin.invalid_json', [], 'Invalid JSON')], 400);
        }
        $email    = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $rawUsername = $data['username'] ?? '';
        $username = trim((string) $rawUsername);
        $status   = (string) ($data['status'] ?? 'active');
        $customFields = $data['custom_fields'] ?? null;

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => $this->trans('enduser.admin.valid_email_required', [], 'Valid email is required')], 422);
        }
        if (strlen($password) < 6) {
            return $this->json(['error' => $this->trans('enduser.admin.password_min_length', ['%min%' => '6'], 'Password must be at least 6 characters')], 422);
        }
        if (!in_array($status, ['active', 'banned', 'pending'], true)) {
            return $this->json(['error' => $this->trans('enduser.admin.invalid_status', [], 'Invalid status')], 422);
        }
        if ($username !== '') {
            if (mb_strlen($username) > 100 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
                return $this->json(['error' => $this->trans('enduser.admin.invalid_username', [], 'Invalid username format or too long (max 100 characters).')], 422);
            }
        }

        $sanitizedCustomFields = null;
        if ($customFields !== null) {
            $sanitizer = $this->customFieldsSanitizer ?? new EndUserCustomFieldsSanitizer($this->translator);
            $cfRes = $sanitizer->sanitize($customFields);
            if (!$cfRes['valid']) {
                return $this->json(['error' => $cfRes['error']], 422);
            }
            $sanitizedCustomFields = $cfRes['sanitized'];
        }

        $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if ($existing) {
            return $this->json(['error' => $this->trans('enduser.admin.email_already_registered', [], 'Email already registered')], 409);
        }

        $endUser = new EndUser($project, $email);
        $endUser->username = $username !== '' ? $username : null;
        $endUser->status = $status;
        $endUser->password = $this->hasher->hashPassword($endUser, $password);
        if ($sanitizedCustomFields !== null) {
            $endUser->customFields = $sanitizedCustomFields;
        }

        $this->em->persist($endUser);
        $this->em->flush();

        return $this->json(['data' => $this->serializeUser($endUser)], 201);
    }

    // ─── UPDATE ──────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}', name: 'update', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['PATCH'])]
    public function update(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => $this->trans('enduser.admin.not_found', [], 'Not found')], 404);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('enduser.admin.invalid_json', [], 'Invalid JSON')], 400);
        }

        if (isset($data['email'])) {
            $email = trim((string) $data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => $this->trans('enduser.admin.invalid_email', [], 'Invalid email')], 422);
            }
            $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
            if ($existing && $existing->uuid->toString() !== $endUserUuid) {
                return $this->json(['error' => $this->trans('enduser.admin.email_already_taken', [], 'Email already taken')], 409);
            }
            $endUser->email = $email;
        }
        if (isset($data['username'])) {
            $cleanUser = trim((string) $data['username']);
            if ($cleanUser !== '') {
                if (mb_strlen($cleanUser) > 100 || preg_match('/[\x00-\x1F\x7F]/', $cleanUser)) {
                    return $this->json(['error' => $this->trans('enduser.admin.invalid_username', [], 'Invalid username format or too long (max 100 characters).')], 422);
                }
            }
            $endUser->username = $cleanUser ?: null;
        }
        if (isset($data['status'])) {
            $statusVal = (string) $data['status'];
            if (!in_array($statusVal, ['active', 'banned', 'pending'], true)) {
                return $this->json(['error' => $this->trans('enduser.admin.invalid_status', [], 'Invalid status')], 422);
            }
            $endUser->status = $statusVal;
            if ($statusVal === 'banned') {
                $endUser->tokenVersion++;
                $this->sessionRepo?->revokeAllForEndUser($endUser);
                $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
            }
        }
        if (isset($data['custom_fields'])) {
            $sanitizer = $this->customFieldsSanitizer ?? new EndUserCustomFieldsSanitizer($this->translator);
            $cfRes = $sanitizer->sanitize($data['custom_fields']);
            if (!$cfRes['valid']) {
                return $this->json(['error' => $cfRes['error']], 422);
            }
            $endUser->customFields = $cfRes['sanitized'];
        }
        // Reset password (admin-initiated)
        if (!empty($data['password'] ?? '')) {
            $pwd = (string) $data['password'];
            if (strlen($pwd) < 6) {
                return $this->json(['error' => $this->trans('enduser.admin.password_min_length', ['%min%' => '6'], 'Password must be at least 6 characters')], 422);
            }
            $endUser->password = $this->hasher->hashPassword($endUser, $pwd);
            $endUser->tokenVersion++;
            $this->sessionRepo?->revokeAllForEndUser($endUser);
            $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
        }

        $this->em->flush();

        return $this->json(['data' => $this->serializeUser($endUser)]);
    }

    // ─── DELETE ──────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}', name: 'destroy', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['DELETE'])]
    public function destroy(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => $this->trans('enduser.admin.not_found', [], 'Not found')], 404);

        $this->sessionRepo?->revokeAllForEndUser($endUser);
        $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
        $this->em->remove($endUser);
        $this->em->flush();

        return $this->json(['success' => true, 'deleted' => $endUserUuid]);
    }

    // ─── STATUS ──────────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}/status', name: 'status', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['PATCH'])]
    public function status(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => $this->trans('enduser.admin.not_found', [], 'Not found')], 404);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('enduser.admin.invalid_json', [], 'Invalid JSON')], 400);
        }
        $newStatus = (string) ($data['status'] ?? '');
        if (!in_array($newStatus, ['active', 'banned', 'pending'], true)) {
            return $this->json(['error' => $this->trans('enduser.admin.invalid_status', [], 'Invalid status')], 422);
        }

        $endUser->status = $newStatus;
        if ($newStatus === 'banned') {
            $endUser->tokenVersion++;
            $this->sessionRepo?->revokeAllForEndUser($endUser);
            $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
        }
        $this->em->flush();

        return $this->json(['success' => true, 'status' => $newStatus]);
    }

    // ─── RESET 2FA ───────────────────────────────────────────────────────────

    #[Route('/{endUserUuid}/reset-2fa', name: 'reset_2fa', requirements: ['endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function resetTwoFactor(string $uuid, string $endUserUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) return $project;

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) return $this->json(['error' => $this->trans('enduser.admin.not_found', [], 'Not found')], 404);

        if (!$endUser->twoFactorEnabled) {
            return $this->json(['error' => $this->trans('admin_end_users.2fa_not_enabled', [], 'Two-factor authentication is not enabled for this end user.')], 400);
        }

        $endUser->twoFactorEnabled = false;
        $endUser->twoFactorMethod = null;
        $endUser->twoFactorSecret = null;
        $endUser->twoFactorBackupCodes = null;
        $endUser->twoFactorConfirmedAt = null;
        $this->em->flush();

        if ($this->lockoutService !== null) {
            $this->lockoutService->clearAttempts('enduser_2fa:' . $endUser->uuid?->toString());
            $this->lockoutService->clearAttempts('enduser_2fa_backup:' . $endUser->uuid?->toString());
        }

        $this->auditService?->log(
            toolName: 'admin_enduser_2fa_reset',
            project: $project,
            input: ['end_user_uuid' => $endUserUuid, 'email' => $endUser->email],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->security->getUser()?->getUserIdentifier() ?? 'system',
            source: 'enduser_admin'
        );
        $this->auditService?->flush();

        return $this->json([
            'message' => $this->trans('admin_end_users.2fa_reset_success', ['%email%' => $endUser->email], "Two-factor authentication has been successfully reset for end user {$endUser->email}."),
            'two_factor_enabled' => false,
        ]);
    }

    // ─── Serializer ──────────────────────────────────────────────────────────

    private function serializeUser(EndUser $eu): array
    {
        return [
            'uuid'               => $eu->uuid?->toString(),
            'email'              => $eu->email,
            'name'               => $eu->username,
            'status'             => $eu->status,
            'avatar_url'         => $eu->avatarUrl,
            'custom_fields'      => $eu->customFields,
            'two_factor_enabled' => $eu->twoFactorEnabled,
            'two_factor_method'  => $eu->twoFactorMethod,
            'created_at'         => $eu->createdAt?->format(\DateTimeInterface::ATOM),
            'updated_at'         => $eu->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
