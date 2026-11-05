<?php
namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Entity\User;
use App\Service\AuditService;
use App\Service\ProjectAuthLockoutService;
use App\Service\TwoFactorService;
use App\Service\TwoFactorMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;

class TwoFactorChallengeController extends InertiaController
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const MAX_EMAIL_RESENDS = 3;

    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private TwoFactorService $twoFactor,
        private TwoFactorMailer $twoFactorMailer,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'limiter.two_factor_limiter')]
        private RateLimiterFactory $twoFactorLimiter,
        private ?ProjectAuthLockoutService $lockoutService = null,
        private ?AuditService $auditService = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/two-factor-challenge', name: 'two_factor_challenge', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $session = $request->getSession();

        // Vérifier que l'utilisateur a passé l'étape 1 (login)
        if (!$session->has('two_factor_user_id') || !$session->has('two_factor_expires')) {
            return $this->redirectToRoute('app_login');
        }
        if (time() > (int) $session->get('two_factor_expires')) {
            $this->clearTwoFactorSession($session);
            return $this->redirectToRoute('app_login');
        }

        return $this->inertia($request, 'auth/two-factor-challenge', [
            'error' => null,
        ]);
    }

    #[Route('/two-factor-challenge', name: 'two_factor_challenge_verify', methods: ['POST'])]
    public function verify(Request $request): Response
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->inertia($request, 'auth/two-factor-challenge', [
                'error' => 'auth.2fa.payload_too_large',
            ], 413);
        }

        $session = $request->getSession();

        if (!$session->has('two_factor_user_id') || !$session->has('two_factor_expires')) {
            return $this->redirectToRoute('app_login');
        }
        if (time() > (int) $session->get('two_factor_expires')) {
            $this->clearTwoFactorSession($session);
            return $this->redirectToRoute('app_login');
        }

        // Distributed Brute-Force & Rate Limiting protection
        $userId = (string) $session->get('two_factor_user_id');
        $ip = $request->getClientIp() ?: '127.0.0.1';

        if ($this->lockoutService !== null) {
            $accountKey = 'user_2fa_account:' . $userId;
            $ipKey = 'user_2fa_ip:' . $ip;

            if ($this->lockoutService->isLockedOut($accountKey)) {
                $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($accountKey);
                $this->clearTwoFactorSession($session);
                return $this->inertia($request, 'auth/two-factor-challenge', [
                    'error' => $this->trans('auth.2fa.account_locked_retry_after', ['%seconds%' => (string) $retryAfter], "Too many failed 2FA attempts across sessions. Account challenge locked for {$retryAfter} seconds."),
                ], 429);
            }

            if ($this->lockoutService->isLockedOut($ipKey)) {
                $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($ipKey);
                return $this->inertia($request, 'auth/two-factor-challenge', [
                    'error' => $this->trans('auth.2fa.too_many_attempts', [], 'Too many attempts. Please wait 60 seconds.'),
                ], 429);
            }
        }

        $limiter = $this->twoFactorLimiter->create($ip . '_' . $userId);
        if (!$limiter->consume()->isAccepted()) {
            return $this->inertia($request, 'auth/two-factor-challenge', [
                'error' => 'auth.2fa.too_many_attempts',
            ], 429);
        }

        $user = $this->getUserById((int) $userId);
        if (!$user) {
            $this->clearTwoFactorSession($session);
            return $this->redirectToRoute('app_login');
        }

        try {
            $postData = $request->getPayload()->all();
        } catch (\Throwable) {
            $postData = $request->request->all();
        }
        if (empty($postData)) {
            $postData = $request->request->all();
        }
        $code = trim((string) ($postData['code'] ?? ''));
        $useBackup = (bool) ($postData['use_backup'] ?? false) || $this->twoFactor->isBackupCodeFormat($code);

        // Dedicated distributed brute-force protection for static backup recovery keys
        if ($useBackup && $this->lockoutService !== null) {
            $backupKey = 'user_2fa_backup:' . $userId;
            if ($this->lockoutService->isLockedOut($backupKey)) {
                $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($backupKey);
                return $this->inertia($request, 'auth/two-factor-challenge', [
                    'error' => $this->trans('auth.2fa.backup_locked_retry_after', ['%seconds%' => (string) $retryAfter], "Too many failed backup code attempts. Recovery code verification locked for {$retryAfter} seconds."),
                ], 429);
            }
        }

        if (strlen($code) > TwoFactorService::MAX_CODE_LENGTH) {
            return $this->handleFailedAttempt($session, $request, $userId, $useBackup);
        }

        $valid = false;

        if ($useBackup) {
            // Backup code
            $storedCodes = $user->twoFactorBackupCodes ?? [];
            $valid = $this->twoFactor->verifyAndConsumeBackupCode($storedCodes, $code);
            if ($valid) {
                $user->twoFactorBackupCodes = $storedCodes;
                $this->em->flush();
            }
        } elseif ($user->twoFactorMethod === 'totp') {
            $valid = $this->twoFactor->verifyTotp($user->twoFactorSecret ?? '', $code);
        } elseif ($user->twoFactorMethod === 'email') {
            $storedCode = $session->get('two_factor_email_code');
            $expires = (int) $session->get('two_factor_email_expires', 0);
            $valid = $this->twoFactor->verifyEmailCode($storedCode, $expires, $code);
            if ($valid) {
                $session->remove('two_factor_email_code');
                $session->remove('two_factor_email_expires');
            }
        }

        if (!$valid) {
            return $this->handleFailedAttempt($session, $request, $userId, $useBackup);
        }

        // Clear lockouts on success
        if ($this->lockoutService !== null) {
            $this->lockoutService->clearAttempts('user_2fa_account:' . $userId);
            $this->lockoutService->clearAttempts('user_2fa_backup:' . $userId);
            $this->lockoutService->clearAttempts('user_2fa_ip:' . $ip);
        }

        if ($this->auditService !== null) {
            $this->auditService->log(
                toolName: 'auth_2fa_verify',
                project: null,
                input: ['userId' => $userId, 'method' => $useBackup ? 'backup_code' : $user->twoFactorMethod, 'ip' => $ip],
                output: ['success' => true],
                status: 'success',
                createdBy: $user->getUserIdentifier(),
                source: 'two_factor_challenge'
            );
            $this->auditService->flush();
        }

        // Nettoyer la session 2FA
        $this->clearTwoFactorSession($session);

        // Créer la session Symfony complète (authentification manuelle)
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);
        $session->set('_security_main', serialize($token));
        $session->migrate(true);

        return $this->redirectToRoute('app_home');
    }

    #[Route('/two-factor-challenge/send-email', name: 'two_factor_send_email', methods: ['POST'])]
    public function sendEmail(Request $request): Response
    {
        $session = $request->getSession();
        if (!$session->has('two_factor_user_id')) {
            return $this->redirectToRoute('app_login');
        }

        $userId = (string) $session->get('two_factor_user_id');
        $limiter = $this->twoFactorLimiter->create(($request->getClientIp() ?: '127.0.0.1') . '_email_' . $userId);
        if (!$limiter->consume()->isAccepted()) {
            return $this->secureJson(['error' => $this->trans('auth.2fa.too_many_attempts', [], 'Too many attempts. Please wait 60 seconds.')], 429);
        }

        $resends = (int) $session->get('two_factor_email_resends', 0);
        if ($resends >= self::MAX_EMAIL_RESENDS) {
            return $this->secureJson(['error' => $this->trans('auth.2fa.max_resends_exceeded', [], 'Maximum email resend limit reached.')], 429);
        }

        $user = $this->getUserById((int) $userId);
        if (!$user || $user->twoFactorMethod !== 'email') {
            $this->clearTwoFactorSession($session);
            return $this->redirectToRoute('app_login');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $session->set('two_factor_email_code', $code);
        $session->set('two_factor_email_expires', time() + 300);
        $session->set('two_factor_email_resends', $resends + 1);
        $this->twoFactorMailer->sendCode($user->email, $code, 'JamboAPI');

        return $this->secureJson(['message' => $this->trans('auth.2fa.code_sent', [], 'Code sent.')]);
    }

    private function handleFailedAttempt($session, Request $request, string $userId, bool $useBackup = false): Response
    {
        $attempts = ((int) $session->get('two_factor_failed_attempts', 0)) + 1;
        $session->set('two_factor_failed_attempts', $attempts);

        $ip = $request->getClientIp() ?: '127.0.0.1';
        if ($this->lockoutService !== null) {
            $this->lockoutService->recordTieredFailedAttempt('user_2fa_account:' . $userId, [5 => 300, 10 => 900, 15 => 3600]);
            $this->lockoutService->recordTieredFailedAttempt('user_2fa_ip:' . $ip, [10 => 300, 20 => 900, 30 => 3600]);
            if ($useBackup) {
                $this->lockoutService->recordTieredFailedAttempt('user_2fa_backup:' . $userId, [3 => 300, 5 => 900, 8 => 3600]);
            }
        }

        if ($this->auditService !== null) {
            $this->auditService->log(
                toolName: 'auth_2fa_failure',
                project: null,
                input: ['userId' => $userId, 'use_backup' => $useBackup, 'ip' => $ip, 'attempt' => $attempts],
                output: ['error' => 'invalid_code'],
                status: 'failed',
                createdBy: (string) $userId,
                source: 'two_factor_challenge'
            );
            $this->auditService->flush();
        }

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->clearTwoFactorSession($session);
            return $this->inertia($request, 'auth/two-factor-challenge', [
                'error' => 'auth.2fa.max_attempts_exceeded',
            ]);
        }

        return $this->inertia($request, 'auth/two-factor-challenge', [
            'error' => 'auth.2fa.invalid_code',
        ]);
    }

    private function clearTwoFactorSession($session): void
    {
        $session->remove('two_factor_user_id');
        $session->remove('two_factor_expires');
        $session->remove('two_factor_email_code');
        $session->remove('two_factor_email_expires');
        $session->remove('two_factor_failed_attempts');
        $session->remove('two_factor_email_resends');
    }

    protected function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if (isset($this->translator)) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    private function getUserById(int $id): ?User
    {
        return $this->em->getRepository(User::class)->find($id);
    }
}
