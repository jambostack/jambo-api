<?php

namespace App\Controller\Api;

use App\Dto\ExportOptions;
use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectRepository;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects', name: 'api_project_export_import_')]
class ProjectExportImportController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectExporter $exporter,
        private ProjectImporter $importer,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/{uuid}/export', name: 'export', methods: ['GET'])]
    public function export(string $uuid, Request $request): Response
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
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

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->deleteFileAfterSend();

        return $response;
    }

    #[Route('/{uuid}/export/preview', name: 'export_preview', methods: ['GET'])]
    public function exportPreview(string $uuid): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
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

    #[Route('/import', name: 'import_new', methods: ['POST'])]
    public function importNew(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], 422);
        }

        $data = $request->request->all();
        $options = ImportOptions::fromRequest($data);
        $options->createNewProject = true;

        if (empty($options->newProjectName)) {
            return $this->json(['error' => 'new_project_name is required'], 422);
        }

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($uploadedFile->getPathname());
            $manifest = $this->importer->validateManifest($extractedDir);

            $project = new Project();
            $project->name = $options->newProjectName;
            $project->defaultLocale = $manifest['project']['default_locale'] ?? 'en';
            $project->locales = [$project->defaultLocale];

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

            $this->em->flush();

            return $this->json([
                'data' => [
                    'id'   => $project->id,
                    'uuid' => $project->uuid?->toString(),
                    'name' => $project->name,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Import failed: ' . $e->getMessage()], 500);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }

    #[Route('/import/preview', name: 'import_preview', methods: ['POST'])]
    public function importPreview(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], 422);
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
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Preview failed: ' . $e->getMessage()], 500);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }

    #[Route('/{uuid}/import/merge', name: 'import_merge', methods: ['POST'])]
    public function importMerge(string $uuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], 422);
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

                $this->em->flush();
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            return $this->json(['data' => ['id' => $project->id, 'uuid' => $project->uuid?->toString()]]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Import failed: ' . $e->getMessage()], 500);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }
}
