<?php
namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Entity\User;
use App\Service\TwoFactorService;
use App\Service\TwoFactorMailer;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class TwoFactorChallengeController extends InertiaController
{
    public function __construct(
        private TwoFactorService $twoFactor,
        private TwoFactorMailer $twoFactorMailer,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/two-factor-challenge', name: 'two_factor_challenge', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $session = $request->getSession();

        // Vérifier que l'utilisateur a passé l'étape 1 (login)
        if (!$session->has('two_factor_user_id') || !$session->has('two_factor_expires')) {
            return $this->redirectToRoute('app_login');
        }
        if (time() > $session->get('two_factor_expires')) {
            $session->remove('two_factor_user_id');
            $session->remove('two_factor_expires');
            return $this->redirectToRoute('app_login');
        }

        return $this->inertia($request, 'auth/two-factor-challenge', [
            'error' => null,
        ]);
    }

    #[Route('/two-factor-challenge', name: 'two_factor_challenge_verify', methods: ['POST'])]
    public function verify(Request $request, RateLimiterFactory $twoFactorLimiter): Response
    {
        $session = $request->getSession();

        if (!$session->has('two_factor_user_id') || !$session->has('two_factor_expires')) {
            return $this->redirectToRoute('app_login');
        }
        if (time() > $session->get('two_factor_expires')) {
            $session->remove('two_factor_user_id');
            $session->remove('two_factor_expires');
            return $this->redirectToRoute('app_login');
        }

        // Rate limiting
        $limiter = $twoFactorLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->inertia($request, 'auth/two-factor-challenge', [
                'error' => 'Too many attempts. Please wait 60 seconds.',
            ]);
        }

        $user = $this->getUserById($session->get('two_factor_user_id'));
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $code = (string) ($request->get('code', ''));
        $useBackup = $request->getBoolean('use_backup', false);

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
            $expires = $session->get('two_factor_email_expires', 0);
            $valid = $storedCode && time() <= $expires && $code === $storedCode;
            if ($valid) {
                $session->remove('two_factor_email_code');
                $session->remove('two_factor_email_expires');
            }
        }

        if (!$valid) {
            return $this->inertia($request, 'auth/two-factor-challenge', [
                'error' => 'Invalid code. Please try again.',
            ]);
        }

        // Nettoyer la session 2FA
        $session->remove('two_factor_user_id');
        $session->remove('two_factor_expires');

        // Créer la session Symfony complète (authentification manuelle)
        $token = $this->container->get('security.authenticator.form_login.main')
            ->createAuthenticatedToken($user, 'main');
        $this->container->get('security.token_storage')->setToken($token);
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

        $user = $this->getUserById($session->get('two_factor_user_id'));
        if (!$user || $user->twoFactorMethod !== 'email') {
            return $this->redirectToRoute('app_login');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $session->set('two_factor_email_code', $code);
        $session->set('two_factor_email_expires', time() + 300);
        $this->twoFactorMailer->sendCode($user->email, $code, 'JamboAPI');

        return $this->json(['message' => 'Code sent.']);
    }

    private function getUserById(int $id): ?User
    {
        return $this->em->getRepository(User::class)->find($id);
    }
}
