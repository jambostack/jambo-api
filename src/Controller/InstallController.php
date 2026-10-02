<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/install', name: 'install_')]
class InstallController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $diagnostics = $this->runDiagnostics();

        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Jambo API — Assistant d\'Installation</title>';
        $html .= '<style>body{background:#0d1117;color:#e6edf3;font-family:sans-serif;padding:40px;line-height:1.6}';
        $html .= '.card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px;max-width:700px;margin:0 auto}';
        $html .= 'h1{color:#58a6ff;margin-bottom:16px}ul{list-style:none;padding:0}li{padding:8px 0;border-bottom:1px solid #30363d;display:flex;justify-content:space-between}';
        $html .= '.ok{color:#3fb950;font-weight:bold}.ko{color:#f85149;font-weight:bold}</style></head><body>';
        $html .= '<div class="card"><h1>⚡ Jambo API — Diagnostic d\'Installation</h1>';
        $html .= '<p>Vérification de l\'environnement serveur :</p><ul>';

        foreach ($diagnostics['checks'] as $check) {
            $class = $check['pass'] ? 'ok' : 'ko';
            $status = $check['pass'] ? '✓ OK' : '✗ ERREUR';
            $html .= sprintf('<li><span>%s</span><span class="%s">%s</span></li>', htmlspecialchars($check['label']), $class, $status);
        }

        $html .= '</ul>';
        if ($diagnostics['ready']) {
            $html .= '<p style="color:#3fb950;margin-top:20px;font-weight:bold">🎉 Votre serveur est prêt pour Jambo API !</p>';
        } else {
            $html .= '<p style="color:#f85149;margin-top:20px;font-weight:bold">⚠️ Veuillez corriger les points bloquants ci-dessus.</p>';
        }
        $html .= '</div></body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    #[Route('/check', name: 'check', methods: ['GET'])]
    public function check(): JsonResponse
    {
        return new JsonResponse($this->runDiagnostics());
    }

    #[Route('/setup-admin', name: 'setup_admin', methods: ['POST'])]
    public function setupAdmin(Request $request): JsonResponse
    {
        if ($this->userRepository->count([]) > 0) {
            return new JsonResponse(['error' => 'Super admin already initialized.'], 403);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $email = trim($payload['email'] ?? '');
        $password = $payload['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            return new JsonResponse(['error' => 'Valid email and password (min 8 chars) required.'], 400);
        }

        $user = new User();
        $user->email = $email;
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $user->password = $this->passwordHasher->hashPassword($user, $password);

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Super admin created successfully.'], 201);
    }

    private function runDiagnostics(): array
    {
        $checks = [];
        $allPass = true;

        // PHP Version
        $phpVersionOk = version_compare(PHP_VERSION, '8.4.0', '>=');
        $checks[] = [
            'label' => sprintf('Version PHP >= 8.4 (actuelle : %s)', PHP_VERSION),
            'pass'  => $phpVersionOk,
        ];
        if (!$phpVersionOk) $allPass = false;

        // Extensions
        $requiredExtensions = ['pdo', 'intl', 'ctype', 'iconv', 'sodium', 'curl', 'mbstring', 'json'];
        foreach ($requiredExtensions as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'label' => sprintf('Extension PHP : %s', $ext),
                'pass'  => $loaded,
            ];
            if (!$loaded) $allPass = false;
        }

        // Dossiers inscriptibles
        $writableDirs = [
            'var/' => $this->projectDir . '/var',
            'public/uploads/' => $this->projectDir . '/public/uploads',
        ];
        foreach ($writableDirs as $name => $path) {
            $isWritable = is_dir($path) ? is_writable($path) : @mkdir($path, 0775, true) && is_writable($path);
            $checks[] = [
                'label' => sprintf('Dossier accessible en écriture : %s', $name),
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
