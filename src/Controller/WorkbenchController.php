<?php
namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Entity\WorkbenchProject;
use App\Repository\AppSettingsRepository;
use App\Repository\ProjectRepository;
use App\Repository\WorkbenchProjectRepository;
use App\Service\JamboClientGenerator;
use App\Service\WorkbenchStreamService;
use App\Entity\SiteDomain;
use App\Entity\WorkbenchEnvVar;
use App\Repository\SiteDomainRepository;
use App\Repository\WorkbenchEnvVarRepository;
use App\Service\PublishedSiteStorage;
use App\Service\ZipExportService;
use App\Workbench\Templates\BaseTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class WorkbenchController extends InertiaController
{
    private const MAX_PROMPT_LENGTH = 4000;
    private const MAX_FILES_BYTES = 2 * 1024 * 1024;

    /** @param BaseTemplate[] $templates */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        private readonly WorkbenchProjectRepository $workbenchRepository,
        private readonly WorkbenchStreamService $streamService,
        private readonly AppSettingsRepository $appSettingsRepository,
        private readonly JamboClientGenerator $clientGenerator,
        private readonly PlatformInterface $openaiPlatform,
        private readonly PlatformInterface $anthropicPlatform,
        private readonly PlatformInterface $ollamaPlatform,
        private readonly PlatformInterface $deepseekPlatform,
        private readonly array $templates,
        private readonly ZipExportService $zipExportService,
        private readonly PublishedSiteStorage $publishedSiteStorage,
        private readonly WorkbenchEnvVarRepository $envVarRepository,
        private readonly SiteDomainRepository $siteDomainRepository,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/projects/{project}/workbench', name: 'workbench_page', requirements: ['project' => '\d+'], priority: 10)]
    public function index(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('project.view', $project);

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

        $collectionsData = array_map(fn (Collection $c) => [
            'id'     => $c->id,
            'uuid'   => $c->uuid?->toRfc4122(),
            'name'   => $c->name,
            'slug'   => $c->slug,
            'fields' => array_values(array_filter(
                array_map(fn ($f) => $f->isDeleted() ? null : [
                    'name' => $f->name, 'slug' => $f->slug,
                    'type' => $f->type, 'isRequired' => $f->isRequired,
                ], $c->fields->toArray())
            )),
        ], $collections);

        $workbenchProjects = $this->workbenchRepository->findByProject($project);

        $apiUrl = $request->getSchemeAndHttpHost();
        $collectionsForClient = array_map(fn (Collection $c) => [
            'name' => $c->name, 'slug' => $c->slug,
            'fields' => array_values(array_filter(
                array_map(fn ($f) => $f->isDeleted() ? null : [
                    'name' => $f->name, 'slug' => $f->slug,
                    'type' => $f->type, 'isRequired' => $f->isRequired,
                ], $c->fields->toArray())
            )),
        ], $collections);

        $starterFilesByFramework = [];
        foreach ($this->templates as $template) {
            $starterFilesByFramework[$template->getId()] = $template->getStarterFiles(
                $apiUrl, $project->uuid->toRfc4122(), $collectionsForClient,
            );
        }

        return $this->inertia($request, 'Projects/Workbench/WorkbenchPage', [
            'project' => [
                'id'   => $project->id,
                'uuid' => $project->uuid->toRfc4122(),
                'name' => $project->name,
            ],
            'collections'            => $collectionsData,
            'workbenchProjects'      => array_map(fn (WorkbenchProject $w) => $this->serializeWorkbench($w), $workbenchProjects),
            'frameworks'             => array_map(fn ($t) => ['id' => $t->getId(), 'label' => $t->getLabel()], $this->templates),
            'userCan'                => [],
            'starterFilesByFramework'=> $starterFilesByFramework,
        ]);
    }

    #[Route('/api/projects/{uuid}/workbench/generate', name: 'workbench_generate', methods: ['POST'])]
    public function generate(string $uuid, Request $request): Response
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $body = $request->toArray();
        $userPrompt = trim((string) ($body['prompt'] ?? ''));
        $framework  = $body['framework'] ?? 'nextjs';

        if ($userPrompt === '') return new JsonResponse(['error' => $this->translator->trans('workbench.errors.prompt_required')], 422);
        if (mb_strlen($userPrompt) > self::MAX_PROMPT_LENGTH) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.prompt_too_long', ['%max%' => self::MAX_PROMPT_LENGTH])], 422);
        }
        if (!in_array($framework, WorkbenchProject::FRAMEWORKS, true)) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.invalid_framework')], 422);
        }

        [$platform, $model] = $this->resolveProvider();
        if ($platform === null) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.no_ai_provider')], 503);
        }

        $apiUrl = $request->getSchemeAndHttpHost();
        $streamService = $this->streamService;
        $controller = new AbortController();
        $abortRef = null;

        $response = new StreamedResponse(function () use ($streamService, $project, $userPrompt, $framework, $apiUrl, $platform, $model) {
            foreach ($streamService->stream($project, $userPrompt, $framework, $apiUrl, $platform, $model) as $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) ob_flush();
                flush();
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route('/api/projects/{uuid}/workbench/templates', name: 'workbench_templates', methods: ['GET'])]
    public function templates(string $uuid): JsonResponse
    {
        return $this->json([
            'data' => array_map(fn ($t) => ['id' => $t->getId(), 'label' => $t->getLabel()], $this->templates),
        ]);
    }

    #[Route('/api/projects/{uuid}/workbench/save', name: 'workbench_save', methods: ['POST'])]
    public function save(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = $request->toArray();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') return new JsonResponse(['error' => $this->translator->trans('workbench.errors.name_required')], 422);

        $files = is_array($body['files'] ?? null) ? $body['files'] : [];
        if (($error = $this->validateFilesSize($files)) !== null) {
            return new JsonResponse(['error' => $error], 422);
        }

        $workbench = new WorkbenchProject();
        $workbench->project         = $project;
        $workbench->name            = $name;
        $workbench->framework       = in_array($body['framework'] ?? '', WorkbenchProject::FRAMEWORKS, true) ? $body['framework'] : 'nextjs';
        $workbench->files           = $files;
        $workbench->generatedPrompt = isset($body['prompt']) ? (string) $body['prompt'] : null;
        $workbench->createdBy       = $this->getUser();

        $this->em->persist($workbench);
        $this->em->flush();

        return new JsonResponse(['data' => $this->serializeWorkbench($workbench)], 201);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}', name: 'workbench_update', methods: ['PUT', 'PATCH'])]
    public function update(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $body = $request->toArray();
        if (isset($body['files']) && is_array($body['files'])) {
            if (($error = $this->validateFilesSize($body['files'])) !== null) {
                return new JsonResponse(['error' => $error], 422);
            }
            $workbench->files = $body['files'];
        }
        if (isset($body['name'])) $workbench->name = (string) $body['name'];
        $workbench->touch();
        $this->em->flush();

        return new JsonResponse(['data' => $this->serializeWorkbench($workbench)]);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/export', name: 'workbench_export', methods: ['GET'])]
    public function export(string $uuid, string $workbenchUuid): Response
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);
        if (empty($workbench->files)) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.no_files_export')], 422);
        }

        $zipBytes = $this->zipExportService->export($workbench);
        $filename = $this->zipExportService->suggestedFilename($workbench);

        $tmpFile = tempnam(sys_get_temp_dir(), 'jambo_zip_') . '.zip';
        file_put_contents($tmpFile, $zipBytes);

        $response = new BinaryFileResponse($tmpFile);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    // ── Env Vars ──────────────────────────────────────────────────────────

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/env', name: 'workbench_env_list', methods: ['GET'])]
    public function envList(string $uuid, string $workbenchUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $vars = $this->envVarRepository->findByWorkbench($workbench);

        return new JsonResponse(['data' => array_map(fn (WorkbenchEnvVar $v) => [
            'id'       => $v->id,
            'key_name' => $v->keyName,
            'value'    => $v->isSecret ? null : $v->value,
            'is_secret'=> $v->isSecret,
        ], $vars)]);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/env', name: 'workbench_env_create', methods: ['POST'])]
    public function envCreate(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $body    = $request->toArray();
        $keyName = strtoupper(trim((string) ($body['key_name'] ?? '')));
        $value   = (string) ($body['value'] ?? '');

        if ($keyName === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $keyName)) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.invalid_env_key')], 422);
        }
        if ($this->envVarRepository->findOneByKey($workbench, $keyName) !== null) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.env_key_duplicate')], 409);
        }

        $var = new WorkbenchEnvVar();
        $var->workbenchProject = $workbench;
        $var->keyName   = $keyName;
        $var->value     = $value;
        $var->isSecret  = (bool) ($body['is_secret'] ?? false);
        $this->em->persist($var);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.env_key_duplicate')], 409);
        }

        return new JsonResponse(['data' => ['id' => $var->id, 'key_name' => $var->keyName, 'is_secret' => $var->isSecret]], 201);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/env/{envId}', name: 'workbench_env_delete', methods: ['DELETE'])]
    public function envDelete(string $uuid, string $workbenchUuid, int $envId): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $var = $this->envVarRepository->find($envId);
        if ($var === null || $var->workbenchProject->id !== $workbench->id) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.env_var_not_found')], 404);
        }

        $this->em->remove($var);
        $this->em->flush();

        return new JsonResponse(['deleted' => $envId]);
    }

    // ── Publish ───────────────────────────────────────────────────────────

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/publish', name: 'workbench_publish', methods: ['POST'])]
    public function publish(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $body = $request->toArray();
        $files = $body['files'] ?? [];

        if (!is_array($files) || count($files) === 0) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.no_files_received')], 422);
        }

        // Vérification template statique
        $template = null;
        foreach ($this->templates as $t) {
            if ($t->getId() === $workbench->framework) { $template = $t; break; }
        }
        if ($template === null || $template->getStaticOutputDir() === null) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.no_static_publish')], 422);
        }

        // Limite de taille : 25 Mo
        $totalBytes = array_sum(array_map('strlen', $files));
        if ($totalBytes > 25 * 1024 * 1024) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.payload_too_large')], 422);
        }

        $this->publishedSiteStorage->publish($workbench->uuid->toRfc4122(), $files);
        $workbench->publishedAt = new \DateTimeImmutable();
        $this->em->flush();

        // Retourner les variables non-secrètes pour référence (le build est déjà fait côté client).
        // Les secrets (isSecret=true) ne sont jamais renvoyés dans la réponse.
        $envVars = $this->envVarRepository->findByWorkbench($workbench);
        $env = [];
        foreach ($envVars as $v) {
            if (!$v->isSecret) {
                $env[$v->keyName] = $v->value;
            }
        }

        return new JsonResponse([
            'published_at' => $workbench->publishedAt->format(\DateTimeInterface::ATOM),
            'env'          => $env,
        ]);
    }

    // ── Site Domains ──────────────────────────────────────────────────────

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/domains', name: 'workbench_domain_list', methods: ['GET'])]
    public function domainList(string $uuid, string $workbenchUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $domains = $this->siteDomainRepository->findByWorkbench($workbench);

        return new JsonResponse(['data' => array_map(fn (SiteDomain $d) => [
            'uuid'       => $d->uuid->toRfc4122(),
            'domain'     => $d->domain,
            'is_primary' => $d->isPrimary,
        ], $domains)]);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/domains', name: 'workbench_domain_add', methods: ['POST'])]
    public function domainAdd(string $uuid, string $workbenchUuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $domain = strtolower(trim((string) ($request->toArray()['domain'] ?? '')));
        if ($domain === '' || strlen($domain) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.invalid_domain')], 422);
        }
        if ($this->siteDomainRepository->findByDomain($domain) !== null) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.domain_duplicate')], 409);
        }

        $existingDomains = $this->siteDomainRepository->findByWorkbench($workbench);
        $isPrimary = count($existingDomains) === 0;

        $sd = new SiteDomain();
        $sd->workbenchProject = $workbench;
        $sd->domain           = $domain;
        $sd->isPrimary        = $isPrimary;
        $this->em->persist($sd);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['error' => $this->translator->trans('workbench.errors.domain_duplicate')], 409);
        }

        return new JsonResponse(['data' => [
            'uuid'       => $sd->uuid->toRfc4122(),
            'domain'     => $sd->domain,
            'is_primary' => $sd->isPrimary,
        ]], 201);
    }

    #[Route('/api/projects/{uuid}/workbench/{workbenchUuid}/domains/{domainUuid}', name: 'workbench_domain_delete', methods: ['DELETE'])]
    public function domainDelete(string $uuid, string $workbenchUuid, string $domainUuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.project_not_found')], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.not_found')], 404);

        $sd = $this->siteDomainRepository->findOneBy(['uuid' => $domainUuid, 'workbenchProject' => $workbench]);
        if (!$sd) return new JsonResponse(['error' => $this->translator->trans('workbench.errors.domain_not_found')], 404);

        $this->em->remove($sd);
        $this->em->flush();

        return new JsonResponse(['deleted' => $domainUuid]);
    }

    private function validateFilesSize(array $files): ?string
    {
        $bytes = strlen((string) json_encode($files));
        if ($bytes > self::MAX_FILES_BYTES) {
            return $this->translator->trans('workbench.errors.files_too_large', ['%max%' => intdiv(self::MAX_FILES_BYTES, 1024 * 1024)]);
        }
        return null;
    }

    private function serializeWorkbench(WorkbenchProject $w): array
    {
        return [
            'uuid'         => $w->uuid->toRfc4122(),
            'name'         => $w->name,
            'framework'    => $w->framework,
            'files'        => $w->files,
            'published_at' => $w->publishedAt?->format(\DateTimeInterface::ATOM),
            'created_at'   => $w->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'   => $w->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveProvider(): array
    {
        $config = $this->appSettingsRepository->getOrCreate()->aiProviders ?? [];
        $candidates = [
            'openai'    => [$this->openaiPlatform,    $config['openai']['model']    ?? 'gpt-4o'],
            'anthropic' => [$this->anthropicPlatform, $config['anthropic']['model'] ?? 'claude-sonnet-4-6'],
            'deepseek'  => [$this->deepseekPlatform,  $config['deepseek']['model']  ?? 'deepseek-chat'],
            'ollama'    => [$this->ollamaPlatform,     $config['ollama']['model']    ?? 'llama3.2'],
        ];
        foreach ($candidates as $name => [$platform, $model]) {
            if (!empty($config[$name]['enabled'])) return [$platform, $model];
        }
        return [null, null];
    }
}
