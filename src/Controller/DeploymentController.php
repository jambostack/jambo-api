<?php

namespace App\Controller;

use App\Entity\Deployment;
use App\Repository\DeploymentRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Jambo Deploy — endpoints d'observabilité du pipeline CI/CD.
 *
 *  - /api/system/info      : version + commit (lecture libre, public)
 *  - /api/deployments      : derniers déploiements (auth requise)
 *  - /api/deployments/hook : webhook signé pour mise à jour depuis GitHub Actions
 */
class DeploymentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DeploymentRepository $deployments,
        private ProjectRepository $projects,
    ) {}

    /**
     * Info de build — utilisée par le frontend pour afficher la version courante.
     * Pas d'authentification : on n'expose que des métadonnées non-sensibles.
     */
    #[Route('/api/system/info', name: 'system_info', methods: ['GET'])]
    public function systemInfo(): JsonResponse
    {
        $rootDir = $this->getParameter('kernel.project_dir');
        $versionFile = $rootDir . '/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev';

        return $this->json([
            'name'        => 'JamboApi CMS',
            'version'     => $version,
            'commit'      => $_ENV['GIT_COMMIT_SHA'] ?? null,
            'branch'      => $_ENV['GIT_BRANCH'] ?? null,
            'built_at'    => $_ENV['BUILT_AT'] ?? null,
            'environment' => $this->getParameter('kernel.environment'),
            'php'         => PHP_VERSION,
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/api/deployments', name: 'deployments_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, $request->query->getInt('limit', 25)));
        $deployments = $this->deployments->findRecent($limit);

        return $this->json([
            'data' => array_map(fn (Deployment $d) => $this->serialize($d), $deployments),
        ]);
    }

    /**
     * Webhook signé HMAC-SHA256 appelé par le workflow GitHub Actions.
     *
     * Le secret partagé est lu dans `DEPLOY_WEBHOOK_SECRET`. La signature
     * arrive dans le header `X-Jambo-Signature: sha256=<hex>`.
     */
    #[Route('/api/deployments/hook', name: 'deployments_hook', methods: ['POST'])]
    public function hook(Request $request): JsonResponse
    {
        $secret = $_ENV['DEPLOY_WEBHOOK_SECRET'] ?? '';
        if ($secret === '') {
            return $this->json(['error' => 'Webhook désactivé : DEPLOY_WEBHOOK_SECRET non configuré'], 503);
        }

        $payload = $request->getContent();
        $signature = $request->headers->get('X-Jambo-Signature', '');
        if (!str_starts_with($signature, 'sha256=')) {
            return $this->json(['error' => 'Signature manquante'], 401);
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            return $this->json(['error' => 'Signature invalide'], 401);
        }

        $data = json_decode($payload, true) ?: [];

        $environment = in_array($data['environment'] ?? '', [
            Deployment::ENV_PREVIEW,
            Deployment::ENV_STAGING,
            Deployment::ENV_PRODUCTION,
        ], true) ? $data['environment'] : Deployment::ENV_PREVIEW;

        $status = in_array($data['status'] ?? '', [
            Deployment::STATUS_PENDING,
            Deployment::STATUS_RUNNING,
            Deployment::STATUS_SUCCEEDED,
            Deployment::STATUS_FAILED,
            Deployment::STATUS_CANCELED,
        ], true) ? $data['status'] : Deployment::STATUS_PENDING;

        $deployment = new Deployment();
        $deployment->commitSha = substr((string) ($data['commit_sha'] ?? ''), 0, 40);
        $deployment->branch = isset($data['branch']) ? substr((string) $data['branch'], 0, 100) : null;
        $deployment->environment = $environment;
        $deployment->status = $status;
        $deployment->imageRef = isset($data['image']) ? (string) $data['image'] : null;
        $deployment->previewUrl = isset($data['preview_url']) ? (string) $data['preview_url'] : null;
        $deployment->runUrl = isset($data['run_url']) ? (string) $data['run_url'] : null;
        $deployment->errorMessage = isset($data['error']) ? (string) $data['error'] : null;

        if (in_array($status, [Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED, Deployment::STATUS_CANCELED], true)) {
            $deployment->finishedAt = new \DateTimeImmutable();
        }

        if (!empty($data['project_uuid'])) {
            $project = $this->projects->findOneBy(['uuid' => $data['project_uuid']]);
            if ($project) {
                $deployment->project = $project;
            }
        }

        $this->em->persist($deployment);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($deployment)], 201);
    }

    private function serialize(Deployment $d): array
    {
        return [
            'uuid'        => $d->uuid->toRfc4122(),
            'project'     => $d->project?->uuid?->toRfc4122(),
            'commit_sha'  => $d->commitSha,
            'branch'      => $d->branch,
            'environment' => $d->environment,
            'status'      => $d->status,
            'image'       => $d->imageRef,
            'preview_url' => $d->previewUrl,
            'run_url'     => $d->runUrl,
            'error'       => $d->errorMessage,
            'started_at'  => $d->startedAt->format(\DateTimeInterface::ATOM),
            'finished_at' => $d->finishedAt?->format(\DateTimeInterface::ATOM),
            'duration_s'  => $d->durationSeconds(),
        ];
    }
}
