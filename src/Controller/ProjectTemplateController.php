<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use App\Service\ProjectTemplateBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/project-templates', name: 'api_project_template_')]
class ProjectTemplateController extends AbstractController
{
    public function __construct(
        private readonly ProjectTemplateRepository $templateRepository,
        private readonly ProjectTemplateBuilder $builder,
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $templates = $this->templateRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->json([
            'data' => array_map(fn ($t) => [
                'id'          => $t->id,
                'name'        => $t->name,
                'description' => $t->description,
                'createdAt'   => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $templates),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        if ($id <= 0) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        return $this->json([
            'id'          => $template->id,
            'name'        => $template->name,
            'description' => $template->description,
            'structure'   => $template->structure,
            'createdAt'   => $template->createdAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/from-project/{projectUuid}', name: 'save_from_project', methods: ['POST'])]
    public function saveFromProject(string $projectUuid, Request $request): JsonResponse
    {
        if (!Uuid::isValid($projectUuid)) {
            return $this->json(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404);
        }
        if ($project === null) {
            return $this->json(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : null;
        $name = $rawName !== null && $rawName !== '' ? mb_substr($rawName, 0, 255) : null;

        $rawDesc = isset($data['description']) && is_scalar($data['description']) ? trim((string) $data['description']) : null;
        $desc = $rawDesc !== null && $rawDesc !== '' ? mb_substr($rawDesc, 0, 2000) : null;

        $template = $this->builder->exportFromProject($projectUuid, $name, $desc);

        if ($template === null) {
            return $this->json(['error' => $this->trans('template.project_not_found', [], 'Project not found')], 404);
        }

        return $this->json([
            'id'        => $template->id,
            'name'      => $template->name,
            'createdAt' => $template->createdAt->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/{id}/apply', name: 'apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => $this->trans('template.auth_required', [], 'Authentication required')], 401);
        }

        if ($id <= 0) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('template.invalid_json', [], 'Invalid JSON')], 400);
        }

        $rawName = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : '';
        $projectName = $rawName !== '' ? mb_substr($rawName, 0, 255) : $template->name;

        $project = $this->builder->applyTemplate($template, $projectName, $user);

        return $this->json([
            'uuid' => $project->uuid?->toString(),
            'name' => $project->name,
        ], 201);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($id <= 0) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => $this->trans('template.not_found', [], 'Template not found')], 404);
        }

        $this->em->remove($template);
        $this->em->flush();

        return $this->json(null, 204);
    }
}
