<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Repository\ProjectMailerSettingsRepository;
use App\Service\ProjectMailerService;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProjectMailerSettingsController extends AbstractController
{
    private readonly SsrfProtectionService $ssrfProtection;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectMailerSettingsRepository $settingsRepository,
        private readonly ProjectMailerService $mailerService,
        #[Autowire(service: 'limiter.mailer_test_limiter')]
        private readonly ?RateLimiterFactory $mailerTestLimiter = null,
        ?SsrfProtectionService $ssrfProtection = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    #[Route('/api/admin/projects/{uuid}/mailer', name: 'admin_project_mailer_get', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('project.not_found', [], 'Project not found')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        $settings = $this->settingsRepository->findByProject($project);
        if ($settings === null) {
            return new JsonResponse(['data' => null]);
        }

        return new JsonResponse(['data' => [
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            'encryption' => $settings->encryption,
            'from_email' => $settings->fromEmail,
            'from_name' => $settings->fromName,
            'enabled' => $settings->enabled,
        ]]);
    }

    #[Route('/api/admin/projects/{uuid}/mailer', name: 'admin_project_mailer_update', methods: ['PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('project.not_found', [], 'Project not found')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        $settings = $this->settingsRepository->findByProject($project);
        if ($settings === null) {
            $settings = new ProjectMailerSettings();
            $settings->project = $project;
            $this->em->persist($settings);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON')], 400);
        }

        if (isset($body['host'])) {
            $rawHost = (string) $body['host'];
            if (preg_match('/[\r\n\x00-\x1F\x7F]/', $rawHost)) {
                return new JsonResponse(['error' => $this->trans('project.mailer.invalid_host_control_chars', [], 'Invalid host: host must not contain control characters or newlines.')], 400);
            }
            $host = trim($rawHost);
            if ($host === '' || mb_strlen($host) > 255) {
                return new JsonResponse(['error' => $this->trans('project.mailer.invalid_host_length', [], 'Invalid host: host must be between 1 and 255 characters.')], 400);
            }
            $reason = null;
            if (!$this->ssrfProtection->isSafeHost($host, $reason)) {
                return new JsonResponse([
                    'error' => $this->trans('project.mailer.invalid_host_ssrf', [
                        '%reason%' => $reason ?? 'private or reserved addresses are not allowed',
                    ], 'Invalid host: ' . ($reason ?? 'private or reserved addresses are not allowed.')),
                ], 400);
            }
            $settings->host = $host;
        }
        if (isset($body['port'])) {
            $port = (int) $body['port'];
            if (!in_array($port, [25, 465, 587, 2525], true)) {
                return new JsonResponse(['error' => $this->trans('project.mailer.invalid_port', [], 'Invalid port: only SMTP ports allowed (25, 465, 587, 2525).')], 400);
            }
            $settings->port = $port;
        }
        if (isset($body['username'])) {
            $username = trim((string) $body['username']);
            if (mb_strlen($username) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $username)) {
                return new JsonResponse(['error' => $this->trans('project.mailer.invalid_username', [], 'Invalid username: must not exceed 255 characters and cannot contain control characters.')], 400);
            }
            $settings->username = $username;
        }
        // Ne mettre à jour le password que s'il est fourni (non vide)
        if (!empty($body['password'] ?? '')) {
            $rawPass = (string) $body['password'];
            if (mb_strlen($rawPass) > 1024) {
                return new JsonResponse(['error' => $this->trans('project.mailer.password_too_long', [], 'Password must not exceed 1024 characters.')], 400);
            }
            $settings->encryptedPassword = $this->mailerService->encryptPassword($rawPass);
        }
        if (isset($body['encryption'])) {
            $enc = (string) $body['encryption'];
            if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
                return new JsonResponse(['error' => $this->trans('project.mailer.invalid_encryption', [], 'Invalid encryption: must be tls, ssl, or none.')], 400);
            }
            $settings->encryption = $enc;
        }
        if (isset($body['from_email'])) {
            $fromEmail = trim((string) $body['from_email']);
            if ($fromEmail === '' || mb_strlen($fromEmail) > 254 || preg_match('/[\r\n\x00-\x1F\x7F]/', $fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse(['error' => $this->trans('project.mailer.invalid_from_email', [], 'Invalid from_email: must be a valid email address (max 254 characters) without control characters.')], 400);
            }
            $settings->fromEmail = $fromEmail;
        }
        if (isset($body['from_name'])) {
            $fromName = trim((string) $body['from_name']);
            if (mb_strlen($fromName) > 128 || preg_match('/[\r\n\x00-\x1F\x7F]/', $fromName)) {
                return new JsonResponse(['error' => $this->trans('project.mailer.invalid_from_name', [], 'Invalid from_name: must not exceed 128 characters and cannot contain control characters.')], 400);
            }
            $settings->fromName = $fromName;
        }
        if (isset($body['enabled'])) {
            $settings->enabled = (bool) $body['enabled'];
        }

        $this->em->flush();

        return new JsonResponse(['data' => [
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            'encryption' => $settings->encryption,
            'from_email' => $settings->fromEmail,
            'from_name' => $settings->fromName,
            'enabled' => $settings->enabled,
        ]]);
    }

    #[Route('/api/admin/projects/{uuid}/mailer/test', name: 'admin_project_mailer_test', methods: ['POST'])]
    public function test(Request $request, string $uuid): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('project.not_found', [], 'Project not found')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        if ($this->mailerTestLimiter !== null) {
            $limiter = $this->mailerTestLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('project.mailer.too_many_tests', [], 'Too many test requests. Please retry later.')], 429);
            }
        }

        $settings = $this->settingsRepository->findByProject($project);
        if ($settings === null || !$settings->enabled) {
            return new JsonResponse(['error' => $this->trans('project.mailer.not_configured', [], 'Mailer non configure ou desactive')], 422);
        }

        if (empty($settings->fromEmail) || !filter_var($settings->fromEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00-\x1F\x7F]/', $settings->fromEmail)) {
            return new JsonResponse(['error' => $this->trans('project.mailer.invalid_sender_email', [], 'Invalid sender email in mailer settings.')], 422);
        }

        try {
            $this->mailerService->sendSync(
                $project,
                $settings->fromEmail,
                $this->trans('project.mailer.test_email_subject', [], 'Jambo — Email de test'),
                $this->trans('project.mailer.test_email_body', [], "Bonjour,\n\nCeci est un email de test envoye depuis Jambo.\n\nSi vous recevez cet email, votre configuration SMTP est correcte.\n\n— L'equipe Jambo"),
            );
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => $this->trans('project.mailer.test_failed', ['%message%' => $e->getMessage()], $e->getMessage()),
            ], 422);
        }

        return new JsonResponse(['sent' => true]);
    }

    private function resolveProject(string $uuid): ?Project
    {
        try {
            return $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }
}
