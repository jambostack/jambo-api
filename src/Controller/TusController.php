<?php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Exception\TusChunkOutOfBoundsException;
use App\Exception\TusConflictException;
use App\Exception\TusOffsetMismatchException;
use App\Repository\MediaFolderRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\MediaSerializer;
use App\Service\MercurePublisher;
use App\Service\StorageDriverFactory;
use App\Service\StorageManager;
use App\Service\TusServer;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/files/tus', name: 'api_tus_')]
class TusController extends AbstractController
{
    private const MAX_TUS_SIZE = 100 * 1024 * 1024; // 100 MB

    public function __construct(
        private readonly TusServer $tusServer,
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        private readonly MediaSerializer $mediaSerializer,
        private readonly MediaFolderRepository $folderRepository,
        private readonly ProjectStorageProfileRepository $profileRepo,
        private readonly StorageRuleRepository $ruleRepo,
        private readonly StorageDriverFactory $driverFactory,
        private readonly ProjectMemberRepository $memberRepo,
        private readonly MercurePublisher $mercure,
        private readonly UploadSecurityValidator $uploadValidator,
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

    /**
     * OPTIONS — Découverte de la configuration TUS du serveur (protocole TUS 1.0.0).
     */
    #[Route('', name: 'options', methods: ['OPTIONS'])]
    #[Route('/{uploadId}', name: 'options_upload', methods: ['OPTIONS'])]
    public function options(): Response
    {
        return new Response('', 204, [
            'Tus-Resumable' => '1.0.0',
            'Tus-Version'   => '1.0.0',
            'Tus-Extension' => 'creation,termination',
            'Tus-Max-Size'  => (string) self::MAX_TUS_SIZE,
        ]);
    }

    /**
     * POST — Crée un upload TUS.
     *
     * Headers attendus :
     *   Tus-Resumable: 1.0.0
     *   Upload-Length: <taille_en_octets>
     *   Upload-Metadata: filename <base64>,filetype <base64>[,folder_id <base64>]
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): Response
    {
        $project = $this->findProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('tus.project_not_found', [], 'Project not found')], 404, ['Tus-Resumable' => '1.0.0']);
        }
        $this->denyProjectAccess($project, $request);

        // Vérifier le header obligatoire TUS 1.0.0
        if ($request->headers->get('Tus-Resumable') !== '1.0.0') {
            return new Response('', 412, ['Tus-Version' => '1.0.0']);
        }

        if (!$request->headers->has('Upload-Length')) {
            return $this->json(['error' => $this->trans('tus.missing_upload_length', [], 'Missing Upload-Length header')], 400, ['Tus-Resumable' => '1.0.0']);
        }

        $rawLength = (string) $request->headers->get('Upload-Length', '0');
        if (!is_numeric($rawLength) || (int) $rawLength <= 0) {
            return $this->json(['error' => $this->trans('tus.invalid_upload_length', [], 'Invalid or missing Upload-Length')], 400, ['Tus-Resumable' => '1.0.0']);
        }

        $uploadLength = (int) $rawLength;
        if ($uploadLength > self::MAX_TUS_SIZE) {
            return $this->json(['error' => $this->trans('tus.upload_length_exceeds_max', ['%max%' => '100MB'], 'Upload-Length exceeds maximum allowed size (100MB)')], 413, ['Tus-Resumable' => '1.0.0']);
        }

        // Parser Upload-Metadata : format "filename BASE64,filetype BASE64[,folder_id BASE64]"
        $metadata = $this->parseMetadata((string) $request->headers->get('Upload-Metadata', ''));

        try {
            if (isset($metadata['filename'])) {
                $this->uploadValidator->sanitizeAndValidateFilename($metadata['filename']);
            }
            // Contrôle atomique du quota incluant les uploads TUS actifs en cours pour le projet
            $pendingBytes = $this->tusServer->getPendingUploadsSize($projectUuid);
            $this->uploadValidator->validateSizeAndQuota($project, $uploadLength + $pendingBytes);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400, ['Tus-Resumable' => '1.0.0']);
        }

        $uploadId = $this->tusServer->create($projectUuid, $uploadLength, $metadata);

        $location = $this->generateUrl('api_tus_process', [
            'projectUuid' => $projectUuid,
            'uploadId'    => $uploadId,
        ]);

        return new Response('', 201, [
            'Location'      => $location,
            'Tus-Resumable' => '1.0.0',
        ]);
    }

    /**
     * PATCH — Reçoit un chunk et l'écrit à l'offset spécifié.
     *
     * La réponse inclut Upload-Offset avec le nouvel offset.
     */
    #[Route('/{uploadId}', name: 'process', methods: ['PATCH'])]
    public function patch(string $projectUuid, string $uploadId, Request $request): Response
    {
        if ($request->headers->get('Tus-Resumable') !== '1.0.0') {
            return new Response('', 412, ['Tus-Version' => '1.0.0']);
        }

        // Protocole TUS 1.0.0 : Content-Type strict
        $contentType = (string) $request->headers->get('Content-Type', '');
        if (!str_starts_with(strtolower($contentType), 'application/offset+octet-stream')) {
            return $this->json(['error' => $this->trans('tus.invalid_content_type', [], 'Content-Type must be application/offset+octet-stream')], 415, ['Tus-Resumable' => '1.0.0']);
        }

        $project = $this->findProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('tus.project_not_found', [], 'Project not found')], 404, ['Tus-Resumable' => '1.0.0']);
        }
        $this->denyProjectAccess($project, $request);

        if (!$this->isValidUploadId($uploadId) || !$this->tusServer->exists($projectUuid, $uploadId)) {
            return $this->json(['error' => $this->trans('tus.upload_not_found', [], 'Upload not found')], 404, ['Tus-Resumable' => '1.0.0']);
        }

        if (!$request->headers->has('Upload-Offset')) {
            return $this->json(['error' => $this->trans('tus.missing_upload_offset', [], 'Missing Upload-Offset header')], 400, ['Tus-Resumable' => '1.0.0']);
        }

        $rawOffset = (string) $request->headers->get('Upload-Offset', '0');
        if (!is_numeric($rawOffset) || (int) $rawOffset < 0) {
            return $this->json(['error' => $this->trans('tus.invalid_upload_offset', [], 'Invalid Upload-Offset header')], 400, ['Tus-Resumable' => '1.0.0']);
        }
        $offset = (int) $rawOffset;

        // Lire le corps brut comme flux
        $stream = $request->getContent(true); // resource

        try {
            $newOffset = $this->tusServer->patch($projectUuid, $uploadId, $offset, $stream);
        } catch (TusOffsetMismatchException $e) {
            return new Response('', 409, [
                'Upload-Offset' => (string) $e->getCurrentOffset(),
                'Tus-Resumable' => '1.0.0',
            ]);
        } catch (TusChunkOutOfBoundsException $e) {
            return $this->json(['error' => $e->getMessage()], 413, ['Tus-Resumable' => '1.0.0']);
        } catch (TusConflictException $e) {
            return $this->json(['error' => $e->getMessage()], 409, ['Tus-Resumable' => '1.0.0']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 404, ['Tus-Resumable' => '1.0.0']);
        }

        return new Response('', 204, [
            'Upload-Offset' => (string) $newOffset,
            'Tus-Resumable' => '1.0.0',
        ]);
    }

    /**
     * HEAD — Retourne l'offset actuel (pour reprise d'upload).
     */
    #[Route('/{uploadId}', name: 'head', methods: ['HEAD'])]
    public function head(string $projectUuid, string $uploadId, Request $request): Response
    {
        $project = $this->findProject($projectUuid);
        if (!$project) {
            return new Response('', 404, ['Tus-Resumable' => '1.0.0']);
        }
        $this->denyProjectAccess($project, $request, 'project.view');

        if (!$this->isValidUploadId($uploadId) || !$this->tusServer->exists($projectUuid, $uploadId)) {
            return new Response('', 404, ['Tus-Resumable' => '1.0.0']);
        }

        $info = $this->tusServer->getInfo($projectUuid, $uploadId);
        $offset = $info['offset'] ?? 0;
        $size   = $info['size'] ?? 0;

        return new Response('', 200, [
            'Upload-Offset'       => (string) $offset,
            'Upload-Length'       => (string) $size,
            'Tus-Resumable'       => '1.0.0',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * DELETE — Annule un upload en cours.
     */
    #[Route('/{uploadId}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $uploadId, Request $request): Response
    {
        $project = $this->findProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('tus.project_not_found', [], 'Project not found')], 404, ['Tus-Resumable' => '1.0.0']);
        }
        $this->denyProjectAccess($project, $request);

        if (!$this->isValidUploadId($uploadId) || !$this->tusServer->exists($projectUuid, $uploadId)) {
            return $this->json(['error' => $this->trans('tus.upload_not_found', [], 'Upload not found')], 404, ['Tus-Resumable' => '1.0.0']);
        }
        $this->tusServer->cancel($projectUuid, $uploadId);
        return new Response('', 204, ['Tus-Resumable' => '1.0.0']);
    }

    /**
     * POST /finalize — Appelé par le client après le dernier chunk pour
     * créer l'entité Media et synchroniser les stockages.
     */
    #[Route('/{uploadId}/finalize', name: 'finalize', methods: ['POST'])]
    public function finalize(string $projectUuid, string $uploadId, Request $request): JsonResponse
    {
        $project = $this->findProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('tus.project_not_found', [], 'Project not found')], 404, ['Tus-Resumable' => '1.0.0']);
        }
        $this->denyProjectAccess($project, $request);

        if (!$this->isValidUploadId($uploadId)) {
            return $this->json(['error' => $this->trans('tus.upload_not_found', [], 'Upload not found')], 404, ['Tus-Resumable' => '1.0.0']);
        }

        $result = $this->tusServer->withLock($projectUuid, $uploadId, function () use ($project, $projectUuid, $uploadId): JsonResponse {
            $info = $this->tusServer->getInfo($projectUuid, $uploadId);
            if ($info === null) {
                return $this->json(['error' => $this->trans('tus.upload_not_found', [], 'Upload not found')], 404, ['Tus-Resumable' => '1.0.0']);
            }

            if ($info['finalized'] ?? false) {
                return $this->json(['error' => $this->trans('tus.upload_already_finalized', [], 'Upload already finalized')], 409, ['Tus-Resumable' => '1.0.0']);
            }

            if ($info['finalizing'] ?? false) {
                return $this->json(['error' => $this->trans('tus.upload_finalizing_in_progress', [], 'Upload is currently being finalized')], 409, ['Tus-Resumable' => '1.0.0']);
            }

            if (!$this->tusServer->isComplete($projectUuid, $uploadId)) {
                return $this->json(['error' => $this->trans('tus.upload_incomplete', [], 'Upload is not complete yet')], 400, ['Tus-Resumable' => '1.0.0']);
            }

            $sourcePath = $this->tusServer->getFilePath($projectUuid, $uploadId);
            if (!file_exists($sourcePath)) {
                return $this->json(['error' => $this->trans('tus.upload_file_missing', [], 'Upload file missing')], 404, ['Tus-Resumable' => '1.0.0']);
            }

            $fileSize = filesize($sourcePath);
            if ($fileSize !== (int) ($info['size'] ?? 0)) {
                return $this->json(['error' => $this->trans('tus.upload_size_mismatch', [], 'Uploaded file size does not match declared size')], 400, ['Tus-Resumable' => '1.0.0']);
            }

            $metadata = $info['metadata'] ?? [];
            try {
                $filename = $this->uploadValidator->sanitizeAndValidateFilename($metadata['filename'] ?? 'file');
            } catch (\InvalidArgumentException $e) {
                return $this->json(['error' => $e->getMessage()], 400, ['Tus-Resumable' => '1.0.0']);
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            try {
                $mimeType = $this->uploadValidator->validateFileContent($sourcePath, $ext);
                $this->uploadValidator->validateSizeAndQuota($project, $fileSize ?: 0);
            } catch (\InvalidArgumentException $e) {
                @unlink($sourcePath);
                $this->tusServer->cleanup($projectUuid, $uploadId);
                return $this->json(['error' => $e->getMessage()], 400, ['Tus-Resumable' => '1.0.0']);
            }

            // Marquer en cours de finalisation
            $this->tusServer->markFinalizing($projectUuid, $uploadId);

            // Générer un nom unique (comme Vich UniqidNamer)
            $uniqueName = uniqid() . '.' . $ext;

            // Destination finale dans public/uploads/media/{canonicalUuid}/
            $canonicalProjectUuid = $project->uuid?->toRfc4122() ?? $projectUuid;
            $publicDir = $this->getParameter('kernel.project_dir') . '/public/uploads/media/' . $canonicalProjectUuid;
            if (!is_dir($publicDir)) {
                mkdir($publicDir, 0755, true);
            }
            $destPath = $publicDir . '/' . $uniqueName;

            // Déplacer le fichier assemblé vers la destination publique
            if (!rename($sourcePath, $destPath)) {
                // Fallback: copier si rename échoue (volumes différents)
                copy($sourcePath, $destPath);
                @unlink($sourcePath);
            }

            $finalSize = filesize($destPath);

            // Créer l'entité Media
            $media = new Media();
            $media->project      = $project;
            $media->fileName     = $uniqueName;
            $media->originalName = $filename;
            $media->mimeType     = $mimeType;
            $media->fileSize     = $finalSize !== false ? $finalSize : 0;

            // Dossier optionnel
            $folderId = $metadata['folder_id'] ?? null;
            if ($folderId !== null && $folderId !== '') {
                $folder = $this->folderRepository->findOneBy(['id' => (int) $folderId, 'project' => $project]);
                if ($folder) {
                    $media->folder = $folder;
                }
            }

            // ── Sync multi-stockage ──
            $storage = new StorageManager($project, $this->profileRepo, $this->ruleRepo, $this->driverFactory);
            $relativePath = $canonicalProjectUuid . '/' . $uniqueName;

            try {
                $fp = fopen($destPath, 'rb');
                $paths = $storage->write($relativePath, $fp, [
                    'mime_type' => $mimeType,
                    'filename'  => $filename,
                    'size'      => $media->fileSize,
                ]);
                fclose($fp);

                $media->storagePaths = $paths;
                $firstProfileUuid = array_key_first($paths);
                if ($firstProfileUuid !== null) {
                    try {
                        $firstProfile = $this->profileRepo->findOneBy(['uuid' => $firstProfileUuid]);
                        if ($firstProfile !== null) {
                            $media->storageProfile = $firstProfile;
                        }
                    } catch (\Throwable) {}
                }
            } catch (\Throwable $e) {
                // Même si la sync distante échoue, on garde le fichier local
                trigger_error("TusController::finalize storage sync failed: " . $e->getMessage(), E_USER_WARNING);
            }

            $this->em->persist($media);
            $this->em->flush();

            // Notification temps réel
            $serialized = $this->mediaSerializer->serialize($media);
            $this->mercure->mediaUploaded($canonicalProjectUuid, $serialized);

            // Marquer finalisé et nettoyer les fichiers temporaires TUS
            $this->tusServer->markFinalized($projectUuid, $uploadId);
            $this->tusServer->cleanup($projectUuid, $uploadId);

            return $this->json(['data' => $serialized], 201, ['Tus-Resumable' => '1.0.0']);
        });

        return $result ?? $this->json(['error' => $this->trans('tus.upload_busy', [], 'Upload is currently busy')], 409, ['Tus-Resumable' => '1.0.0']);
    }

    // ─── Private ─────────────────────────────────────────────────────────

    private function findProject(string $projectUuid): ?Project
    {
        if (!Uuid::isValid($projectUuid)) {
            return null;
        }
        try {
            return $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isValidUploadId(string $uploadId): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadId);
    }

    /**
     * Vérifie que l'utilisateur connecté a accès au projet.
     * Les super-admins ont accès à tout, les autres doivent être membres avec permission adéquate.
     */
    private function denyProjectAccess(Project $project, ?Request $request = null, string $permission = 'content.create'): void
    {
        if ($request !== null && $request->attributes->get('_project') === $project) {
            return;
        }
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException($this->trans('tus.auth_required', [], 'Authentication required'));
        }
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }
        $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
        if ($member === null) {
            throw $this->createAccessDeniedException($this->trans('tus.access_denied', [], 'Access denied'));
        }
        if ($permission !== 'project.view' && $member->role !== null) {
            if (!$member->role->hasPermission('project.manage') && !$member->role->hasPermission($permission)) {
                throw $this->createAccessDeniedException($this->trans('tus.permission_denied', [], 'Permission denied'));
            }
        }
    }

    /**
     * Parse le header Upload-Metadata au format TUS :
     * "filename ZmlsZW5hbWU=,filetype aW1hZ2UvanBlZw=="
     *
     * @return array<string, string>  ex: ['filename' => 'photo.jpg', 'filetype' => 'image/jpeg']
     */
    private function parseMetadata(string $header): array
    {
        $metadata = [];
        $header = trim($header);
        if ($header === '' || strlen($header) > 4096) {
            return $metadata;
        }

        $pairs = explode(',', $header);
        foreach ($pairs as $pair) {
            $parts = explode(' ', trim($pair), 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $key)) {
                    continue;
                }
                $value = base64_decode(trim($parts[1]), true);
                if ($value !== false) {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }
}
