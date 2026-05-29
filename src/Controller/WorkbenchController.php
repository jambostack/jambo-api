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
use App\Workbench\Templates\BaseTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\AI\Platform\PlatformInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class WorkbenchController extends InertiaController
{
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

        return $this->inertia($request, 'Projects/Workbench/WorkbenchPage', [
            'project' => [
                'id'   => $project->id,
                'uuid' => $project->uuid->toRfc4122(),
                'name' => $project->name,
            ],
            'collections'       => $collectionsData,
            'workbenchProjects' => array_map(fn (WorkbenchProject $w) => $this->serializeWorkbench($w), $workbenchProjects),
            'frameworks'        => array_map(fn ($t) => ['id' => $t->getId(), 'label' => $t->getLabel()], $this->templates),
            'userCan'           => [],
        ]);
    }

    #[Route('/api/projects/{uuid}/workbench/generate', name: 'workbench_generate', methods: ['POST'])]
    public function generate(string $uuid, Request $request): Response
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.view', $project);

        $body = $request->toArray();
        $userPrompt = trim((string) ($body['prompt'] ?? ''));
        $framework  = $body['framework'] ?? 'nextjs';

        if ($userPrompt === '') {
            return new JsonResponse(['error' => 'Prompt requis'], 422);
        }
        if (!in_array($framework, WorkbenchProject::FRAMEWORKS, true)) {
            return new JsonResponse(['error' => 'Framework invalide'], 422);
        }

        [$platform, $model] = $this->resolveProvider();
        if ($platform === null) {
            return new JsonResponse(['error' => 'Aucun fournisseur IA activé. Configurez-en un dans Paramètres → Fournisseurs IA.'], 503);
        }

        $apiUrl = $request->getSchemeAndHttpHost();
        $streamService = $this->streamService;

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
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $body = $request->toArray();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') return new JsonResponse(['error' => 'name requis'], 422);

        $workbench = new WorkbenchProject();
        $workbench->project         = $project;
        $workbench->name            = $name;
        $workbench->framework       = in_array($body['framework'] ?? '', WorkbenchProject::FRAMEWORKS, true) ? $body['framework'] : 'nextjs';
        $workbench->files           = is_array($body['files'] ?? null) ? $body['files'] : [];
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
        if (!$project) return new JsonResponse(['error' => 'Projet introuvable'], 404);
        $this->denyAccessUnlessGranted('project.manage', $project);

        $workbench = $this->workbenchRepository->findOneBy(['uuid' => $workbenchUuid, 'project' => $project]);
        if (!$workbench) return new JsonResponse(['error' => 'WorkbenchProject introuvable'], 404);

        $body = $request->toArray();
        if (isset($body['files']) && is_array($body['files'])) $workbench->files = $body['files'];
        if (isset($body['name']))  $workbench->name  = (string) $body['name'];
        $workbench->touch();
        $this->em->flush();

        return new JsonResponse(['data' => $this->serializeWorkbench($workbench)]);
    }

    private function serializeWorkbench(WorkbenchProject $w): array
    {
        return [
            'uuid'          => $w->uuid->toRfc4122(),
            'name'          => $w->name,
            'framework'     => $w->framework,
            'files'         => $w->files,
            'deploy_status' => $w->deployStatus,
            'created_at'    => $w->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'    => $w->updatedAt->format(\DateTimeInterface::ATOM),
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
