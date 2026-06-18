<?php
namespace App\Controller\Settings;

use App\Entity\User;
use App\Service\TwoFactorService;
use App\Service\TwoFactorMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

#[Route('/api/settings/security', name: 'settings_security_')]
class SecurityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TwoFactorService $twoFactor,
        private TwoFactorMailer $twoFactorMailer,
        private UserPasswordHasherInterface $hasher,
        private RequestStack $requestStack,
    ) {}

    #[Route('', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $user = $this->requireUser();
        return $this->json([
            'two_factor_enabled' => $user->twoFactorEnabled,
            'two_factor_method' => $user->twoFactorMethod,
            'two_factor_confirmed_at' => $user->twoFactorConfirmedAt?->format('c'),
            'has_backup_codes' => $user->twoFactorBackupCodes !== null && count($user->twoFactorBackupCodes) > 0,
        ]);
    }

    #[Route('/totp/setup', name: 'totp_setup', methods: ['POST'])]
    public function totpSetup(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->json(['error' => '2FA already enabled. Disable it first.'], 409);
        }
        $password = (string) ($request->toArray()['password'] ?? '');
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Invalid password.'], 422);
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

        return $this->json([
            'secret' => $secret,
            'qr_code_uri' => $qrDataUri,
            'provisioning_uri' => $uri,
        ]);
    }

    #[Route('/totp/confirm', name: 'totp_confirm', methods: ['POST'])]
    public function totpConfirm(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->json(['error' => '2FA already enabled.'], 409);
        }
        if (!$user->twoFactorSecret || $user->twoFactorMethod !== 'totp') {
            return $this->json(['error' => 'Run TOTP setup first.'], 400);
        }

        $code = (string) ($request->toArray()['code'] ?? '');
        if (!$this->twoFactor->verifyTotp($user->twoFactorSecret, $code)) {
            return $this->json(['error' => 'Invalid code. Please try again.'], 422);
        }

        $backupResult = $this->twoFactor->generateBackupCodes();
        $user->twoFactorEnabled = true;
        $user->twoFactorConfirmedAt = new \DateTimeImmutable();
        $user->twoFactorBackupCodes = $backupResult['hashes'];
        $this->em->flush();

        return $this->json([
            'message' => '2FA enabled successfully.',
            'backup_codes' => $backupResult['plaintext'],
        ]);
    }

    #[Route('/email/enable', name: 'email_enable', methods: ['POST'])]
    public function emailEnable(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->json(['error' => '2FA already enabled.'], 409);
        }
        $password = (string) ($request->toArray()['password'] ?? '');
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Invalid password.'], 422);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $session->set('two_factor_email_code', $code);
        $session->set('two_factor_email_expires', time() + 300);

        $user->twoFactorSecret = null;
        $user->twoFactorMethod = 'email';
        $this->em->flush();

        $this->twoFactorMailer->sendCode($user->email, $code, 'JamboAPI');

        return $this->json(['message' => 'Verification code sent to your email.']);
    }

    #[Route('/email/confirm', name: 'email_confirm', methods: ['POST'])]
    public function emailConfirm(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if ($user->twoFactorEnabled) {
            return $this->json(['error' => '2FA already enabled.'], 409);
        }
        if ($user->twoFactorMethod !== 'email') {
            return $this->json(['error' => 'Run email setup first.'], 400);
        }

        $code = (string) ($request->toArray()['code'] ?? '');
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $storedCode = $session->get('two_factor_email_code');
        $expires = $session->get('two_factor_email_expires', 0);

        if (!$storedCode || time() > $expires) {
            return $this->json(['error' => 'Code expired. Request a new one.'], 422);
        }
        if ($code !== $storedCode) {
            return $this->json(['error' => 'Invalid code.'], 422);
        }

        $session->remove('two_factor_email_code');
        $session->remove('two_factor_email_expires');

        $backupResult = $this->twoFactor->generateBackupCodes();
        $user->twoFactorEnabled = true;
        $user->twoFactorConfirmedAt = new \DateTimeImmutable();
        $user->twoFactorBackupCodes = $backupResult['hashes'];
        $this->em->flush();

        return $this->json([
            'message' => '2FA enabled successfully.',
            'backup_codes' => $backupResult['plaintext'],
        ]);
    }

    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if (!$user->twoFactorEnabled) {
            return $this->json(['error' => '2FA is not enabled.'], 400);
        }

        $password = (string) ($request->toArray()['password'] ?? '');
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Invalid password.'], 422);
        }

        $user->twoFactorEnabled = false;
        $user->twoFactorMethod = null;
        $user->twoFactorSecret = null;
        $user->twoFactorBackupCodes = null;
        $user->twoFactorConfirmedAt = null;
        $this->em->flush();

        return $this->json(['message' => '2FA disabled successfully.']);
    }

    #[Route('/backup-codes', name: 'backup_codes', methods: ['POST'])]
    public function regenerateBackupCodes(): JsonResponse
    {
        $user = $this->requireUser();
        if (!$user->twoFactorEnabled) {
            return $this->json(['error' => '2FA is not enabled.'], 400);
        }

        $backupResult = $this->twoFactor->generateBackupCodes();
        $user->twoFactorBackupCodes = $backupResult['hashes'];
        $this->em->flush();

        return $this->json([
            'message' => 'Backup codes regenerated.',
            'backup_codes' => $backupResult['plaintext'],
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

}
