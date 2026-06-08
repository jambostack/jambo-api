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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProjectStorageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectStorageProfileRepository $profileRepo,
        private readonly StorageRuleRepository $ruleRepo,
        private readonly StorageDriverFactory $driverFactory,
    ) {}

    // ── Stratégie ──────────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage', name: 'admin_project_storage_get', methods: ['GET'])]
    public function getConfig(string $uuid): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return new JsonResponse([
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

        try {
            $body = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        if (isset($body['strategy'])) {
            $strategy = (string) $body['strategy'];
            if (!in_array($strategy, ['default_only', 'mirror_all', 'rules'], true)) {
                return new JsonResponse(['error' => 'Invalid strategy.'], 400);
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

        $body = $request->toArray();
        $profile = $this->hydrateProfile(new ProjectStorageProfile(), $body, $project);

        if ($profile->isDefault) {
            $this->clearOtherDefaults($project, $profile);
        }

        $this->em->persist($profile);
        $this->em->flush();

        return new JsonResponse(['data' => $profile->toArray()], 201);
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
            return new JsonResponse(['error' => 'Profile not found.'], 404);
        }

        $this->hydrateProfile($profile, $request->toArray(), $project);

        if ($profile->isDefault) {
            $this->clearOtherDefaults($project, $profile);
        }

        $this->em->flush();

        return new JsonResponse(['data' => $profile->toArray()]);
    }

    #[Route('/api/admin/projects/{uuid}/storage/profiles/{id}', name: 'admin_project_storage_profile_delete', methods: ['DELETE'])]
    public function deleteProfile(string $uuid, int $id): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($this->profileRepo->countByProject($project) <= 1) {
            return new JsonResponse(['error' => 'Cannot delete the last storage profile.'], 422);
        }

        $profile = $this->profileRepo->find($id);
        if ($profile === null || $profile->project->id !== $project->id) {
            return new JsonResponse(['error' => 'Profile not found.'], 404);
        }

        if ($profile->isDefault) {
            return new JsonResponse(['error' => 'Cannot delete the default profile. Set another profile as default first.'], 422);
        }

        $this->em->remove($profile);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    // ── Règles CRUD ────────────────────────────────────────────────────

    #[Route('/api/admin/projects/{uuid}/storage/rules', name: 'admin_project_storage_rule_create', methods: ['POST'])]
    public function createRule(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $body = $request->toArray();
        $rule = $this->hydrateRule(new StorageRule(), $body, $project);
        if ($rule instanceof JsonResponse) {
            return $rule;
        }

        $this->em->persist($rule);
        $this->em->flush();

        return new JsonResponse($this->serializeRule($rule), 201);
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
            return new JsonResponse(['error' => 'Rule not found.'], 404);
        }

        $result = $this->hydrateRule($rule, $request->toArray(), $project);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $this->em->flush();

        return new JsonResponse($this->serializeRule($rule));
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
            return new JsonResponse(['error' => 'Rule not found.'], 404);
        }

        $this->em->remove($rule);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    // ─── Private helpers ────────────────────────────────────────────────

    private function findProject(string $uuid): Project|JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if ($project === null) {
            return new JsonResponse(['error' => 'Project not found'], 404);
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

    private function hydrateProfile(ProjectStorageProfile $p, array $body, Project $project): ProjectStorageProfile
    {
        if (isset($body['name'])) {
            $p->name = (string) $body['name'];
        }
        if (isset($body['driver'])) {
            $driver = (string) $body['driver'];
            if (!in_array($driver, ['local', 's3'], true)) {
                throw new \InvalidArgumentException("Invalid storage driver: $driver");
            }
            $p->driver = $driver;
        }
        if (isset($body['priority'])) {
            $p->priority = (int) $body['priority'];
        }
        if (isset($body['enabled'])) {
            $p->enabled = (bool) $body['enabled'];
        }
        if (isset($body['is_default'])) {
            $p->isDefault = (bool) $body['is_default'];
        }
        if (isset($body['s3_key'])) {
            $p->s3Key = (string) $body['s3_key'];
        }
        if (isset($body['s3_region'])) {
            $p->s3Region = (string) $body['s3_region'];
        }
        if (isset($body['s3_bucket'])) {
            $p->s3Bucket = (string) $body['s3_bucket'];
        }
        if (isset($body['s3_endpoint'])) {
            $p->s3Endpoint = (string) $body['s3_endpoint'];
        }
        if (isset($body['s3_use_path_style'])) {
            $p->s3UsePathStyle = (bool) $body['s3_use_path_style'];
        }
        if (isset($body['base_url'])) {
            $p->baseUrl = (string) $body['base_url'];
        }
        if (isset($body['root_path'])) {
            $p->rootPath = (string) $body['root_path'];
        }

        if (!empty($body['s3_secret'] ?? '')) {
            $p->s3Secret = $this->driverFactory->encrypt((string) $body['s3_secret']);
        }

        $p->project = $project;

        return $p;
    }

    private function hydrateRule(StorageRule $r, array $body, Project $project): StorageRule|JsonResponse
    {
        if (isset($body['mime_type_pattern'])) {
            $r->mimeTypePattern = (string) $body['mime_type_pattern'] ?: null;
        }
        if (isset($body['extension'])) {
            $r->extension = (string) $body['extension'] ?: null;
        }
        if (isset($body['max_size'])) {
            $r->maxSize = $body['max_size'] ? (int) $body['max_size'] : null;
        }
        if (isset($body['priority'])) {
            $r->priority = (int) $body['priority'];
        }

        if (isset($body['profile_uuid'])) {
            $profile = $this->profileRepo->findOneBy([
                'uuid'    => $body['profile_uuid'],
                'project' => $project,
            ]);
            if ($profile === null) {
                return new JsonResponse(['error' => 'Storage profile not found for this project.'], 422);
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
}
