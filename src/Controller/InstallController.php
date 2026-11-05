<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Security\PasswordPolicyValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/install', name: 'install_')]
class InstallController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];
    private const MAX_PAYLOAD_SIZE = 16384;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire(service: 'limiter.install_setup_limiter')] private readonly RateLimiterFactory $installLimiter,
        private readonly TranslatorInterface $translator,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function secureHtml(string $html, int $status = 200, array $headers = []): Response
    {
        return new Response($html, $status, array_merge(self::SECURITY_HEADERS, ['Content-Type' => 'text/html; charset=UTF-8'], $headers));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $t = $this->translator;
        if ($this->userRepository->count([]) > 0) {
            $title = htmlspecialchars($t->trans('install.already_installed_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body  = htmlspecialchars($t->trans('install.already_installed_body'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $link  = htmlspecialchars($t->trans('install.already_installed_link'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $this->secureHtml('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Jambo API</title></head><body style="background:#0d1117;color:#e6edf3;font-family:sans-serif;padding:40px;"><div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px;max-width:600px;margin:0 auto;"><h1 style="color:#f85149;">' . $title . '</h1><p>' . $body . '</p><p><a href="/login" style="color:#58a6ff;">' . $link . '</a></p></div></body></html>', 403);
        }

        $diagnostics = $this->runDiagnostics();

        $html  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($t->trans('install.diagnostic_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';
        $html .= '<style>body{background:#0d1117;color:#e6edf3;font-family:sans-serif;padding:40px;line-height:1.6}';
        $html .= '.card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px;max-width:700px;margin:0 auto}';
        $html .= 'h1{color:#58a6ff;margin-bottom:16px}ul{list-style:none;padding:0}li{padding:8px 0;border-bottom:1px solid #30363d;display:flex;justify-content:space-between}';
        $html .= '.ok{color:#3fb950;font-weight:bold}.ko{color:#f85149;font-weight:bold}</style></head><body>';
        $html .= '<div class="card"><h1>' . htmlspecialchars($t->trans('install.diagnostic_heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
        $html .= '<p>' . htmlspecialchars($t->trans('install.diagnostic_intro'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><ul>';

        foreach ($diagnostics['checks'] as $check) {
            $class  = $check['pass'] ? 'ok' : 'ko';
            $status = $check['pass'] ? htmlspecialchars($t->trans('install.check_ok'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars($t->trans('install.check_error'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= sprintf('<li><span>%s</span><span class="%s">%s</span></li>', htmlspecialchars($check['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $class, $status);
        }

        $html .= '</ul>';
        if ($diagnostics['ready']) {
            $html .= '<p style="color:#3fb950;margin-top:20px;font-weight:bold">' . htmlspecialchars($t->trans('install.diagnostic_ready'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        } else {
            $html .= '<p style="color:#f85149;margin-top:20px;font-weight:bold">' . htmlspecialchars($t->trans('install.diagnostic_not_ready'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        $html .= '</div></body></html>';

        return $this->secureHtml($html, 200);
    }

    #[Route('/check', name: 'check', methods: ['GET'])]
    public function check(): JsonResponse
    {
        if ($this->userRepository->count([]) > 0) {
            return $this->secureJson(['error' => $this->translator->trans('install.already_installed_error')], 403);
        }

        return $this->secureJson($this->runDiagnostics());
    }

    #[Route('/setup-admin', name: 'setup_admin', methods: ['POST'])]
    public function setupAdmin(Request $request): JsonResponse
    {
        $t = $this->translator;

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_SIZE) {
            return $this->secureJson(['error' => $t->trans('install.payload_too_large')], 413);
        }

        // Rate limiting: prevent brute-force admin setup on fresh instances.
        $limiter = $this->installLimiter->create($request->getClientIp() ?: '127.0.0.1');
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->secureJson(['error' => $t->trans('install.too_many_attempts')], 429);
        }

        if ($this->userRepository->count([]) > 0) {
            return $this->secureJson(['error' => $t->trans('install.super_admin_exists')], 403);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $t->trans('install.invalid_json')], 400);
        }
        $email    = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->secureJson(['error' => $t->trans('install.email_required')], 400);
        }

        if (strlen($password) > PasswordPolicyValidator::MAX_LENGTH) {
            return $this->secureJson(['error' => $t->trans('password.policy.max_length', ['%max%' => (string) PasswordPolicyValidator::MAX_LENGTH])], 400);
        }

        $pwdValidator = new PasswordPolicyValidator($this->translator);
        $valResult    = $pwdValidator->validateAdminPassword($password, $email);
        if (!$valResult['valid']) {
            return $this->secureJson(['error' => $valResult['errors'][0]], 400);
        }

        $user                    = new User();
        $user->email             = $email;
        $user->roles             = ['ROLE_SUPER_ADMIN'];
        $user->password          = $this->passwordHasher->hashPassword($user, $password);
        $user->passwordChangedAt = new \DateTimeImmutable();

        $this->em->persist($user);
        $this->em->flush();

        return $this->secureJson(['success' => true, 'message' => $t->trans('install.super_admin_created')], 201);
    }

    private function runDiagnostics(): array
    {
        $t      = $this->translator;
        $checks = [];
        $allPass = true;

        // PHP Version
        $phpVersionOk = version_compare(PHP_VERSION, '8.4.0', '>=');
        $checks[] = [
            'label' => sprintf($t->trans('install.php_version_check'), PHP_VERSION),
            'pass'  => $phpVersionOk,
        ];
        if (!$phpVersionOk) $allPass = false;

        // Extensions
        $requiredExtensions = ['pdo', 'intl', 'ctype', 'iconv', 'sodium', 'curl', 'mbstring', 'json'];
        foreach ($requiredExtensions as $ext) {
            $loaded   = extension_loaded($ext);
            $checks[] = [
                'label' => sprintf($t->trans('install.extension_check'), $ext),
                'pass'  => $loaded,
            ];
            if (!$loaded) $allPass = false;
        }

        // Writable directories
        $writableDirs = [
            'var/'            => $this->projectDir . '/var',
            'public/uploads/' => $this->projectDir . '/public/uploads',
        ];
        foreach ($writableDirs as $name => $path) {
            $isWritable = is_dir($path) ? is_writable($path) : @mkdir($path, 0775, true) && is_writable($path);
            $checks[]   = [
                'label' => sprintf($t->trans('install.writable_dir_check'), $name),
                'pass'  => $isWritable,
            ];
            if (!$isWritable) $allPass = false;
        }

        return [
            'ready'  => $allPass,
            'checks' => $checks,
        ];
    }
}
