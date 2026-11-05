<?php

namespace App\Controller\Settings;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/settings', name: 'api_settings_')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    private const MAX_PAYLOAD_BYTES = 65536; // 64 KB

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.profile_limiter')]
        private readonly ?RateLimiterFactory $profileLimiter = null,
        private readonly ?UserPasswordHasherInterface $hasher = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->profileLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->profileLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('settings.profile.rate_limit_exceeded', [], 'Too many profile requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    #[Route('/profile', name: 'profile_show', methods: ['GET'])]
    public function show(?Request $request = null): JsonResponse
    {
        $request ??= new Request();
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => $this->trans('settings.profile.unauthenticated', [], 'Unauthenticated.'),
                'code'  => 'UNAUTHENTICATED',
            ], 401);
        }

        return new JsonResponse([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }

    #[Route('/profile', name: 'profile_update', methods: ['PATCH'])]
    public function update(Request $request): Response
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($_ENV['DEMO_MODE'] ?? false) {
            return new JsonResponse([
                'error' => $this->trans('settings.profile.demo_disabled', [], 'Account modification/deletion is disabled in demo mode.'),
                'code'  => 'DEMO_MODE',
            ], 403);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => $this->trans('settings.profile.unauthenticated', [], 'Unauthenticated.'),
                'code'  => 'UNAUTHENTICATED',
            ], 401);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('settings.profile.payload_too_large', [], 'Profile payload exceeds maximum size.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('settings.profile.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return new JsonResponse([
                    'errors' => ['name' => $this->trans('settings.profile.name_required', [], 'Name is required.')],
                ], 422);
            }
            if (mb_strlen($name) > 255) {
                return new JsonResponse([
                    'errors' => ['name' => $this->trans('settings.profile.name_too_long', [], 'Name cannot exceed 255 characters.')],
                ], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return new JsonResponse([
                    'errors' => ['name' => $this->trans('settings.profile.name_control_chars', [], 'Name contains invalid control characters.')],
                ], 422);
            }
            $user->name = $name;
        }

        if (isset($data['email'])) {
            $newEmail = strtolower(trim((string) $data['email']));
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse([
                    'errors' => ['email' => $this->trans('settings.profile.email_required', [], 'Valid email address is required.')],
                ], 422);
            }
            if (strlen($newEmail) > 180) {
                return new JsonResponse([
                    'errors' => ['email' => $this->trans('settings.profile.email_too_long', [], 'Email address cannot exceed 180 characters.')],
                ], 422);
            }
            if ($newEmail !== strtolower((string) $user->email) && $this->userRepository->findByEmail($newEmail) !== null) {
                return new JsonResponse([
                    'errors' => ['email' => $this->trans('settings.profile.email_taken', [], 'Email already taken.')],
                ], 422);
            }
            $user->email = $newEmail;
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'profile_update',
            project: null,
            input: ['name' => $user->name, 'email' => $user->email],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'profile_settings'
        );
        $this->auditService?->flush();

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                ],
            ]);
        }

        return $this->redirectToRoute('settings_profile', [], 303);
    }

    #[Route('/profile', name: 'profile_delete', methods: ['DELETE'])]
    public function delete(Request $request): Response
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($_ENV['DEMO_MODE'] ?? false) {
            return new JsonResponse([
                'error' => $this->trans('settings.profile.demo_disabled', [], 'Account modification/deletion is disabled in demo mode.'),
                'code'  => 'DEMO_MODE',
            ], 403);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => $this->trans('settings.profile.unauthenticated', [], 'Unauthenticated.'),
                'code'  => 'UNAUTHENTICATED',
            ], 401);
        }

        // If user has a password set, require password confirmation
        if ($user->getPassword() !== null && $user->getPassword() !== '') {
            $data = [];
            if ($request->getContent() !== '') {
                try {
                    $data = $request->toArray();
                } catch (\Throwable) {
                    $data = $request->request->all();
                }
            } else {
                $data = $request->request->all();
            }

            $password = (string) ($data['password'] ?? $data['current_password'] ?? '');
            if ($password === '') {
                return new JsonResponse([
                    'error' => $this->trans('settings.profile.password_required', [], 'Current password is required to delete account.'),
                    'code'  => 'PASSWORD_REQUIRED',
                ], 422);
            }

            if ($this->hasher !== null && !$this->hasher->isPasswordValid($user, $password)) {
                return new JsonResponse([
                    'error' => $this->trans('settings.profile.invalid_password', [], 'Current password is invalid.'),
                    'code'  => 'INVALID_PASSWORD',
                ], 422);
            }
        }

        // Ensure at least one other active super administrator exists
        if ($user->isSuperAdmin()) {
            $count = (int) $this->em->createQuery(
                "SELECT COUNT(u.id) FROM App\\Entity\\User u WHERE u.roles LIKE :role"
            )->setParameter('role', '%ROLE_SUPER_ADMIN%')->getSingleScalarResult();

            if ($count <= 1) {
                return new JsonResponse([
                    'error' => $this->trans('settings.profile.cannot_delete_last_admin', [], 'Cannot delete account: at least one active super administrator must remain.'),
                    'code'  => 'LAST_ADMIN_PROTECTION',
                ], 403);
            }
        }

        $this->auditService?->log(
            toolName: 'profile_delete',
            project: null,
            input: ['user_id' => $user->id, 'email' => $user->email],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'profile_settings'
        );
        $this->auditService?->flush();

        $this->em->remove($user);
        $this->em->flush();

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectToRoute('app_login', [], 303);
    }
}
