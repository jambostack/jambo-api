<?php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Repository\MediaFolderRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use App\Service\MediaSerializer;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Vich\UploaderBundle\Handler\UploadHandler;

#[Route('/api/projects/{projectUuid}/media', name: 'api_media_')]
class MediaController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaSerializer $mediaSerializer,
        private UploadHandler $uploadHandler,
        private ProjectRepository $projectRepository,
        private MediaRepository $mediaRepository,
        private MediaFolderRepository $folderRepository,
        private MercurePublisher $mercure,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        // Construire les conditions WHERE
        $where = 'm.project = :project AND m.deletedAt IS NULL';
        $params = ['project' => $project];

        // Filtre par dossier : folder_id=null → racine (sans dossier)
        if ($request->query->has('folder_id')) {
            $folderId = $request->query->get('folder_id');
            if ($folderId === '' || $folderId === null || $folderId === 'null') {
                $where .= ' AND m.folder IS NULL';
            } else {
                $where .= ' AND m.folder = :folderId';
                $params['folderId'] = (int) $folderId;
            }
        }

        // Filtre par recherche texte
        $search = $request->query->get('search', '');
        if ($search !== '') {
            $where .= ' AND (m.originalName LIKE :search OR m.fileName LIKE :search OR m.alt LIKE :search OR m.caption LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        // Filtre par type (mime_type)
        $type = $request->query->get('type', '');
        if ($type !== '' && $type !== 'all') {
            if ($type === 'image') {
                $where .= ' AND m.mimeType LIKE :type';
                $params['type'] = 'image/%';
            } elseif ($type === 'video') {
                $where .= ' AND m.mimeType LIKE :type';
                $params['type'] = 'video/%';
            } elseif ($type === 'audio') {
                $where .= ' AND m.mimeType LIKE :type';
                $params['type'] = 'audio/%';
            } elseif ($type === 'document') {
                $where .= " AND (m.mimeType LIKE :type1 OR m.mimeType LIKE :type2 OR m.mimeType LIKE :type3)";
                $params['type1'] = 'application/pdf';
                $params['type2'] = 'application/msword';
                $params['type3'] = 'application/vnd.%';
            } else {
                // other
                $where .= ' AND m.mimeType NOT LIKE :timg AND m.mimeType NOT LIKE :tvid AND m.mimeType NOT LIKE :taud';
                $params['timg'] = 'image/%';
                $params['tvid'] = 'video/%';
                $params['taud'] = 'audio/%';
            }
        }

        // Filtre par date
        $dateFilter = $request->query->get('date_filter', '');
        if ($dateFilter !== '') {
            $since = match ($dateFilter) {
                'today'   => new \DateTimeImmutable('today'),
                'week'    => new \DateTimeImmutable('-7 days'),
                'month'   => new \DateTimeImmutable('-30 days'),
                'quarter' => new \DateTimeImmutable('-90 days'),
                default   => null,
            };
            if ($since !== null) {
                $where .= ' AND m.createdAt >= :dateSince';
                $params['dateSince'] = $since;
            }
        }

        // Tri
        $sort = $request->query->get('sort', 'newest');
        $orderParts = match ($sort) {
            'oldest'    => ['m.createdAt', 'ASC'],
            'name'      => ['m.originalName', 'ASC'],
            'size'      => ['m.fileSize', 'DESC'],
            default     => ['m.createdAt', 'DESC'],
        };

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where($where)
            ->orderBy($orderParts[0], $orderParts[1])
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage);
        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }

        $countQb = $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Media::class, 'm')
            ->where($where);
        foreach ($params as $key => $value) {
            $countQb->setParameter($key, $value);
        }
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $media = $qb->getQuery()->getResult();

        return $this->json([
            'data'         => array_map(fn ($m) => $this->mediaSerializer->serialize($m), $media),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
            'per_page'     => $perPage,
        ]);
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], 422);
        }

        $media               = new Media();
        $media->project      = $project;
        $media->alt          = $request->request->get('alt');
        $media->caption      = $request->request->get('caption');
        $media->originalName = $uploadedFile->getClientOriginalName();
        $media->setFile($uploadedFile);

        // Dossier cible optionnel
        $folderId = $request->request->get('folder_id');
        if ($folderId !== null && $folderId !== '') {
            $folder = $this->folderRepository->findOneBy(['id' => (int) $folderId, 'project' => $project]);
            if ($folder) {
                $media->folder = $folder;
            }
        }

        $this->em->persist($media);
        $this->em->flush();

        // Notification temps réel
        $serialized = $this->mediaSerializer->serialize($media);
        $this->mercure->mediaUploaded($projectUuid, $serialized);

        return $this->json(['data' => $serialized], 201);
    }

    #[Route('/bulk-destroy', name: 'bulk_destroy', methods: ['DELETE'], priority: 10)]
    public function bulkDestroy(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $ids = $request->toArray()['asset_ids'] ?? [];
        foreach ($ids as $id) {
            $media = $this->mediaRepository->findOneBy(['id' => (int) $id, 'project' => $project]);
            if ($media) {
                $this->uploadHandler->remove($media, 'file');
                $this->em->remove($media);
            }
        }
        $this->em->flush();

        return $this->json(null, 204);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $uuid): JsonResponse
    {
        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        return $this->json(['data' => $this->mediaSerializer->serialize($media)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $data = $request->toArray();
        if (array_key_exists('alt', $data)) {
            $media->alt = $data['alt'];
        }
        if (array_key_exists('caption', $data)) {
            $media->caption = $data['caption'];
        }

        $this->em->flush();

        return $this->json(['data' => $this->mediaSerializer->serialize($media)]);
    }

    #[Route('/{uuid}/move', name: 'move', methods: ['PUT', 'PATCH'])]
    public function move(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $data = $request->toArray();
        if (!array_key_exists('folder_id', $data)) {
            return $this->json(['error' => 'folder_id is required'], 422);
        }

        $folderId = $data['folder_id'];
        if ($folderId === null || $folderId === 'null' || $folderId === '') {
            $media->folder = null;
        } else {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
            $folder = $this->folderRepository->findOneBy(['id' => (int) $folderId, 'project' => $project]);
            if (!$folder) {
                return $this->json(['error' => 'Folder not found'], 404);
            }
            $media->folder = $folder;
        }

        $this->em->flush();

        return $this->json(['data' => $this->mediaSerializer->serialize($media)]);
    }

    #[Route('/{uuid}/crop', name: 'crop', methods: ['POST'])]
    public function crop(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file provided'], 422);
        }

        $media->setFile($uploadedFile);
        $media->originalName = $uploadedFile->getClientOriginalName();
        $this->em->flush();

        return $this->json(['data' => $this->mediaSerializer->serialize($media)]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $uuid): JsonResponse
    {
        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }

        $this->uploadHandler->remove($media, 'file');
        $this->em->remove($media);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function findMedia(string $projectUuid, string $uuid): Media|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        try {
            $media = $this->mediaRepository->findOneBy([
                'uuid'    => Uuid::fromString($uuid),
                'project' => $project,
            ]);
        } catch (\Throwable) {
            $media = is_numeric($uuid)
                ? $this->mediaRepository->findOneBy(['id' => (int) $uuid, 'project' => $project])
                : null;
        }

        if (!$media) {
            return $this->json(['error' => 'Media not found'], 404);
        }

        return $media;
    }
}
