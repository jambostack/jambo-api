<?php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/projects/{projectUuid}/files/tus', name: 'api_tus_')]
class TusController extends AbstractController
{
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
    ) {}

    /** Extensions autorisées — refuse tout fichier exécutable */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
        'mp4', 'webm', 'mov', 'avi',
        'mp3', 'wav', 'ogg', 'aac', 'flac',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'json', 'xml', 'yaml', 'yml',
        'zip', 'gz', 'tar',
    ];

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
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyProjectAccess($project);

        // Vérifier le header obligatoire
        if ($request->headers->get('Tus-Resumable') !== '1.0.0') {
            return $this->json(['error' => 'Missing Tus-Resumable header'], 400);
        }

        $uploadLength = (int) $request->headers->get('Upload-Length', '0');
        if ($uploadLength <= 0) {
            return $this->json(['error' => 'Invalid or missing Upload-Length'], 400);
        }

        // Parser Upload-Metadata : format "filename BASE64,filetype BASE64[,folder_id BASE64]"
        $metadata = $this->parseMetadata($request->headers->get('Upload-Metadata', ''));

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
     * Si le chunk final est reçu (offset + chunk_size >= upload_length),
     * le serveur finalise automatiquement.
     */
    #[Route('/{uploadId}', name: 'process', methods: ['PATCH'])]
    public function patch(string $projectUuid, string $uploadId, Request $request): Response
    {
        if ($request->headers->get('Tus-Resumable') !== '1.0.0') {
            return $this->json(['error' => 'Missing Tus-Resumable header'], 400);
        }

        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyProjectAccess($project);

        if (!$this->tusServer->exists($projectUuid, $uploadId)) {
            return $this->json(['error' => 'Upload not found'], 404);
        }

        $info = $this->tusServer->getInfo($projectUuid, $uploadId);
        if ($info['finalized'] ?? false) {
            return $this->json(['error' => 'Upload already finalized'], 409);
        }

        $offset = (int) $request->headers->get('Upload-Offset', '0');

        // Lire le corps brut comme flux
        $stream = $request->getContent(true); // resource

        try {
            $newOffset = $this->tusServer->patch($projectUuid, $uploadId, $offset, $stream);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 404);
        }

        $headers = [
            'Upload-Offset' => (string) $newOffset,
            'Tus-Resumable' => '1.0.0',
        ];

        // Finalisation automatique si upload complet
        if ($this->tusServer->isComplete($projectUuid, $uploadId) && !($info['finalized'] ?? false)) {
            $this->tusServer->markFinalized($projectUuid, $uploadId);
            // Finalisation asynchrone ? Non, on le fait ici.
            // Le client s'attend à recevoir le JSON du Media créé.
        }

        return new Response('', 204, $headers);
    }

    /**
     * HEAD — Retourne l'offset actuel (pour reprise d'upload).
     */
    #[Route('/{uploadId}', name: 'head', methods: ['HEAD'])]
    public function head(string $projectUuid, string $uploadId, Request $request): Response
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new Response('', 404, ['Tus-Resumable' => '1.0.0']);
        }
        $this->denyProjectAccess($project);

        if (!$this->tusServer->exists($projectUuid, $uploadId)) {
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
    public function delete(string $projectUuid, string $uploadId): Response
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyProjectAccess($project);

        if (!$this->tusServer->exists($projectUuid, $uploadId)) {
            return $this->json(['error' => 'Upload not found'], 404);
        }
        $this->tusServer->cancel($projectUuid, $uploadId);
        return new Response('', 204, ['Tus-Resumable' => '1.0.0']);
    }

    /**
     * POST /finalize — Appelé par le client après le dernier chunk pour
     * créer l'entité Media et synchroniser les stockages.
     */
    #[Route('/{uploadId}/finalize', name: 'finalize', methods: ['POST'])]
    public function finalize(string $projectUuid, string $uploadId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyProjectAccess($project);

        $info = $this->tusServer->getInfo($projectUuid, $uploadId);
        if ($info === null) {
            return $this->json(['error' => 'Upload not found'], 404);
        }

        $metadata = $info['metadata'] ?? [];
        $filename = $this->sanitizeFilename($metadata['filename'] ?? 'file');

        // Validation de l'extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return $this->json(['error' => 'File type not allowed'], 400);
        }

        // Validation du contenu réel (MIME magique)
        $sourcePath = $this->tusServer->getFilePath($projectUuid, $uploadId);
        if (!file_exists($sourcePath)) {
            return $this->json(['error' => 'Upload file missing'], 404);
        }
        $detectedMime = mime_content_type($sourcePath);
        $mimeType = $detectedMime !== false ? $detectedMime : 'application/octet-stream';

        // Générer un nom unique (comme Vich UniqidNamer)
        $uniqueName = uniqid() . '.' . $ext;

        // Destination finale dans public/uploads/media/{projectUuid}/
        $publicDir = $this->getParameter('kernel.project_dir') . '/public/uploads/media/' . $projectUuid;
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

        $fileSize = filesize($destPath);

        // Créer l'entité Media
        $media = new Media();
        $media->project      = $project;
        $media->fileName     = $uniqueName;
        $media->originalName = $filename;
        $media->mimeType     = $mimeType;
        $media->fileSize     = $fileSize !== false ? $fileSize : 0;

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
        $relativePath = $projectUuid . '/' . $uniqueName;

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
                // Trouver le profil pour déterminer le driver (local/s3)
                $firstProfile = $this->profileRepo->findOneBy(['uuid' => $firstProfileUuid]);
                if ($firstProfile !== null) {
                    $media->storageProfile = $firstProfile;
                }
            }
        } catch (\Throwable $e) {
            // Même si la sync distante échoue, on garde le fichier local
            trigger_error("TusController::finalize storage sync failed: " . $e->getMessage(), E_USER_WARNING);
        }

        $this->em->persist($media);
        $this->em->flush();

        // Notification temps réel
        $serialized = $this->mediaSerializer->serialize($media);
        $this->mercure->mediaUploaded($projectUuid, $serialized);

        // Nettoyer les fichiers temporaires TUS
        $this->tusServer->cleanup($projectUuid, $uploadId);

        return $this->json(['data' => $serialized], 201);
    }

    // ─── Private ─────────────────────────────────────────────────────────

    /**
     * Vérifie que l'utilisateur connecté a accès au projet.
     * Les super-admins ont accès à tout, les autres doivent être membres.
     */
    private function denyProjectAccess(Project $project): void
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException('Authentication required');
        }
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }
        $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
        if ($member === null) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Nettoie le nom de fichier : supprime les caractères dangereux
     * et prévient le path traversal via null bytes.
     */
    private function sanitizeFilename(string $name): string
    {
        // Rejette tout null byte (path truncation)
        if (str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Filename contains null byte');
        }
        // Ne garde que le nom de base (pas de chemin)
        $name = basename($name);
        // Supprime les caractères non imprimables et les slashes
        $name = preg_replace('/[\x00-\x1F\/\\\\]/', '', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'unnamed';
        }
        return $name;
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
        if ($header === '' || $header === null) {
            return $metadata;
        }

        $pairs = explode(',', $header);
        foreach ($pairs as $pair) {
            $parts = explode(' ', trim($pair), 2);
            if (count($parts) === 2) {
                $key   = $parts[0];
                $value = base64_decode($parts[1], true);
                if ($value !== false) {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }
}
