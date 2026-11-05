<?php

namespace App\Controller\Settings;

use App\Entity\User;
use App\Service\AuditService;
use App\Service\Security\PasswordPolicyValidator;
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

#[IsGranted('ROLE_USER')]
class PasswordController extends AbstractController
{
    private const MAX_PAYLOAD_BYTES = 65536; // 64 KB

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private ?PasswordPolicyValidator $passwordValidator = null,
        #[Autowire(service: 'limiter.login_limiter')]
        private readonly ?RateLimiterFactory $passwordLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
    ) {
        $this->passwordValidator ??= new PasswordPolicyValidator();
    }

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

    #[Route('/api/settings/password', name: 'api_settings_password', methods: ['PUT'])]
    public function update(Request $request): Response
    {
        if ($this->passwordLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->passwordLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('settings.password.rate_limit_exceeded', [], 'Too many password update attempts. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if ($_ENV['DEMO_MODE'] ?? false) {
            return new JsonResponse([
                'errors' => ['current_password' => $this->trans('settings.password.demo_disabled', [], 'Password changes are disabled in demo mode.')],
            ], 403);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => $this->trans('settings.password.unauthenticated', [], 'Unauthenticated.'),
                'code'  => 'UNAUTHENTICATED',
            ], 401);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('settings.password.payload_too_large', [], 'Payload exceeds maximum size.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('settings.password.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $validator = $this->passwordValidator ?? new PasswordPolicyValidator();
        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');

        // 1. Contrôle de rotation et vérification du mot de passe actuel
        $rotationResult = $validator->validateRotation($currentPassword, $newPassword, $user, $this->hasher);
        if (!$rotationResult['valid']) {
            $errorKey = str_contains($rotationResult['error'] ?? '', 'different') ? 'password' : 'current_password';
            return new JsonResponse(['errors' => [$errorKey => $rotationResult['error']]], 422);
        }

        // 2. Correspondance de confirmation
        if ($newPassword !== $confirmation) {
            return new JsonResponse(['errors' => ['password_confirmation' => $this->trans('settings.password.mismatch', [], 'Passwords do not match.')]], 422);
        }

        // 3. Politique de complexité et de sécurité du compte administrateur / membre
        $valResult = $validator->validateAdminPassword($newPassword, $user->email, $user->name);
        if (!$valResult['valid']) {
            return new JsonResponse(['errors' => ['password' => $valResult['errors'][0]]], 422);
        }

        $user->password = $this->hasher->hashPassword($user, $newPassword);
        $user->passwordChangedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'password_change',
            project: null,
            input: ['user_id' => $user->id],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'security_settings'
        );
        $this->auditService?->flush();

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectToRoute('settings_password', [], 303);
    }
}
