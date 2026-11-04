<?php

namespace App\Controller;

use App\Entity\Deployment;
use App\Repository\DeploymentRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        #[Autowire(service: 'limiter.deployment_hook_limiter')]
        private ?RateLimiterFactory $hookLimiter = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function validateSafeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $host = strtolower($host);
        $blockedHosts = [
            '169.254.169.254',
            '169.254.169.250',
            'fd00:ec2::254',
            'localhost',
            '127.0.0.1',
            '::1',
        ];
        if (in_array($host, $blockedHosts, true)) {
            return false;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with($ip, '127.')
                || str_starts_with($ip, '169.254.')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Info de build — utilisée par le frontend pour afficher la version courante.
     * Pas d'authentification : on n'expose que des métadonnées non-sensibles.
     */
    #[Route('/api/system/info', name: 'system_info', methods: ['GET'])]
    public function systemInfo(): JsonResponse
    {
        $rootDir = $this->getParameter('kernel.project_dir');
        $versionFile = $rootDir . '/VERSION';
        if (is_file($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));
        } elseif (is_file($rootDir . '/composer.json')) {
            $composerJson = json_decode((string) file_get_contents($rootDir . '/composer.json'), true);
            $version = $composerJson['version'] ?? HealthController::VERSION;
        } else {
            $version = HealthController::VERSION;
        }

        return new JsonResponse([
            'name'     => 'JamboApi CMS',
            'version'  => $version,
            'commit'   => $_ENV['GIT_COMMIT_SHA'] ?? null,
            'branch'   => $_ENV['GIT_BRANCH'] ?? null,
            'built_at' => $_ENV['BUILT_AT'] ?? null,
            // 'environment' and 'php' intentionally omitted to reduce information disclosure surface.
        ]);
    }

    #[Route('/api/deployments', name: 'deployments_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return new JsonResponse(['error' => $this->trans('deployment.auth_required', [], 'Authentication required.')], 401);
        }

        if (!$this->isGranted('ROLE_SUPER_ADMIN') && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['error' => $this->trans('deployment.access_denied', [], 'Access denied.')], 403);
        }

        $limit = min(100, max(1, $request->query->getInt('limit', 25)));
        $deployments = $this->deployments->findRecent($limit);

        return new JsonResponse([
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
        if ($this->hookLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->hookLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('deployment.too_many_requests', [], 'Too many deployment hook requests. Please retry later.')], 429);
            }
        }

        $secret = $_ENV['DEPLOY_WEBHOOK_SECRET'] ?? '';
        if ($secret === '') {
            return new JsonResponse(['error' => $this->trans('deployment.webhook_disabled', [], 'Deployment webhook disabled: DEPLOY_WEBHOOK_SECRET not configured.')], 503);
        }

        $payload = $request->getContent();
        if (strlen($payload) > 1048576) {
            return new JsonResponse(['error' => $this->trans('deployment.payload_too_large', [], 'Payload too large (max 1MB).')], 413);
        }

        $signature = $request->headers->get('X-Jambo-Signature', '');
        if (!str_starts_with($signature, 'sha256=')) {
            return new JsonResponse(['error' => $this->trans('deployment.signature_missing', [], 'Signature header missing.')], 401);
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            return new JsonResponse(['error' => $this->trans('deployment.signature_invalid', [], 'Invalid signature.')], 401);
        }

        $decoded = json_decode($payload, true);
        $data = is_array($decoded) ? $decoded : [];

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

        $commitSha = trim((string) ($data['commit_sha'] ?? ''));
        if ($commitSha !== '' && !preg_match('/^[a-fA-F0-9]{4,40}$/', $commitSha)) {
            return new JsonResponse(['error' => $this->trans('deployment.invalid_commit_sha', [], 'Invalid commit SHA format.')], 422);
        }

        $branch = isset($data['branch']) ? trim((string) $data['branch']) : null;
        if ($branch !== null) {
            if (strlen($branch) > 100 || preg_match('/[\r\n\x00-\x1F\x7F]/', $branch)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_branch', [], 'Invalid branch name.')], 422);
            }
        }

        $previewUrl = null;
        if (!empty($data['preview_url'])) {
            $pUrl = trim((string) $data['preview_url']);
            if (strlen($pUrl) > 2048 || preg_match('/[\r\n\x00-\x1F\x7F]/', $pUrl) || !filter_var($pUrl, FILTER_VALIDATE_URL)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_preview_url', [], 'Invalid preview URL.')], 422);
            }
            $pScheme = strtolower((string) parse_url($pUrl, PHP_URL_SCHEME));
            if (!in_array($pScheme, ['http', 'https'], true)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_preview_url_scheme', [], 'Invalid preview URL scheme: only http and https allowed.')], 422);
            }
            if (!$this->validateSafeUrl($pUrl)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_preview_url_ssrf', [], 'Invalid preview URL: private or restricted address rejected for security.')], 422);
            }
            $previewUrl = $pUrl;
        }

        $runUrl = null;
        if (!empty($data['run_url'])) {
            $rUrl = trim((string) $data['run_url']);
            if (strlen($rUrl) > 2048 || preg_match('/[\r\n\x00-\x1F\x7F]/', $rUrl) || !filter_var($rUrl, FILTER_VALIDATE_URL)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_run_url', [], 'Invalid run URL.')], 422);
            }
            $rScheme = strtolower((string) parse_url($rUrl, PHP_URL_SCHEME));
            if (!in_array($rScheme, ['http', 'https'], true)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_run_url_scheme', [], 'Invalid run URL scheme: only http and https allowed.')], 422);
            }
            if (!$this->validateSafeUrl($rUrl)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_run_url_ssrf', [], 'Invalid run URL: private or restricted address rejected for security.')], 422);
            }
            $runUrl = $rUrl;
        }

        $imageRef = null;
        if (isset($data['image'])) {
            $img = trim((string) $data['image']);
            if (strlen($img) > 500 || preg_match('/[\r\n\x00-\x1F\x7F]/', $img)) {
                return new JsonResponse(['error' => $this->trans('deployment.invalid_image_reference', [], 'Invalid image reference.')], 422);
            }
            $imageRef = $img !== '' ? $img : null;
        }

        $errorMessage = null;
        if (isset($data['error'])) {
            $errorMessage = substr((string) $data['error'], 0, 2048);
        }

        $deployment = new Deployment();
        $deployment->commitSha = substr($commitSha, 0, 40);
        $deployment->branch = $branch;
        $deployment->environment = $environment;
        $deployment->status = $status;
        $deployment->imageRef = $imageRef;
        $deployment->previewUrl = $previewUrl;
        $deployment->runUrl = $runUrl;
        $deployment->errorMessage = $errorMessage;

        if (in_array($status, [Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED, Deployment::STATUS_CANCELED], true)) {
            $deployment->finishedAt = new \DateTimeImmutable();
        }

        if (!empty($data['project_uuid'])) {
            try {
                $project = $this->projects->findOneBy(['uuid' => $data['project_uuid']]);
                if ($project) {
                    $deployment->project = $project;
                }
            } catch (\Throwable) {
                // Ignore malformed project uuid
            }
        }

        $this->em->persist($deployment);
        $this->em->flush();

        return new JsonResponse(['data' => $this->serialize($deployment)], 201);
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
