<?php

namespace App\Controller\AdminApi;

use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\SchemaProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin-api/projects/{uuid}/collections/{slug}/fields', name: 'admin_api_field_')]
class FieldController extends AbstractController
{
    use AdminApiControllerTrait;

    public const MAX_PAYLOAD_BYTES = 524288; // 512KB

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private SchemaProvisioner $provisioner,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator?->trans($id, $parameters) ?? $id;
    }

    /** @return array{0: ?\App\Entity\Collection, 1: ?JsonResponse} */
    private function resolveCollection(string $uuid, string $slug): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return [null, $this->json(['error' => $this->trans('admin_api.field.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404)];
        }
        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return [null, $this->json(['error' => $this->trans('admin_api.field.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404)];
        }
        if (!$project) {
            return [null, $this->json(['error' => $this->trans('admin_api.field.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404)];
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $c = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$c) {
            return [null, $this->json(['error' => $this->trans('admin_api.field.collection_not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404)];
        }
        return [$c, null];
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $uuid, string $slug): JsonResponse
    {
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        return $this->json(['data' => array_map(
            fn (\App\Entity\Field $f) => [
                'name'       => $f->name,
                'slug'       => $f->slug,
                'type'       => $f->type,
                'isRequired' => $f->isRequired,
                'order'      => $f->order,
                'options'    => $f->options,
            ],
            $this->fields->findByCollection($c),
        )]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $c->project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->trans('admin_api.field.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.field.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => $this->trans('admin_api.field.name_required'), 'code' => 'NAME_REQUIRED'], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->json(['error' => $this->trans('admin_api.field.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
        }

        try {
            $f = $this->provisioner->addField($c, $body);
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }
        return $this->json(['data' => ['name' => $f->name, 'slug' => $f->slug, 'type' => $f->type, 'isRequired' => $f->isRequired]], 201);
    }

    #[Route('/{fieldSlug}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, string $slug, string $fieldSlug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $c->project);
        $f = $this->fields->findOneBy(['collection' => $c, 'slug' => $fieldSlug]);
        if (!$f) {
            return $this->json(['error' => $this->trans('admin_api.field.not_found'), 'code' => 'FIELD_NOT_FOUND'], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->json(['error' => $this->trans('admin_api.field.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.field.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->json(['error' => $this->trans('admin_api.field.name_required'), 'code' => 'NAME_REQUIRED'], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->json(['error' => $this->trans('admin_api.field.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
            }
        }

        try {
            $this->provisioner->updateField($f, $body);
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }
        return $this->json(['data' => ['slug' => $f->slug, 'type' => $f->type]]);
    }

    #[Route('/{fieldSlug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, string $slug, string $fieldSlug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $c->project);
        $f = $this->fields->findOneBy(['collection' => $c, 'slug' => $fieldSlug]);
        if (!$f) {
            return $this->json(['error' => $this->trans('admin_api.field.not_found'), 'code' => 'FIELD_NOT_FOUND'], 404);
        }
        $this->provisioner->deleteField($f);
        return new JsonResponse(null, 204);
    }
}
