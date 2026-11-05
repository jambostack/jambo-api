<?php

namespace App\Controller;

use App\Entity\Deployment;
use App\Repository\DeploymentRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
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

/**
 * Jambo Deploy — endpoints d'observabilité du pipeline CI/CD.
 *
 *  - /api/system/info      : version + commit (lecture libre, public)
 *  - /api/deployments      : derniers déploiements (auth requise)
 *  - /api/deployments/hook : webhook signé pour mise à jour depuis GitHub Actions
 */
class DeploymentController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 1048576; // 1MB
    public const MAX_BRANCH_LENGTH = 100;
    public const MAX_URL_LENGTH = 500;
    public const MAX_IMAGE_LENGTH = 500;
    public const MAX_ERROR_LENGTH = 2048;
    public const MIN_SECRET_LENGTH = 16;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    private SsrfProtectionService $ssrfProtection;

    public function __construct(
        private EntityManagerInterface $em,
        private DeploymentRepository $deployments,
        private ProjectRepository $projects,
        #[Autowire(service: 'limiter.deployment_hook_limiter')]
        private ?RateLimiterFactory $hookLimiter = null,
        private ?TranslatorInterface $translator = null,
        ?SsrfProtectionService $ssrfProtection = null,
        private ?AuditService $auditService = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function validateSafeUrl(string $url): bool
    {
        return $this->ssrfProtection->isSafeUrl($url, false);
    }

    /**
     * Info de build — utilisée par le frontend pour afficher la version courante.
     * Pas d'authentification : on n'expose que des métadonnées non-sensibles.
     */
    #[Route('/api/system/info', name: 'system_info', methods: ['GET'])]
    public function systemInfo(): JsonResponse
    {
        try {
            $rootDir = (string) $this->getParameter('kernel.project_dir');
        } catch (\Throwable) {
            $rootDir = dirname(__DIR__, 2);
        }

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
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/api/deployments', name: 'deployments_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return new JsonResponse([
                'error' => $this->trans('deployment.auth_required', [], 'Authentication required.'),
                'code' => 'AUTH_REQUIRED',
            ], 401, self::SECURITY_HEADERS);
        }

        if (!$this->isGranted('ROLE_SUPER_ADMIN') && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse([
                'error' => $this->trans('deployment.access_denied', [], 'Access denied.'),
                'code' => 'ACCESS_DENIED',
            ], 403, self::SECURITY_HEADERS);
        }

        $limit = min(100, max(1, $request->query->getInt('limit', 25)));

        $projectUuid = $request->query->get('project') ?: $request->query->get('project_uuid');
        if ($projectUuid !== null && is_string($projectUuid) && trim($projectUuid) !== '') {
            $pUuid = trim($projectUuid);
            if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $pUuid)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_project_uuid', [], 'Invalid project UUID format.'),
                    'code' => 'INVALID_PROJECT_UUID',
                ], 422, self::SECURITY_HEADERS);
            }
            $project = $this->projects->findOneBy(['uuid' => $pUuid]);
            if ($project === null) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.project_not_found', [], 'Project not found.'),
                    'code' => 'PROJECT_NOT_FOUND',
                ], 404, self::SECURITY_HEADERS);
            }
            if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
                $this->denyAccessUnlessGranted('project.view', $project);
            }
            $deployments = $this->deployments->findForProject($project, $limit);
        } else {
            $deployments = $this->deployments->findRecent($limit);
        }

        return new JsonResponse([
            'data' => array_map(fn (Deployment $d) => $this->serialize($d), $deployments),
        ], 200, self::SECURITY_HEADERS);
    }

    /**
     * Webhook signé HMAC-SHA256 appelé par le workflow GitHub Actions.
     *
     * Le secret partagé est lu dans `DEPLOY_WEBHOOK_SECRET`. La signature
     * arrive dans le header `X-Jambo-Signature: sha256=<hex>` ou `X-Hub-Signature-256: sha256=<hex>`.
     */
    #[Route('/api/deployments/hook', name: 'deployments_hook', methods: ['POST'])]
    public function hook(Request $request): JsonResponse
    {
        if ($this->hookLimiter !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->hookLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.too_many_requests', [], 'Too many deployment hook requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }

        $secret = (string) ($_ENV['DEPLOY_WEBHOOK_SECRET'] ?? $_SERVER['DEPLOY_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            return new JsonResponse([
                'error' => $this->trans('deployment.webhook_disabled', [], 'Deployment webhook disabled: DEPLOY_WEBHOOK_SECRET not configured.'),
                'code' => 'WEBHOOK_DISABLED',
            ], 503, self::SECURITY_HEADERS);
        }

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            return new JsonResponse([
                'error' => $this->trans('deployment.secret_too_short', [], 'Deployment webhook secret is too short (min 16 characters).'),
                'code' => 'WEBHOOK_DISABLED',
            ], 503, self::SECURITY_HEADERS);
        }

        $payload = $request->getContent();
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('deployment.payload_too_large', [], 'Payload too large (max 1MB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413, self::SECURITY_HEADERS);
        }

        $signature = (string) ($request->headers->get('X-Jambo-Signature') ?: $request->headers->get('X-Hub-Signature-256') ?: '');
        if (!str_starts_with($signature, 'sha256=')) {
            // Anti-timing: perform dummy hash calculation before returning
            hash_hmac('sha256', $payload, $secret);
            return new JsonResponse([
                'error' => $this->trans('deployment.signature_missing', [], 'Signature header missing.'),
                'code' => 'SIGNATURE_MISSING',
            ], 401, self::SECURITY_HEADERS);
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            $this->auditService?->log(
                toolName: 'deployment_hook_auth',
                project: null,
                input: ['ip' => $request->getClientIp()],
                output: null,
                status: 'failure',
                errorMessage: 'Invalid signature',
                source: 'webhook',
            );
            return new JsonResponse([
                'error' => $this->trans('deployment.signature_invalid', [], 'Invalid signature.'),
                'code' => 'SIGNATURE_INVALID',
            ], 401, self::SECURITY_HEADERS);
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return new JsonResponse([
                'error' => $this->trans('deployment.invalid_json', [], 'Invalid JSON payload.'),
                'code' => 'INVALID_JSON',
            ], 400, self::SECURITY_HEADERS);
        }
        $data = $decoded;

        $rawEnv = $data['environment'] ?? null;
        if ($rawEnv !== null && $rawEnv !== '') {
            if (!in_array($rawEnv, [
                Deployment::ENV_PREVIEW,
                Deployment::ENV_STAGING,
                Deployment::ENV_PRODUCTION,
            ], true)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_environment', ['%env%' => (string) $rawEnv], "Invalid deployment environment: {$rawEnv}."),
                    'code' => 'INVALID_ENVIRONMENT',
                ], 422, self::SECURITY_HEADERS);
            }
            $environment = (string) $rawEnv;
        } else {
            $environment = Deployment::ENV_PREVIEW;
        }

        $rawStatus = $data['status'] ?? null;
        if ($rawStatus !== null && $rawStatus !== '') {
            if (!in_array($rawStatus, [
                Deployment::STATUS_PENDING,
                Deployment::STATUS_RUNNING,
                Deployment::STATUS_SUCCEEDED,
                Deployment::STATUS_FAILED,
                Deployment::STATUS_CANCELED,
            ], true)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_status', ['%status%' => (string) $rawStatus], "Invalid deployment status: {$rawStatus}."),
                    'code' => 'INVALID_STATUS',
                ], 422, self::SECURITY_HEADERS);
            }
            $status = (string) $rawStatus;
        } else {
            $status = Deployment::STATUS_PENDING;
        }

        $commitSha = trim((string) ($data['commit_sha'] ?? ''));
        if ($commitSha !== '') {
            if (!preg_match('/^[a-fA-F0-9]{4,40}$/', $commitSha)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_commit_sha', [], 'Invalid commit SHA format.'),
                    'code' => 'INVALID_COMMIT_SHA',
                ], 422, self::SECURITY_HEADERS);
            }
            $commitSha = strtolower($commitSha);
        }

        $branch = isset($data['branch']) ? trim((string) $data['branch']) : null;
        if ($branch !== null && $branch !== '') {
            if (strlen($branch) > self::MAX_BRANCH_LENGTH
                || preg_match('/[\r\n\x00-\x1F\x7F~^:?*\[\]\\\]|\.\.|\/\/|^[\/\.\-]|[\/\.]$|@\{|\.lock$/', $branch)
                || preg_match('/\s/', $branch)
            ) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_branch', [], 'Invalid branch name.'),
                    'code' => 'INVALID_BRANCH',
                ], 422, self::SECURITY_HEADERS);
            }
        } else {
            $branch = null;
        }

        $previewUrl = null;
        if (!empty($data['preview_url'])) {
            $pUrl = trim((string) $data['preview_url']);
            if (strlen($pUrl) > self::MAX_URL_LENGTH || preg_match('/[\r\n\x00-\x1F\x7F]/', $pUrl) || !filter_var($pUrl, FILTER_VALIDATE_URL)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_preview_url', [], 'Invalid preview URL.'),
                    'code' => 'INVALID_PREVIEW_URL',
                ], 422, self::SECURITY_HEADERS);
            }
            $pScheme = strtolower((string) parse_url($pUrl, PHP_URL_SCHEME));
            if (!in_array($pScheme, ['http', 'https'], true)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_preview_url_scheme', [], 'Invalid preview URL scheme: only http and https allowed.'),
                    'code' => 'INVALID_PREVIEW_URL_SCHEME',
                ], 422, self::SECURITY_HEADERS);
            }
            if (!$this->validateSafeUrl($pUrl)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_preview_url_ssrf', [], 'Invalid preview URL: private or restricted address rejected for security.'),
                    'code' => 'INVALID_PREVIEW_URL_SSRF',
                ], 422, self::SECURITY_HEADERS);
            }
            $previewUrl = $pUrl;
        }

        $runUrl = null;
        if (!empty($data['run_url'])) {
            $rUrl = trim((string) $data['run_url']);
            if (strlen($rUrl) > self::MAX_URL_LENGTH || preg_match('/[\r\n\x00-\x1F\x7F]/', $rUrl) || !filter_var($rUrl, FILTER_VALIDATE_URL)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_run_url', [], 'Invalid run URL.'),
                    'code' => 'INVALID_RUN_URL',
                ], 422, self::SECURITY_HEADERS);
            }
            $rScheme = strtolower((string) parse_url($rUrl, PHP_URL_SCHEME));
            if (!in_array($rScheme, ['http', 'https'], true)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_run_url_scheme', [], 'Invalid run URL scheme: only http and https allowed.'),
                    'code' => 'INVALID_RUN_URL_SCHEME',
                ], 422, self::SECURITY_HEADERS);
            }
            if (!$this->validateSafeUrl($rUrl)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_run_url_ssrf', [], 'Invalid run URL: private or restricted address rejected for security.'),
                    'code' => 'INVALID_RUN_URL_SSRF',
                ], 422, self::SECURITY_HEADERS);
            }
            $runUrl = $rUrl;
        }

        $imageRef = null;
        if (isset($data['image'])) {
            $img = trim((string) $data['image']);
            if (strlen($img) > self::MAX_IMAGE_LENGTH
                || preg_match('/[\r\n\x00-\x1F\x7F]/', $img)
                || !preg_match('/^[a-zA-Z0-9_\-\.\/@:]+$/', $img)
            ) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_image_reference', [], 'Invalid image reference.'),
                    'code' => 'INVALID_IMAGE_REFERENCE',
                ], 422, self::SECURITY_HEADERS);
            }
            $imageRef = $img !== '' ? $img : null;
        }

        $errorMessage = null;
        if (isset($data['error'])) {
            $sanitizedErr = preg_replace('/[^\P{C}\n\r\t]/u', '', (string) $data['error']);
            $errorMessage = substr($sanitizedErr, 0, self::MAX_ERROR_LENGTH);
        }

        $project = null;
        if (!empty($data['project_uuid'])) {
            $pUuid = trim((string) $data['project_uuid']);
            if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $pUuid)) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.invalid_project_uuid', [], 'Invalid project UUID format.'),
                    'code' => 'INVALID_PROJECT_UUID',
                ], 422, self::SECURITY_HEADERS);
            }
            try {
                $project = $this->projects->findOneBy(['uuid' => $pUuid]);
            } catch (\Throwable) {
                $project = null;
            }
            if ($project === null) {
                return new JsonResponse([
                    'error' => $this->trans('deployment.project_not_found', [], 'Project not found.'),
                    'code' => 'PROJECT_NOT_FOUND',
                ], 404, self::SECURITY_HEADERS);
            }
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
        $deployment->project = $project;

        if (in_array($status, [Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED, Deployment::STATUS_CANCELED], true)) {
            $deployment->finishedAt = new \DateTimeImmutable();
        }

        $this->em->persist($deployment);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'deployment_hook',
            project: $deployment->project,
            input: [
                'environment' => $deployment->environment,
                'branch' => $deployment->branch,
                'commit_sha' => $deployment->commitSha,
            ],
            output: [
                'uuid' => $deployment->uuid->toRfc4122(),
                'status' => $deployment->status,
            ],
            status: $deployment->status === Deployment::STATUS_FAILED ? 'failure' : 'success',
            errorMessage: $deployment->errorMessage,
            source: 'webhook',
        );

        return new JsonResponse(['data' => $this->serialize($deployment)], 201, self::SECURITY_HEADERS);
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
