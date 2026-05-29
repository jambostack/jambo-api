<?php

namespace App\Controller;

use App\Entity\PageTemplate;
use App\Entity\Project;
use App\Repository\PageTemplateRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Persistence des templates de page créés via Jambo Studio Page Builder.
 * Le frontend appelle ces endpoints pour CRUD sur les pages générées.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/api/projects/{uuid}/page-templates', name: 'page_templates_')]
class PageTemplateController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private PageTemplateRepository $repository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $uuid): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $this->denyAccessUnlessGranted('project.view', $project);

        $templates = $this->repository->findByProject($project);

        return $this->json([
            'data' => array_map(fn (PageTemplate $t) => $this->serialize($t), $templates),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $this->denyAccessUnlessGranted('project.manage', $project);

        $data = $request->toArray();

        $name = trim((string) ($data['name'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            return $this->json(['error' => 'name et slug requis'], 422);
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return $this->json(['error' => 'slug invalide (a-z, 0-9, tirets)'], 422);
        }
        if ($this->repository->findOneByProjectAndSlug($project, $slug) !== null) {
            return $this->json(['error' => 'Slug déjà utilisé'], 409);
        }

        $template = new PageTemplate();
        $template->project = $project;
        $template->name = $name;
        $template->slug = $slug;
        $template->sections = $this->normalizeSections($data['sections'] ?? []);
        $template->generatedCode = isset($data['generated_code']) ? (string) $data['generated_code'] : null;
        $template->createdBy = $this->getUser();

        $this->em->persist($template);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($template)], 201);
    }

    #[Route('/{templateUuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid, string $templateUuid): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $this->denyAccessUnlessGranted('project.view', $project);

        $template = $this->repository->findOneBy(['uuid' => $templateUuid, 'project' => $project]);
        if (!$template) {
            return $this->json(['error' => 'Template introuvable'], 404);
        }

        return $this->json(['data' => $this->serialize($template)]);
    }

    #[Route('/{templateUuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $uuid, string $templateUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $this->denyAccessUnlessGranted('project.manage', $project);

        $template = $this->repository->findOneBy(['uuid' => $templateUuid, 'project' => $project]);
        if (!$template) {
            return $this->json(['error' => 'Template introuvable'], 404);
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') return $this->json(['error' => 'name vide'], 422);
            $template->name = $name;
        }

        if (isset($data['slug'])) {
            $slug = trim((string) $data['slug']);
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                return $this->json(['error' => 'slug invalide'], 422);
            }
            $other = $this->repository->findOneByProjectAndSlug($project, $slug);
            if ($other !== null && $other->id !== $template->id) {
                return $this->json(['error' => 'Slug déjà utilisé'], 409);
            }
            $template->slug = $slug;
        }

        if (array_key_exists('sections', $data)) {
            $template->sections = $this->normalizeSections($data['sections']);
        }

        if (array_key_exists('generated_code', $data)) {
            $template->generatedCode = $data['generated_code'] === null ? null : (string) $data['generated_code'];
        }

        $template->touch();
        $this->em->flush();

        return $this->json(['data' => $this->serialize($template)]);
    }

    #[Route('/{templateUuid}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(string $uuid, string $templateUuid): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) return $project;

        $this->denyAccessUnlessGranted('project.manage', $project);

        $template = $this->repository->findOneBy(['uuid' => $templateUuid, 'project' => $project]);
        if (!$template) {
            return $this->json(['error' => 'Template introuvable'], 404);
        }

        $this->em->remove($template);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    private function resolveProject(string $uuid): Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }
        return $project;
    }

    private function serialize(PageTemplate $t): array
    {
        return [
            'uuid'           => $t->uuid?->toRfc4122(),
            'name'           => $t->name,
            'slug'           => $t->slug,
            'sections'       => $t->sections,
            'generated_code' => $t->generatedCode,
            'created_at'     => $t->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'     => $t->updatedAt->format(\DateTimeInterface::ATOM),
            'created_by'     => $t->createdBy?->getUserIdentifier(),
        ];
    }

    /**
     * Normalize sections: keep only known keys, enforce order, drop empties.
     */
    private function normalizeSections(mixed $sections): array
    {
        if (!is_array($sections)) return [];

        $allowedTypes = ['hero', 'list', 'detail', 'form', 'grid', 'custom'];
        $out = [];
        $order = 0;
        foreach ($sections as $s) {
            if (!is_array($s)) continue;
            $type = $s['type'] ?? null;
            if (!in_array($type, $allowedTypes, true)) continue;
            $out[] = [
                'key'        => isset($s['key']) && is_string($s['key']) ? $s['key'] : uniqid('sec_', true),
                'type'       => $type,
                'title'      => isset($s['title']) ? (string) $s['title'] : '',
                'collection' => isset($s['collection']) ? (string) $s['collection'] : null,
                'fields'     => isset($s['fields']) && is_array($s['fields']) ? array_values(array_filter($s['fields'], 'is_string')) : [],
                'customCode' => isset($s['customCode']) ? (string) $s['customCode'] : null,
                'order'      => $order++,
            ];
        }
        return $out;
    }
}
