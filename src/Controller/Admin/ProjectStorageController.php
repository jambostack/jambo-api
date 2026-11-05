<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Entity\StorageRule;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\StorageDriverFactory;
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
class ProjectStorageController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectStorageProfileRepository $profileRepo,
        private readonly StorageRuleRepository $ruleRepo,
        private readonly StorageDriverFactory $driverFactory,
        #[Autowire(service: 'limiter.storage_test_limiter')]
        private readonly ?RateLimiterFactory $storageTestLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    // ── Stratégie ──────────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage', name: 'admin_project_storage_get', methods: ['GET'])]
    public function getConfig(string $uuid): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return $this->secureJson([
            'strategy' => $project->storageStrategy,
            'profiles' => array_map(
                fn (ProjectStorageProfile $p) => $p->toArray(),
                $this->profileRepo->findByProject($project),
            ),
            'rules' => array_map(
                fn (StorageRule $r) => [
                    'id'                => $r->id,
                    'profile_uuid'      => $r->storageProfile->uuid?->toRfc4122(),
                    'mime_type_pattern' => $r->mimeTypePattern,
                    'extension'         => $r->extension,
                    'max_size'          => $r->maxSize,
                    'priority'          => $r->priority,
                ],
                $this->ruleRepo->findByProject($project),
            ),
        ]);
    }

    #[Route('/api/admin/projects/{uuid}/storage', name: 'admin_project_storage_update', methods: ['PUT'])]
    public function updateConfig(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('project.storage.payload_too_large', [], 'Payload exceeds maximum size limit (64 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON')], 400);
        }

        if (isset($body['strategy'])) {
            $strategy = (string) $body['strategy'];
            if (!in_array($strategy, ['default_only', 'mirror_all', 'rules'], true)) {
                return $this->secureJson(['error' => $this->trans('project.storage.invalid_strategy', [], 'Invalid strategy.')], 400);
            }
            $project->storageStrategy = $strategy;
        }

        $this->em->flush();

        return $this->getConfig($uuid);
    }

    // ── Profils CRUD ──────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage/profiles', name: 'admin_project_storage_profile_create', methods: ['POST'])]
    public function createProfile(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('project.storage.payload_too_large', [], 'Payload exceeds maximum size limit (64 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON')], 400);
        }

        $profile = $this->hydrateProfile(new ProjectStorageProfile(), $body, $project);
        if ($profile instanceof JsonResponse) {
            return $profile;
        }

        if ($profile->isDefault) {
            $this->clearOtherDefaults($project, $profile);
        }

        $this->em->persist($profile);
        $this->em->flush();

        return $this->secureJson(['data' => $profile->toArray()], 201);
    }

    #[Route('/api/admin/projects/{uuid}/storage/profiles/{id}', name: 'admin_project_storage_profile_update', methods: ['PUT'])]
    public function updateProfile(string $uuid, int $id, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $profile = $this->profileRepo->find($id);
        if ($profile === null || $profile->project->id !== $project->id) {
            return $this->secureJson(['error' => $this->trans('project.storage.profile_not_found', [], 'Profile not found.')], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('project.storage.payload_too_large', [], 'Payload exceeds maximum size limit (64 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON')], 400);
        }

        $res = $this->hydrateProfile($profile, $body, $project);
        if ($res instanceof JsonResponse) {
            return $res;
        }

        if ($profile->isDefault) {
            $this->clearOtherDefaults($project, $profile);
        }

        $this->em->flush();

        return $this->secureJson(['data' => $profile->toArray()]);
    }

    #[Route('/api/admin/projects/{uuid}/storage/profiles/{id}/test', name: 'admin_project_storage_profile_test', methods: ['POST'])]
    public function testProfile(Request $request, string $uuid, int $id): JsonResponse
    {
        if ($this->storageTestLimiter !== null) {
            $limiter = $this->storageTestLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('project.storage.too_many_tests', [], 'Too many test requests. Please retry later.')], 429);
            }
        }

        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $profile = $this->profileRepo->find($id);
        if ($profile === null || $profile->project->id !== $project->id) {
            return $this->secureJson(['error' => $this->trans('project.storage.profile_not_found', [], 'Profile not found.')], 404);
        }

        try {
            $filesystem = $this->driverFactory->create($profile);
            $testFileName = '.jambo_test_' . bin2hex(random_bytes(8)) . '.txt';
            $filesystem->write($testFileName, 'ping');
            if (!$filesystem->fileExists($testFileName)) {
                return $this->secureJson(['error' => $this->trans('project.storage.test_read_failed', [], 'Storage test failed: written file was not readable.')], 422);
            }
            $filesystem->delete($testFileName);

            return $this->secureJson([
                'success' => true,
                'message' => $this->trans('project.storage.test_success', ['%driver%' => $profile->driver], sprintf('Storage driver "%s" tested successfully.', $profile->driver)),
            ]);
        } catch (\Throwable $e) {
            return $this->secureJson([
                'error' => $this->trans('project.storage.test_failed', ['%message%' => $e->getMessage()], 'Storage test failed: ' . $e->getMessage()),
            ], 422);
        }
    }

    #[Route('/api/admin/projects/{uuid}/storage/profiles/{id}', name: 'admin_project_storage_profile_delete', methods: ['DELETE'])]
    public function deleteProfile(string $uuid, int $id): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($this->profileRepo->countByProject($project) <= 1) {
            return $this->secureJson(['error' => $this->trans('project.storage.cannot_delete_last_profile', [], 'Cannot delete the last storage profile.')], 422);
        }

        $profile = $this->profileRepo->find($id);
        if ($profile === null || $profile->project->id !== $project->id) {
            return $this->secureJson(['error' => $this->trans('project.storage.profile_not_found', [], 'Profile not found.')], 404);
        }

        if ($profile->isDefault) {
            return $this->secureJson(['error' => $this->trans('project.storage.cannot_delete_default_profile', [], 'Cannot delete the default profile. Set another profile as default first.')], 422);
        }

        $this->em->remove($profile);
        $this->em->flush();

        return $this->secureJson(null, 204);
    }

    // ── Règles CRUD ────────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage/rules', name: 'admin_project_storage_rule_create', methods: ['POST'])]
    public function createRule(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('project.storage.payload_too_large', [], 'Payload exceeds maximum size limit (64 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON')], 400);
        }

        $rule = $this->hydrateRule(new StorageRule(), $body, $project);
        if ($rule instanceof JsonResponse) {
            return $rule;
        }

        $this->em->persist($rule);
        $this->em->flush();

        return $this->secureJson($this->serializeRule($rule), 201);
    }

    #[Route('/api/admin/projects/{uuid}/storage/rules/{id}', name: 'admin_project_storage_rule_update', methods: ['PUT'])]
    public function updateRule(string $uuid, int $id, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $rule = $this->ruleRepo->find($id);
        if ($rule === null || $rule->project->id !== $project->id) {
            return $this->secureJson(['error' => $this->trans('project.storage.rule_not_found', [], 'Rule not found.')], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('project.storage.payload_too_large', [], 'Payload exceeds maximum size limit (64 KB).'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON')], 400);
        }

        $result = $this->hydrateRule($rule, $body, $project);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $this->em->flush();

        return $this->secureJson($this->serializeRule($rule));
    }

    #[Route('/api/admin/projects/{uuid}/storage/rules/{id}', name: 'admin_project_storage_rule_delete', methods: ['DELETE'])]
    public function deleteRule(string $uuid, int $id): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $rule = $this->ruleRepo->find($id);
        if ($rule === null || $rule->project->id !== $project->id) {
            return $this->secureJson(['error' => $this->trans('project.storage.rule_not_found', [], 'Rule not found.')], 404);
        }

        $this->em->remove($rule);
        $this->em->flush();

        return $this->secureJson(null, 204);
    }

    // ─── Private helpers ────────────────────────────────────────────────

    private function findProject(string $uuid): Project|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found')], 404);
        }
        if ($project === null) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);

        return $project;
    }

    private function clearOtherDefaults(Project $project, ProjectStorageProfile $current): void
    {
        foreach ($this->profileRepo->findByProject($project) as $p) {
            if ($p->id !== $current->id && $p->isDefault) {
                $p->isDefault = false;
            }
        }
    }

    private function hydrateProfile(ProjectStorageProfile $p, array $body, Project $project): ProjectStorageProfile|JsonResponse
    {
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return $this->secureJson(['error' => $this->trans('project.storage.invalid_profile_name', [], 'Invalid profile name: must be between 1 and 100 characters without control characters.')], 422);
            }
            $p->name = $name;
        }
        if (isset($body['driver'])) {
            $driver = (string) $body['driver'];
            if (!in_array($driver, ['local', 's3'], true)) {
                return $this->secureJson(['error' => $this->trans('project.storage.invalid_driver', ['%driver%' => $driver], "Invalid storage driver: $driver. Allowed: local, s3.")], 422);
            }
            $p->driver = $driver;
        }
        if (isset($body['priority'])) {
            $p->priority = max(-100000, min(100000, (int) $body['priority']));
        }
        if (isset($body['enabled'])) {
            $p->enabled = (bool) $body['enabled'];
        }
        if (isset($body['is_default'])) {
            $p->isDefault = (bool) $body['is_default'];
        }
        if (isset($body['s3_key'])) {
            $key = trim((string) $body['s3_key']);
            if (mb_strlen($key) > 255 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
                return $this->secureJson(['error' => $this->trans('project.storage.invalid_s3_key', [], 'Invalid S3 access key: must not exceed 255 characters without control characters.')], 422);
            }
            $p->s3Key = $key;
        }
        if (isset($body['s3_region'])) {
            $region = trim((string) $body['s3_region']);
            if (mb_strlen($region) > 64 || ($region !== '' && !preg_match('/^[a-z0-9-]+$/i', $region))) {
                return $this->secureJson(['error' => $this->trans('project.storage.invalid_s3_region', [], 'Invalid S3 region: must not exceed 64 characters and contain only alphanumeric characters and hyphens.')], 422);
            }
            $p->s3Region = $region;
        }
        if (isset($body['s3_bucket'])) {
            $bucket = trim((string) $body['s3_bucket']);
            if (mb_strlen($bucket) > 63 || ($bucket !== '' && !preg_match('/^[a-z0-9.\-_]+$/i', $bucket))) {
                return $this->secureJson(['error' => $this->trans('project.storage.invalid_s3_bucket', [], 'Invalid S3 bucket name: must be between 1 and 63 valid DNS characters.')], 422);
            }
            $p->s3Bucket = $bucket;
        }
        if (isset($body['s3_endpoint'])) {
            $endpoint = trim((string) $body['s3_endpoint']);
            if ($endpoint !== '') {
                if (mb_strlen($endpoint) > 2048) {
                    return $this->secureJson(['error' => $this->trans('project.storage.invalid_s3_endpoint', [], 'Invalid S3 endpoint URL: must be a valid public HTTP/HTTPS URL.')], 422);
                }
                try {
                    $this->driverFactory->validateS3Endpoint($endpoint);
                } catch (\Throwable) {
                    return $this->secureJson(['error' => $this->trans('project.storage.invalid_s3_endpoint', [], 'Invalid S3 endpoint URL: must be a valid public HTTP/HTTPS URL.')], 422);
                }
            }
            $p->s3Endpoint = $endpoint !== '' ? $endpoint : null;
        }
        if (isset($body['s3_use_path_style'])) {
            $p->s3UsePathStyle = (bool) $body['s3_use_path_style'];
        }
        if (isset($body['base_url'])) {
            $baseUrl = trim((string) $body['base_url']);
            if ($baseUrl !== '') {
                if (mb_strlen($baseUrl) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $baseUrl)) {
                    return $this->secureJson(['error' => $this->trans('project.storage.invalid_base_url', [], 'Invalid base URL: must not exceed 2048 characters and must be a valid URL or path.')], 422);
                }
                if (!str_starts_with($baseUrl, '/') && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                    return $this->secureJson(['error' => $this->trans('project.storage.invalid_base_url', [], 'Invalid base URL: must not exceed 2048 characters and must be a valid URL or path.')], 422);
                }
            }
            $p->baseUrl = $baseUrl !== '' ? $baseUrl : null;
        }
        if (isset($body['root_path'])) {
            $rootPath = trim((string) $body['root_path']);
            if ($rootPath !== '') {
                if (mb_strlen($rootPath) > 500 || str_contains($rootPath, "\0") || str_contains($rootPath, '..')) {
                    return $this->secureJson(['error' => $this->trans('project.storage.invalid_root_path', [], 'Invalid root path: directory traversal (..) or control characters are not allowed.')], 422);
                }
            }
            $p->rootPath = $rootPath !== '' ? $rootPath : null;
        }

        if (!empty($body['s3_secret'] ?? '')) {
            $secret = (string) $body['s3_secret'];
            if (mb_strlen($secret) > 1024) {
                return $this->secureJson(['error' => $this->trans('project.storage.s3_secret_too_long', [], 'S3 secret key must not exceed 1024 characters.')], 422);
            }
            $p->s3Secret = $this->driverFactory->encrypt($secret);
        }

        $p->project = $project;

        return $p;
    }

    private function hydrateRule(StorageRule $r, array $body, Project $project): StorageRule|JsonResponse
    {
        if (isset($body['mime_type_pattern'])) {
            $mime = trim((string) $body['mime_type_pattern']);
            if ($mime !== '' && (mb_strlen($mime) > 100 || preg_match('/[\x00-\x1F\x7F]/', $mime))) {
                return $this->secureJson(['error' => $this->trans('project.storage.invalid_mime_pattern', [], 'Invalid MIME type pattern: must not exceed 100 characters.')], 422);
            }
            $r->mimeTypePattern = $mime !== '' ? $mime : null;
        }
        if (isset($body['extension'])) {
            $ext = trim((string) $body['extension']);
            if ($ext !== '') {
                $ext = ltrim($ext, '.');
                if (mb_strlen($ext) > 20 || !preg_match('/^[a-z0-9]+$/i', $ext)) {
                    return $this->secureJson(['error' => $this->trans('project.storage.invalid_extension', [], 'Invalid extension: must not exceed 20 alphanumeric characters.')], 422);
                }
            }
            $r->extension = $ext !== '' ? strtolower($ext) : null;
        }
        if (isset($body['max_size'])) {
            $maxSize = $body['max_size'];
            if ($maxSize !== null && $maxSize !== '') {
                if (!is_numeric($maxSize) || (int) $maxSize < 0 || (int) $maxSize > 10737418240) {
                    return $this->secureJson(['error' => $this->trans('project.storage.invalid_max_size', [], 'Invalid max size: must be a positive integer not exceeding 10GB.')], 422);
                }
                $r->maxSize = (int) $maxSize;
            } else {
                $r->maxSize = null;
            }
        }
        if (isset($body['priority'])) {
            $r->priority = max(-100000, min(100000, (int) $body['priority']));
        }

        if (isset($body['profile_uuid'])) {
            try {
                $profile = $this->profileRepo->findOneBy([
                    'uuid'    => $body['profile_uuid'],
                    'project' => $project,
                ]);
            } catch (\Throwable) {
                $profile = null;
            }
            if ($profile === null) {
                return $this->secureJson(['error' => $this->trans('project.storage.profile_not_found_for_project', [], 'Storage profile not found for this project.')], 422);
            }
            $r->storageProfile = $profile;
        }

        $r->project = $project;

        return $r;
    }

    private function serializeRule(StorageRule $r): array
    {
        return [
            'id'                => $r->id,
            'profile_uuid'      => $r->storageProfile->uuid?->toRfc4122(),
            'mime_type_pattern' => $r->mimeTypePattern,
            'extension'         => $r->extension,
            'max_size'          => $r->maxSize,
            'priority'          => $r->priority,
        ];
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }
}
