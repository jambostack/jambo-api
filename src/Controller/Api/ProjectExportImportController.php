<?php

namespace App\Controller\Api;

use App\Dto\ExportOptions;
use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectRepository;
use App\Service\EndUserSchemaSeeder;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[OA\Tag(name: 'Export / Import')]
#[Route('/api/projects', name: 'api_project_export_import_')]
class ProjectExportImportController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none';",
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectExporter $exporter,
        private ProjectImporter $importer,
        private EntityManagerInterface $em,
        private EndUserSchemaSeeder $endUserSchemaSeeder,
        private ?RateLimiterFactory $exportLimiter = null,
        private ?RateLimiterFactory $importLimiter = null,
        private ?TranslatorInterface $translator = null,
        private ?\App\Service\AuditService $auditService = null,
    ) {}

    public const MAX_ARCHIVE_BYTES = 52428800; // 50MB

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    private function validateUploadedArchive(?\Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile): ?JsonResponse
    {
        if (!$uploadedFile) {
            return $this->json(['error' => $this->trans('project.export_import.no_file', [], 'No file uploaded')], 422, self::SECURITY_HEADERS);
        }
        if (!$uploadedFile->isValid()) {
            return $this->json(['error' => $this->trans('project.export_import.upload_error', ['%error%' => $uploadedFile->getErrorMessage()], 'File upload error: ' . $uploadedFile->getErrorMessage())], 422, self::SECURITY_HEADERS);
        }
        if ($uploadedFile->getSize() > self::MAX_ARCHIVE_BYTES) {
            return $this->json(['error' => $this->trans('project.export_import.archive_too_large', [], 'Uploaded archive exceeds the maximum allowed size of 50MB.')], 422, self::SECURITY_HEADERS);
        }
        $ext = strtolower($uploadedFile->getClientOriginalExtension());
        if ($ext !== 'zip') {
            return $this->json(['error' => $this->trans('project.export_import.invalid_format', [], 'Invalid file format. Only ZIP archives are permitted.')], 422, self::SECURITY_HEADERS);
        }
        $mime = $uploadedFile->getClientMimeType();
        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/octet-stream'];
        if (!in_array($mime, $allowedMimes, true)) {
            return $this->json(['error' => $this->trans('project.export_import.invalid_format', [], 'Invalid file format. Only ZIP archives are permitted.')], 422, self::SECURITY_HEADERS);
        }
        // Verification of ZIP magic bytes (PK\x03\x04 or PK\x05\x06)
        $handle = @fopen($uploadedFile->getPathname(), 'rb');
        if ($handle) {
            $magic = fread($handle, 4);
            fclose($handle);
            if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06") {
                return $this->json(['error' => $this->trans('project.export_import.invalid_format', [], 'Invalid file format. Only ZIP archives are permitted.')], 422, self::SECURITY_HEADERS);
            }
        }
        return null;
    }

    #[OA\Get(
        path: '/api/projects/{uuid}/export',
        summary: 'Export a project as a ZIP archive',
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'structure', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'), description: 'Include collection structure'),
            new OA\Parameter(name: 'content', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'), description: 'Include content entries'),
            new OA\Parameter(name: 'media', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'), description: 'Include media files'),
            new OA\Parameter(name: 'end_users', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'), description: 'Include end users'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ZIP file download'),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    private function resolveIdentifier(Request $request): string
    {
        try {
            $user = $this->getUser();
            if ($user !== null) {
                return $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        return $request->getClientIp() ?? 'anonymous';
    }

    #[Route('/{uuid}/export', name: 'export', methods: ['GET'])]
    public function export(string $uuid, Request $request): Response
    {
        $identifier = $this->resolveIdentifier($request);
        if ($this->exportLimiter !== null && false === $this->exportLimiter->create($identifier)->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => $this->trans('project.export_import.rate_limit_export', [], 'Too many export requests. Please retry later.')], 429);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('project.export_import.not_found', [], 'Project not found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('project.export_import.not_found', [], 'Project not found')], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $options = ExportOptions::fromRequest($request->query->all());
        if (empty($options->getEnabledOptions())) {
            $options->structure = true;
        }

        $zipPath = $this->exporter->streamExport($project, $options);

        $filename = sprintf('export-%s-%s.zip',
            preg_replace('/[^a-zA-Z0-9_-]/', '-', $project->name),
            (new \DateTimeImmutable())->format('Y-m-d-His'),
        );

        $this->auditService?->log(
            toolName: 'project_export',
            project: $project,
            input: ['uuid' => $uuid, 'options' => $options->getEnabledOptions()],
            output: ['filename' => $filename],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
            source: 'export_api',
        );
        $this->auditService?->flush();

        $response = new BinaryFileResponse($zipPath);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->deleteFileAfterSend();

        return $response;
    }

    #[Route('/{uuid}/export/preview', name: 'export_preview', methods: ['GET'])]
    public function exportPreview(string $uuid, Request $request): JsonResponse
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('project.export_import.not_found', [], 'Project not found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('project.export_import.not_found', [], 'Project not found')], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $collectionCount = 0;
        $entryCount = 0;
        foreach ($project->collections as $collection) {
            if (method_exists($collection, 'isDeleted') && $collection->isDeleted()) {
                continue;
            }
            $collectionCount++;
            foreach ($collection->contentEntries as $entry) {
                if (method_exists($entry, 'isDeleted') && $entry->isDeleted()) {
                    continue;
                }
                $entryCount++;
            }
        }

        return $this->json([
            'data' => [
                'collections' => $collectionCount,
                'entries'     => $entryCount,
                'media'       => 0, // Would need repository query
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/projects/import',
        summary: 'Import a ZIP archive as a new project',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'new_project_name', type: 'string'),
                new OA\Property(property: 'structure', type: 'boolean'),
                new OA\Property(property: 'content', type: 'boolean'),
                new OA\Property(property: 'media', type: 'boolean'),
                new OA\Property(property: 'end_users', type: 'boolean'),
            ])
        )),
        responses: [
            new OA\Response(response: 201, description: 'Project created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 500, description: 'Import failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/import', name: 'import_new', methods: ['POST'])]
    public function importNew(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return $this->json(['error' => $this->trans('project.export_import.auth_required', [], 'Authentication required')], 401);
        }

        $identifier = $this->resolveIdentifier($request);
        if ($this->importLimiter !== null && false === $this->importLimiter->create($identifier)->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => $this->trans('project.export_import.rate_limit_import', [], 'Too many import requests. Please retry later.')], 429);
        }

        $uploadedFile = $request->files->get('file');
        if ($fileError = $this->validateUploadedArchive($uploadedFile)) {
            return $fileError;
        }

        $data = $request->request->all();
        $options = ImportOptions::fromRequest($data);
        $options->createNewProject = true;

        $projectName = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string) ($options->newProjectName ?? '')));
        if ($projectName === '') {
            return $this->json(['error' => $this->trans('project.export_import.new_project_name_required', [], 'new_project_name is required')], 422, self::SECURITY_HEADERS);
        }
        if (strlen($projectName) > 150) {
            return $this->json(['error' => $this->trans('project.export_import.name_too_long', [], 'Project name is too long (max 150 characters).')], 422, self::SECURITY_HEADERS);
        }
        $options->newProjectName = $projectName;

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($uploadedFile->getPathname());
            $manifest = $this->importer->validateManifest($extractedDir);

            $defaultLocale = (string) ($manifest['project']['default_locale'] ?? 'en');
            if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $defaultLocale) || strlen($defaultLocale) > 10) {
                $defaultLocale = 'en';
            }

            $project = new Project();
            $project->name = $options->newProjectName;
            $project->defaultLocale = $defaultLocale;
            $project->locales = [$defaultLocale];

            $this->em->persist($project);

            // Add current user as active member
            $user = $this->getUser();
            if ($user) {
                $member = new ProjectMember();
                $member->project = $project;
                $member->user = $user;
                $member->email = $user->email;
                $member->status = ProjectMemberStatus::Active;
                $member->joinedAt = new \DateTimeImmutable();
                $this->em->persist($member);
            }

            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            try {
                // Flush project first so it has a real ID before any dependent queries
                $this->em->flush();

                $this->importer->import($project, $extractedDir, $options);

                // Persist all created collections, fields, entries and their values
                foreach ($project->collections as $collection) {
                    $this->em->persist($collection);
                    foreach ($collection->fields as $field) {
                        $this->em->persist($field);
                    }
                    foreach ($collection->contentEntries as $entry) {
                        $this->em->persist($entry);
                        foreach ($entry->fieldValues as $value) {
                            $this->em->persist($value);
                        }
                    }
                }

                // Persist imported media
                foreach ($this->importer->getMediaHandler()->getImportedMedia() as $media) {
                    $this->em->persist($media);
                    if ($media->metadata) {
                        $this->em->persist($media->metadata);
                    }
                }

                // Seed system EndUser fields — project now has a real ID so findOneBy works correctly
                $this->endUserSchemaSeeder->seed($project);

                // Persist imported end user fields (custom schema)
                foreach ($this->importer->getStructureHandler()->getImportedEndUserFields() as $endUserField) {
                    $this->em->persist($endUserField);
                }

                // Persist imported end users
                foreach ($this->importer->getEndUserHandler()->getImportedUsers() as $endUser) {
                    $this->em->persist($endUser);
                }

                $this->em->flush();
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            $this->auditService?->log(
                toolName: 'project_import_new',
                project: $project,
                input: ['project_name' => $project->name, 'filename' => $uploadedFile->getClientOriginalName()],
                output: ['id' => $project->id, 'uuid' => $project->uuid?->toString()],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
                source: 'export_api',
            );
            $this->auditService?->flush();

            return $this->json([
                'data' => [
                    'id'   => $project->id,
                    'uuid' => $project->uuid?->toString(),
                    'name' => $project->name,
                ],
            ], 201, self::SECURITY_HEADERS);
        } catch (\Throwable $e) {
            return $this->json(['error' => $this->trans('project.export_import.failed', ['%error%' => $e->getMessage()], 'Import failed: ' . $e->getMessage())], 500, self::SECURITY_HEADERS);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }

    #[Route('/import/preview', name: 'import_preview', methods: ['POST'])]
    public function importPreview(Request $request): JsonResponse
    {
        if (!$this->getUser()) {
            return $this->json(['error' => $this->trans('project.export_import.auth_required', [], 'Authentication required')], 401);
        }

        $identifier = $this->resolveIdentifier($request);
        if ($this->importLimiter !== null && false === $this->importLimiter->create($identifier)->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => $this->trans('project.export_import.rate_limit_import', [], 'Too many import requests. Please retry later.')], 429);
        }

        $uploadedFile = $request->files->get('file');
        if ($fileError = $this->validateUploadedArchive($uploadedFile)) {
            return $fileError;
        }

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($uploadedFile->getPathname());
            $manifest = $this->importer->validateManifest($extractedDir);

            $tempProject = new Project();
            $tempProject->name = $manifest['project']['name'] ?? 'Preview';
            $tempProject->defaultLocale = $manifest['project']['default_locale'] ?? 'en';
            $tempProject->locales = [$tempProject->defaultLocale];

            $conflicts = $this->importer->previewConflicts($tempProject, $extractedDir);

            return $this->json([
                'data' => [
                    'manifest'  => $manifest,
                    'conflicts' => array_map(fn ($c) => $c->toArray(), $conflicts),
                ],
            ], 200, self::SECURITY_HEADERS);
        } catch (\Throwable $e) {
            return $this->json(['error' => $this->trans('project.export_import.preview_failed', ['%error%' => $e->getMessage()], 'Preview failed: ' . $e->getMessage())], 500, self::SECURITY_HEADERS);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }

    #[OA\Post(
        path: '/api/projects/{uuid}/import/merge',
        summary: 'Merge a ZIP archive into an existing project',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'structure', type: 'boolean'),
                new OA\Property(property: 'content', type: 'boolean'),
                new OA\Property(property: 'media', type: 'boolean'),
                new OA\Property(property: 'end_users', type: 'boolean'),
            ])
        )),
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Merge successful', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 500, description: 'Import failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{uuid}/import/merge', name: 'import_merge', methods: ['POST'])]
    public function importMerge(string $uuid, Request $request): JsonResponse
    {
        $identifier = $this->resolveIdentifier($request);
        if ($this->importLimiter !== null && false === $this->importLimiter->create($identifier)->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => $this->trans('project.export_import.rate_limit_import', [], 'Too many import requests. Please retry later.')], 429);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('project.export_import.not_found', [], 'Project not found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('project.export_import.not_found', [], 'Project not found')], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.manage', $project);
        }

        $uploadedFile = $request->files->get('file');
        if ($fileError = $this->validateUploadedArchive($uploadedFile)) {
            return $fileError;
        }

        $data = $request->request->all();
        $options = ImportOptions::fromRequest($data);
        $options->createNewProject = false;

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($uploadedFile->getPathname());
            $this->importer->validateManifest($extractedDir);

            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            try {
                $this->importer->import($project, $extractedDir, $options);

                // Persist all created collections, fields, entries and their values
                foreach ($project->collections as $collection) {
                    $this->em->persist($collection);
                    foreach ($collection->fields as $field) {
                        $this->em->persist($field);
                    }
                    foreach ($collection->contentEntries as $entry) {
                        $this->em->persist($entry);
                        foreach ($entry->fieldValues as $value) {
                            $this->em->persist($value);
                        }
                    }
                }
                foreach ($this->importer->getMediaHandler()->getImportedMedia() as $media) {
                    $this->em->persist($media);
                    if ($media->metadata) {
                        $this->em->persist($media->metadata);
                    }
                }
                // Safety net: ensure system EndUser fields exist (idempotent, skips if already present)
                $this->endUserSchemaSeeder->seed($project);

                foreach ($this->importer->getStructureHandler()->getImportedEndUserFields() as $endUserField) {
                    $this->em->persist($endUserField);
                }
                foreach ($this->importer->getEndUserHandler()->getImportedUsers() as $endUser) {
                    $this->em->persist($endUser);
                }

                $this->em->flush();
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            $this->auditService?->log(
                toolName: 'project_import_merge',
                project: $project,
                input: ['uuid' => $uuid, 'filename' => $uploadedFile->getClientOriginalName()],
                output: ['id' => $project->id, 'uuid' => $project->uuid?->toString()],
                status: 'success',
                createdBy: $this->getUser()?->getUserIdentifier() ?? 'system',
                source: 'export_api',
            );
            $this->auditService?->flush();

            return $this->json(['data' => ['id' => $project->id, 'uuid' => $project->uuid?->toString()]], 200, self::SECURITY_HEADERS);
        } catch (\Throwable $e) {
            return $this->json(['error' => $this->trans('project.export_import.failed', ['%error%' => $e->getMessage()], 'Import failed: ' . $e->getMessage())], 500, self::SECURITY_HEADERS);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }
}
