<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class StudioController extends InertiaController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
    ) {}

    /**
     * Render the Jambo Studio page via Inertia.
     */
    #[Route('/projects/{project}/settings/studio', name: 'studio_page', requirements: ['project' => '\d+'], priority: 10)]
    public function index(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('project.view', $project);

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null], ['order' => 'ASC']);

        $collectionsData = array_map(function (Collection $c) {
            $fields = [];
            foreach ($c->fields as $f) {
                if ($f->isDeleted()) continue;
                $fields[] = [
                    'name'       => $f->name,
                    'slug'       => $f->slug,
                    'type'       => $f->type,
                    'isRequired' => $f->isRequired,
                    'options'    => $f->options,
                    'order'      => $f->order,
                ];
            }

            return [
                'id'          => $c->id,
                'uuid'        => $c->uuid?->toRfc4122(),
                'name'        => $c->name,
                'slug'        => $c->slug,
                'description' => $c->description,
                'isSingleton' => $c->isSingleton,
                'fields'      => $fields,
            ];
        }, $collections);

        return $this->inertia($request, 'Projects/Settings/Studio/layout', [
            'project'     => [
                'id'   => $project->id,
                'uuid' => $project->uuid->toRfc4122(),
                'name' => $project->name,
            ],
            'collections' => $collectionsData,
            'userCan'     => [],
        ]);
    }

    /**
     * Apply schema from the visual builder.
     */
    #[Route('/api/projects/{uuid}/studio/schema', name: 'studio_schema_apply', methods: ['POST'])]
    public function applySchema(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $this->denyAccessUnlessGranted('project.manage', $project);

        $data = json_decode($request->getContent(), true);
        $collections = $data['collections'] ?? [];

        $created = 0;
        $updated = 0;

        foreach ($collections as $colData) {
            if (empty($colData['name'])) continue;

            $slug = $colData['slug'] ?: $this->slugify($colData['name']);
            $collection = $this->em->getRepository(Collection::class)
                ->findOneBy(['project' => $project, 'slug' => $slug, 'deletedAt' => null]);

            if (!$collection) {
                $collection = new Collection();
                $collection->project = $project;
                $collection->order = count($project->collections->toArray());
                $created++;
            } else {
                $updated++;
            }

            $collection->name = $colData['name'];
            $collection->slug = $slug;
            $collection->description = $colData['description'] ?? null;
            $collection->isSingleton = $colData['isSingleton'] ?? false;

            $this->em->persist($collection);

            $existingSlugs = [];
            foreach ($collection->fields as $f) {
                if (!$f->isDeleted()) $existingSlugs[$f->slug] = $f;
            }

            $order = 0;
            foreach ($colData['fields'] ?? [] as $fieldData) {
                if (empty($fieldData['name'])) continue;

                $fSlug = $fieldData['slug'] ?: $this->slugify($fieldData['name']);

                if (isset($existingSlugs[$fSlug])) {
                    $field = $existingSlugs[$fSlug];
                    unset($existingSlugs[$fSlug]);
                } else {
                    $field = new Field();
                    $field->collection = $collection;
                }

                $field->name = $fieldData['name'];
                $field->slug = $fSlug;
                $field->type = $fieldData['type'] ?? 'text';
                $field->isRequired = $fieldData['isRequired'] ?? false;
                $field->options = $fieldData['options'] ?? null;
                $field->order = $order++;

                $this->em->persist($field);
            }

            // Soft-delete removed fields
            foreach ($existingSlugs as $oldField) {
                $oldField->deletedAt = new \DateTimeImmutable();
                $this->em->persist($oldField);
            }
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    /**
     * Generate TypeScript types from the project schema.
     */
    #[Route('/api/projects/{uuid}/studio/generate-types', name: 'studio_generate_types', methods: ['GET'])]
    public function generateTypes(string $uuid): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Projet introuvable'], 404);
        }

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null]);

        $types = [];
        foreach ($collections as $collection) {
            $fields = [];
            foreach ($collection->fields as $f) {
                if ($f->isDeleted()) continue;
                $optional = $f->isRequired ? '' : '?';
                $tsType = $this->mapPhpTypeToTs($f->type);
                $fields[] = "  {$f->slug}{$optional}: {$tsType};";
            }

            $types[] = [
                'name' => ucfirst($collection->slug),
                'code' => "export interface " . ucfirst($collection->slug) . " {\n  uuid: string;\n  locale: string;\n  status: 'draft' | 'published';\n" . implode("\n", $fields) . "\n  created_at: string;\n  updated_at: string;\n}",
            ];
        }

        return $this->json(['types' => $types]);
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);

        return trim($text, '_');
    }

    private function mapPhpTypeToTs(string $type): string
    {
        return match ($type) {
            'number', 'decimal' => 'number',
            'boolean', 'checkbox' => 'boolean',
            'json', 'array', 'repeater' => 'Record<string, any>',
            'media', 'relation' => 'string | string[]',
            default => 'string',
        };
    }
}
