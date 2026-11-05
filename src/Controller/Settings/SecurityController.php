<?php
namespace App\Controller\Settings;

use App\Entity\User;
use App\Service\AuditService;
use App\Service\ProjectAuthLockoutService;
use App\Service\SocialLoginService;
use App\Service\TwoFactorService;
use App\Service\TwoFactorMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

#[Route('/api/settings/security', name: 'settings_security_')]
class SecurityController extends AbstractController
{
    public const MAX_CODE_LENGTH = 64;
    public const MAX_FAILED_ATTEMPTS = 5;
    private const MAX_PAYLOAD_BYTES = 65536; // 64 KB

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private TwoFactorService $twoFactor,
        private TwoFactorMailer $twoFactorMailer,
        private UserPasswordHasherInterface $hasher,
        private RequestStack $requestStack,
        private ?SocialLoginService $socialLogin = null,
        #[Autowire(service: 'limiter.two_factor_limiter')]
        private ?RateLimiterFactory $rateLimiter = null,
        private ?TranslatorInterface $translator = null,
        private ?ProjectAuthLockoutService $lockoutService = null,
        private ?AuditService $auditService = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
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

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('security.2fa.payload_too_large', [], 'Payload exceeds maximum size limit (64 KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        if ($this->rateLimiter !== null) {
            $identifier = ($request->getClientIp() ?: '127.0.0.1') . '_' . ($this->getUser()?->getUserIdentifier() ?? 'anon');
            if (!$this->rateLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('security.2fa.too_many_attempts', [], 'Too many requests. Please wait 60 seconds.')], 429);
            }
        }
        return null;
    }

    #[Route('', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $user = $this->requireUser();
        return $this->secureJson([
            'two_factor_enabled' => $user->twoFactorEnabled,
            'two_factor_method' => $user->twoFactorMethod,
            'two_factor_confirmed_at' => $user->twoFactorConfirmedAt?->format('c'),
            'has_backup_codes' => $user->twoFactorBackupCodes !== null && count($user->twoFactorBackupCodes) > 0,
        ]);
    }

    #[Route('/totp/setup', name: 'totp_setup', methods: ['POST'])]
    public function totpSetup(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->secureJson(['error' => $this->trans('security.2fa.already_enabled', [], '2FA already enabled. Disable it first.')], 409);
        }
        $data = $this->getJsonData($request);
        $password = (string) ($data['password'] ?? '');
        if ($password === '' || strlen($password) > 4096 || !$this->hasher->isPasswordValid($user, $password)) {
            return $this->secureJson(['error' => $this->trans('security.2fa.invalid_password', [], 'Invalid password.')], 422);
        }

        $secret = $this->twoFactor->generateSecret();
        $uri = $this->twoFactor->getProvisioningUri($secret, $user->email, 'JamboAPI');

        // Store secret temporarily (not confirmed yet, so twoFactorEnabled stays false)
        $user->twoFactorSecret = $secret;
        $user->twoFactorMethod = 'totp';
        $this->em->flush();

        // Generate QR code PNG as data URI
        $qrCode = new Builder(
            writer: new PngWriter(),
            data: $uri,
            size: 250,
        );
        $qrDataUri = $qrCode->build()->getDataUri();

        return $this->secureJson([
            'secret' => $secret,
            'qr_code_uri' => $qrDataUri,
            'provisioning_uri' => $uri,
        ]);
    }

    #[Route('/totp/confirm', name: 'totp_confirm', methods: ['POST'])]
    public function totpConfirm(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->secureJson(['error' => $this->trans('security.2fa.already_enabled', [], '2FA already enabled.')], 409);
        }
        if (!$user->twoFactorSecret || $user->twoFactorMethod !== 'totp') {
            return $this->secureJson(['error' => $this->trans('security.2fa.totp_setup_required', [], 'Run TOTP setup first.')], 400);
        }

        $userLockoutKey = 'security_2fa_user:' . $user->id;
        if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($userLockoutKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($userLockoutKey);
            return $this->secureJson([
                'error' => $this->trans('security.2fa.too_many_attempts', [], 'Too many requests. Please wait 60 seconds.'),
                'retry_after' => $retryAfter,
            ], 429);
        }

        $data = $this->getJsonData($request);
        $code = trim((string) ($data['code'] ?? ''));
        if (strlen($code) > self::MAX_CODE_LENGTH || !$this->twoFactor->verifyTotp($user->twoFactorSecret, $code)) {
            $this->lockoutService?->recordTieredFailedAttempt($userLockoutKey, [5 => 300, 10 => 900, 15 => 3600]);
            return $this->secureJson(['error' => $this->trans('security.2fa.invalid_code', [], 'Invalid code. Please try again.')], 422);
        }

        $this->lockoutService?->clearAttempts($userLockoutKey);
        $backupResult = $this->twoFactor->generateBackupCodes();
        $user->twoFactorEnabled = true;
        $user->twoFactorConfirmedAt = new \DateTimeImmutable();
        $user->twoFactorBackupCodes = $backupResult['hashes'];
        $this->em->flush();

        $this->auditService?->log(
            toolName: '2fa_enable_totp',
            project: null,
            input: ['ip' => $request->getClientIp()],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'security_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'message' => $this->trans('security.2fa.enabled_success', [], '2FA enabled successfully.'),
            'backup_codes' => $backupResult['plaintext'],
        ]);
    }

    #[Route('/email/enable', name: 'email_enable', methods: ['POST'])]
    public function emailEnable(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->secureJson(['error' => $this->trans('security.2fa.already_enabled', [], '2FA already enabled.')], 409);
        }
        $data = $this->getJsonData($request);
        $password = (string) ($data['password'] ?? '');
        if ($password === '' || strlen($password) > 4096 || !$this->hasher->isPasswordValid($user, $password)) {
            return $this->secureJson(['error' => $this->trans('security.2fa.invalid_password', [], 'Invalid password.')], 422);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $session->set('two_factor_email_code', $code);
        $session->set('two_factor_email_expires', time() + 300);
        $session->remove('security_email_attempts');

        $user->twoFactorSecret = null;
        $user->twoFactorMethod = 'email';
        $this->em->flush();

        $this->twoFactorMailer->sendCode($user->email, $code, 'JamboAPI');

        return $this->secureJson(['message' => $this->trans('security.2fa.code_sent', [], 'Verification code sent to your email.')]);
    }

    #[Route('/email/confirm', name: 'email_confirm', methods: ['POST'])]
    public function emailConfirm(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->secureJson(['error' => $this->trans('security.2fa.already_enabled', [], '2FA already enabled.')], 409);
        }
        if ($user->twoFactorMethod !== 'email') {
            return $this->secureJson(['error' => $this->trans('security.2fa.email_setup_required', [], 'Run email setup first.')], 400);
        }

        $userLockoutKey = 'security_2fa_user:' . $user->id;
        if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($userLockoutKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($userLockoutKey);
            return $this->secureJson([
                'error' => $this->trans('security.2fa.too_many_attempts', [], 'Too many requests. Please wait 60 seconds.'),
                'retry_after' => $retryAfter,
            ], 429);
        }

        $data = $this->getJsonData($request);
        $code = trim((string) ($data['code'] ?? ''));
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $storedCode = $session->get('two_factor_email_code');
        $expires = (int) $session->get('two_factor_email_expires', 0);

        if (!$storedCode || time() > $expires) {
            return $this->secureJson(['error' => $this->trans('security.2fa.code_expired', [], 'Code expired. Request a new one.')], 422);
        }

        if (strlen($code) > self::MAX_CODE_LENGTH || !$this->twoFactor->verifyEmailCode($storedCode, $expires, $code)) {
            $attempts = ((int) $session->get('security_email_attempts', 0)) + 1;
            $session->set('security_email_attempts', $attempts);
            $this->lockoutService?->recordTieredFailedAttempt($userLockoutKey, [5 => 300, 10 => 900, 15 => 3600]);

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $session->remove('two_factor_email_code');
                $session->remove('two_factor_email_expires');
                $session->remove('security_email_attempts');
                return $this->secureJson(['error' => $this->trans('security.2fa.max_attempts_exceeded', [], 'Maximum attempts exceeded. Please restart setup.')], 422);
            }
            return $this->secureJson(['error' => $this->trans('security.2fa.invalid_code', [], 'Invalid code.')], 422);
        }

        $session->remove('two_factor_email_code');
        $session->remove('two_factor_email_expires');
        $session->remove('security_email_attempts');
        $this->lockoutService?->clearAttempts($userLockoutKey);

        $backupResult = $this->twoFactor->generateBackupCodes();
        $user->twoFactorEnabled = true;
        $user->twoFactorConfirmedAt = new \DateTimeImmutable();
        $user->twoFactorBackupCodes = $backupResult['hashes'];
        $this->em->flush();

        $this->auditService?->log(
            toolName: '2fa_enable_email',
            project: null,
            input: ['ip' => $request->getClientIp()],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'security_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'message' => $this->trans('security.2fa.enabled_success', [], '2FA enabled successfully.'),
            'backup_codes' => $backupResult['plaintext'],
        ]);
    }

    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->requireUser();
        if (!$user->twoFactorEnabled) {
            return $this->secureJson(['error' => $this->trans('security.2fa.not_enabled', [], '2FA is not enabled.')], 400);
        }

        $data = $this->getJsonData($request);
        $password = (string) ($data['password'] ?? '');
        if ($password === '' || strlen($password) > 4096 || !$this->hasher->isPasswordValid($user, $password)) {
            return $this->secureJson(['error' => $this->trans('security.2fa.invalid_password', [], 'Invalid password.')], 422);
        }

        $user->twoFactorEnabled = false;
        $user->twoFactorMethod = null;
        $user->twoFactorSecret = null;
        $user->twoFactorBackupCodes = null;
        $user->twoFactorConfirmedAt = null;
        $this->em->flush();

        $this->lockoutService?->clearAttempts('user_2fa_account:' . $user->id);
        $this->lockoutService?->clearAttempts('user_2fa_backup:' . $user->id);
        $this->lockoutService?->clearAttempts('security_2fa_user:' . $user->id);

        $this->auditService?->log(
            toolName: '2fa_disable',
            project: null,
            input: ['ip' => $request->getClientIp()],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'security_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson(['message' => $this->trans('security.2fa.disabled_success', [], '2FA disabled successfully.')]);
    }

    #[Route('/backup-codes', name: 'backup_codes', methods: ['POST'])]
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->requireUser();
        if (!$user->twoFactorEnabled) {
            return $this->secureJson(['error' => $this->trans('security.2fa.not_enabled', [], '2FA is not enabled.')], 400);
        }

        $data = $this->getJsonData($request);
        $password = (string) ($data['password'] ?? '');
        if ($password === '' || strlen($password) > 4096 || !$this->hasher->isPasswordValid($user, $password)) {
            return $this->secureJson(['error' => $this->trans('security.2fa.invalid_password', [], 'Invalid password.')], 422);
        }

        $backupResult = $this->twoFactor->generateBackupCodes();
        $user->twoFactorBackupCodes = $backupResult['hashes'];
        $this->em->flush();

        $this->auditService?->log(
            toolName: '2fa_regenerate_backup_codes',
            project: null,
            input: ['ip' => $request->getClientIp()],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'security_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'message' => $this->trans('security.2fa.backup_codes_regenerated', [], 'Backup codes regenerated.'),
            'backup_codes' => $backupResult['plaintext'],
        ]);
    }

    // ─── Social Login ──────────────────────────────────────────────────────

    #[Route('/social', name: 'social_providers', methods: ['GET'])]
    public function socialProviders(): JsonResponse
    {
        $user = $this->requireUser();

        $allProviders = ['google', 'microsoft', 'github', 'gitlab'];

        if ($this->socialLogin === null) {
            $items = [];
            foreach ($allProviders as $p) {
                $items[$p] = ['linked' => false, 'configured' => false];
            }
            return $this->secureJson(['providers' => $items]);
        }

        $available = $this->socialLogin->getAvailableProviders();
        $linked = $this->socialLogin->getLinkedProviders($user);

        $items = [];
        foreach ($allProviders as $p) {
            $items[$p] = [
                'linked'     => in_array($p, $linked, true),
                'configured' => in_array($p, $available, true),
            ];
        }

        return $this->secureJson(['providers' => $items]);
    }

    #[Route('/social/{provider}/link', name: 'social_link', methods: ['POST'])]
    public function linkSocialProvider(string $provider, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($this->socialLogin === null) {
            return $this->secureJson(['error' => $this->trans('security.social.not_available', [], 'Social login is not available')], 500);
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            return $this->secureJson(['error' => $this->trans('security.social.unknown_provider', [], 'Unknown provider')], 400);
        }

        // La liaison réelle se fait via OAuth redirect — ce endpoint est informatif
        return $this->secureJson([
            'message' => $this->trans('security.social.redirect_link', ['%provider%' => $provider], 'Redirect to /connect/' . $provider . ' to link this provider.'),
            'redirect_url' => '/connect/' . $provider,
        ]);
    }

    #[Route('/social/{provider}', name: 'social_unlink', methods: ['DELETE'])]
    public function unlinkSocialProvider(string $provider, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $user = $this->requireUser();

        if ($this->socialLogin === null) {
            return $this->secureJson(['error' => $this->trans('security.social.not_available', [], 'Social login is not available')], 500);
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            return $this->secureJson(['error' => $this->trans('security.social.unknown_provider', [], 'Unknown provider')], 400);
        }

        if (!$this->socialLogin->canUnlinkProvider($user, $provider)) {
            return $this->secureJson([
                'error' => $this->trans('security.social.cannot_unlink_last', [], 'Cannot unlink the last authentication method. Set a password or link another provider first.'),
            ], 400);
        }

        $this->socialLogin->unlinkProvider($user, $provider);

        return $this->secureJson(['message' => $this->trans('security.social.unlinked_success', [], 'Provider unlinked successfully.')]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    private function getJsonData(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
