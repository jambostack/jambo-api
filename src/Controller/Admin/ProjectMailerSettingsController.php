<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Repository\ProjectMailerSettingsRepository;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProjectMailerSettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectMailerSettingsRepository $settingsRepository,
        private readonly ProjectMailerService $mailerService,
    ) {}

    #[Route('/api/admin/projects/{uuid}/mailer', name: 'admin_project_mailer_get', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Projet introuvable'], 404);
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
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Projet introuvable'], 404);
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
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'JSON invalide'], 400);
        }

        if (isset($body['host'])) {
            $host = (string) $body['host'];
            if (!filter_var(gethostbyname($host), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new JsonResponse(['error' => 'Invalid host: private/internal IPs are not allowed.'], 400);
            }
            $settings->host = $host;
        }
        if (isset($body['port'])) {
            $port = (int) $body['port'];
            if (!in_array($port, [25, 465, 587, 2525], true)) {
                return new JsonResponse(['error' => 'Invalid port: only SMTP ports allowed (25, 465, 587, 2525).'], 400);
            }
            $settings->port = $port;
        }
        if (isset($body['username'])) {
            $settings->username = (string) $body['username'];
        }
        // Ne mettre à jour le password que s'il est fourni (non vide)
        if (!empty($body['password'] ?? '')) {
            $settings->encryptedPassword = $this->mailerService->encryptPassword((string) $body['password']);
        }
        if (isset($body['encryption'])) {
            $enc = (string) $body['encryption'];
            if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
                return new JsonResponse(['error' => 'Invalid encryption: must be tls, ssl, or none.'], 400);
            }
            $settings->encryption = $enc;
        }
        if (isset($body['from_email'])) {
            $settings->fromEmail = (string) $body['from_email'];
        }
        if (isset($body['from_name'])) {
            $settings->fromName = (string) $body['from_name'];
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
    public function test(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Projet introuvable'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        $settings = $this->settingsRepository->findByProject($project);
        if ($settings === null || !$settings->enabled) {
            return new JsonResponse(['error' => 'Mailer non configure ou desactive'], 422);
        }

        try {
            $this->mailerService->send(
                $project,
                $settings->fromEmail,
                'Jambo — Email de test',
                "Bonjour,\n\nCeci est un email de test envoye depuis Jambo.\n\nSi vous recevez cet email, votre configuration SMTP est correcte.\n\n— L'equipe Jambo",
            );
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['sent' => true]);
    }
}
