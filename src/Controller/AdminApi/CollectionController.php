<?php

namespace App\Controller\AdminApi;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\SchemaProvisioner;
use App\Service\Security\CsvSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin-api/projects/{uuid}/collections', name: 'admin_api_collection_')]
class CollectionController extends AbstractController
{
    use AdminApiControllerTrait;

    public const MAX_PAYLOAD_BYTES = 524288; // 512KB
    public const MAX_EXPORT_ROWS = 50000;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private SchemaProvisioner $provisioner,
        private ?ContentEntryRepository $entries = null,
        private ?CsvSanitizer $csvSanitizer = null,
        private ?EntityManagerInterface $em = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator?->trans($id, $parameters) ?? $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $uuid): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.collection.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        return $this->json(['data' => array_map(
            fn (Collection $c) => $this->serialize($c),
            $this->collections->findByProject($project),
        )]);
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $uuid, string $slug): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.collection.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $c = $this->collections->findOneByProjectAndSlug($project, $slug);
        if (!$c) {
            return $this->json(['error' => $this->trans('admin_api.collection.not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404);
        }
        return $this->json(['data' => $this->serialize($c, withFields: true)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.collection.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->trans('admin_api.collection.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.collection.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => $this->trans('admin_api.collection.name_required'), 'code' => 'NAME_REQUIRED'], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->json(['error' => $this->trans('admin_api.collection.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
        }

        if (isset($body['slug'])) {
            $slug = trim((string) $body['slug']);
            if (mb_strlen($slug) > 255) {
                return $this->json(['error' => $this->trans('admin_api.collection.slug_too_long'), 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($slug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
                return $this->json(['error' => $this->trans('admin_api.collection.invalid_slug_format'), 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
        }

        try {
            $c = $this->provisioner->createCollection($project, $body);
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }
        return $this->json(['data' => ['name' => $c->name, 'slug' => $c->slug, 'isSingleton' => $c->isSingleton]], 201);
    }

    #[Route('/{slug}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.collection.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $project);
        $c = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$c) {
            return $this->json(['error' => $this->trans('admin_api.collection.not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->trans('admin_api.collection.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.collection.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->json(['error' => $this->trans('admin_api.collection.name_required'), 'code' => 'NAME_REQUIRED'], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->json(['error' => $this->trans('admin_api.collection.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
            }
        }

        if (isset($body['slug'])) {
            $newSlug = trim((string) $body['slug']);
            if (mb_strlen($newSlug) > 255) {
                return $this->json(['error' => $this->trans('admin_api.collection.slug_too_long'), 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($newSlug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $newSlug)) {
                return $this->json(['error' => $this->trans('admin_api.collection.invalid_slug_format'), 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
        }

        $this->provisioner->updateCollection($c, $body);
        return $this->json(['data' => ['name' => $c->name, 'slug' => $c->slug]]);
    }

    #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.collection.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $project);
        $c = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$c) {
            return $this->json(['error' => $this->trans('admin_api.collection.not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404);
        }
        $this->provisioner->deleteCollection($c);
        return new JsonResponse(null, 204);
    }

    #[Route('/{slug}/export-csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(string $uuid, string $slug): Response
    {
        $project = $this->resolveProject($uuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.collection.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $collection = $this->collections->findOneByProjectAndSlug($project, $slug);
        if (!$collection) {
            return $this->json(['error' => $this->trans('admin_api.collection.not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404);
        }

        $fields = $this->fields->findBy(['collection' => $collection, 'deletedAt' => null]);
        $fieldSlugs = array_map(fn ($f) => $f->slug, $fields);

        $sanitizer = $this->csvSanitizer ?? new CsvSanitizer();
        $safeSlug = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $collection->slug ?: 'collection');
        $filename = sprintf('collection_%s_%s.csv', $safeSlug, date('Ymd_His'));

        $response = new StreamedResponse(function () use ($collection, $fieldSlugs, $sanitizer) {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            // BOM UTF-8 pour Excel
            fwrite($output, CsvSanitizer::BOM_UTF8);

            // En-tête assaini
            $header = ['uuid', 'slug', 'status', 'locale', ...$fieldSlugs];
            fputcsv($output, $sanitizer->sanitizeRow($header), ',', '"', "\\");
            flush();

            if ($this->entries !== null) {
                $batchSize = 100;
                $offset = 0;
                $totalExported = 0;

                while ($totalExported < self::MAX_EXPORT_ROWS) {
                    $limit = min($batchSize, self::MAX_EXPORT_ROWS - $totalExported);
                    $entries = $this->entries->findBy(
                        ['collection' => $collection, 'deletedAt' => null],
                        ['id' => 'ASC'],
                        $limit,
                        $offset
                    );

                    if (empty($entries)) {
                        break;
                    }

                    foreach ($entries as $e) {
                        $row = [$e->uuid?->toString() ?? '', $e->slug, $e->status, $e->locale];
                        $vals = [];
                        foreach ($e->fieldValues as $fv) {
                            $vals[$fv->field?->slug ?? ''] = $fv->textValue ?? $fv->numberValue ?? $fv->booleanValue ?? '';
                        }
                        foreach ($fieldSlugs as $s) {
                            $row[] = $vals[$s] ?? '';
                        }
                        fputcsv($output, $sanitizer->sanitizeRow($row), ',', '"', "\\");
                        $totalExported++;
                    }

                    flush();

                    if ($this->em !== null) {
                        $this->em->clear();
                        $collection = $this->collections->findOneBy(['id' => $collection->id]);
                        if (!$collection) {
                            break;
                        }
                    }

                    $offset += $batchSize;
                }
            }

            fclose($output);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    private function resolveProject(string $uuid): ?Project
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        try {
            return $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function serialize(Collection $c, bool $withFields = false): array
    {
        $data = [
            'name'        => $c->name,
            'slug'        => $c->slug,
            'description' => $c->description,
            'isSingleton' => $c->isSingleton,
            'order'       => $c->order,
            'settings'    => $c->settings ?? [],
        ];
        if ($withFields) {
            $data['fields'] = array_map(
                fn (Field $f) => [
                    'name'       => $f->name,
                    'slug'       => $f->slug,
                    'type'       => $f->type,
                    'isRequired' => $f->isRequired,
                    'order'      => $f->order,
                    'options'    => $f->options,
                ],
                $this->fields->findByCollection($c),
            );
        }
        return $data;
    }
}
