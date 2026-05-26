<?php

namespace App\Controller;

use App\Repository\ProjectTemplateRepository;
use App\Service\ProjectTemplateBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/project-templates', name: 'api_project_template_')]
class ProjectTemplateController extends AbstractController
{
    public function __construct(
        private ProjectTemplateRepository $templateRepository,
        private ProjectTemplateBuilder $builder,
        private EntityManagerInterface $em,
    ) {}

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

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => 'Template not found'], 404);
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
        $data     = $request->toArray();
        $template = $this->builder->exportFromProject($projectUuid, $data['name'] ?? null, $data['description'] ?? null);

        if ($template === null) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        return $this->json([
            'id'        => $template->id,
            'name'      => $template->name,
            'createdAt' => $template->createdAt->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/{id}/apply', name: 'apply', methods: ['POST'])]
    public function apply(int $id, Request $request): JsonResponse
    {
        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => 'Template not found'], 404);
        }

        $data    = $request->toArray();
        $project = $this->builder->applyTemplate($template, $data['name'] ?? $template->name, $this->getUser());

        return $this->json([
            'uuid' => $project->uuid?->toString(),
            'name' => $project->name,
        ], 201);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return $this->json(['error' => 'Template not found'], 404);
        }

        $this->em->remove($template);
        $this->em->flush();

        return $this->json(null, 204);
    }
}
