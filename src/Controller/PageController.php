<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\EndUser;
use App\Entity\Field;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\EndUserField;
use App\Repository\ApiTokenRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\EndUserFieldRepository;
use App\Repository\EndUserRepository;
use App\Repository\FieldRepository;
use App\Repository\PermissionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\MediaSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inertia page controller — renders React pages with server-side props.
 * All routes here take priority over DefaultController's catch-all.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PageController extends InertiaController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private FieldRepository $fieldRepository,
        private ContentEntryRepository $entryRepository,
        private ApiTokenRepository $apiTokenRepository,
        private EndUserRepository $endUserRepository,
        private EndUserFieldRepository $endUserFieldRepository,
        private EntityManagerInterface $em,
        private EavDataFormatterService $formatter,
        private MediaSerializer $mediaSerializer,
        private ProjectMemberRepository $memberRepo,
        private UserPasswordHasherInterface $hasher,
        private PermissionRepository $permissionRepository,
        private FieldRelationOptionsNormalizer $relationOptionsNormalizer,
    ) {}

    /** Cached ProjectMember resolved by denyProjectAccess() for reuse in buildUserCan(). */
    private ?ProjectMember $currentProjectMember = null;

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    #[Route('/', name: 'dashboard', priority: 10)]
    public function dashboard(Request $request): Response
    {
        $user = $this->getUser();
        $projects = $this->projectRepository->findByMember($user);

        return $this->inertia($request, 'dashboard', [
            'projects' => array_map(fn ($p) => $this->serializeProject($p), $projects),
            'userCan'  => $this->buildUserCan(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Project pages
    // -------------------------------------------------------------------------

    #[Route('/projects/{project}', name: 'projects_show', requirements: ['project' => '\d+'], priority: 10)]
    public function projectShow(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $user        = $this->getUser();
        $allProjects = $this->projectRepository->findByMember($user);

        return $this->inertia($request, 'Projects/Show', [
            'project'     => $this->serializeProject($project, true),
            'userCan'     => $this->buildUserCan($project),
            'allProjects' => array_map(
                fn ($p) => ['uuid' => $p->uuid?->toRfc4122(), 'name' => $p->name],
                $allProjects
            ),
        ]);
    }

    #[Route('/projects/{project}/insights', name: 'projects_insights', requirements: ['project' => '\d+'], priority: 10)]
    public function insights(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        return $this->inertia($request, 'Projects/Insights/Index', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $this->buildUserCan($project),
        ]);
    }

    // -------------------------------------------------------------------------
    // Collection / Content pages
    // -------------------------------------------------------------------------

    #[Route('/projects/{project}/collections/{collection}', name: 'projects_collections_show', requirements: ['project' => '\d+', 'collection' => '\d+'], priority: 10)]
    public function collectionShow(int $project, int $collection, Request $request): Response
    {
        [$project, $collection] = $this->resolveProjectAndCollection($project, $collection);

        return $this->inertia($request, 'Collections/Show', [
            'project'    => $this->serializeProject($project, withCollections: true),
            'collection' => $this->serializeCollection($collection, withFields: true),
            'userCan'    => $this->buildUserCan($project),
        ]);
    }

    #[Route('/projects/{project}/collections/{collection}/edit', name: 'projects_collections_edit', requirements: ['project' => '\d+', 'collection' => '\d+'], priority: 10)]
    public function collectionEdit(int $project, int $collection, Request $request): Response
    {
        [$project, $collection] = $this->resolveProjectAndCollection($project, $collection);

        return $this->inertia($request, 'Collections/Edit', [
            'project'    => $this->serializeProject($project, withCollections: true),
            'collection' => $this->serializeCollection($collection, withFields: true),
            'userCan'    => $this->buildUserCan($project),
        ]);
    }

    #[Route('/projects/{project}/collections/{collection}/content/create', name: 'projects_collections_content_create', requirements: ['project' => '\d+', 'collection' => '\d+'], priority: 10)]
    public function contentCreate(int $project, int $collection, Request $request): Response
    {
        [$project, $collection] = $this->resolveProjectAndCollection($project, $collection);

        return $this->inertia($request, 'Collections/Show', [
            'project'    => $this->serializeProject($project, withCollections: true),
            'collection' => $this->serializeCollection($collection, withFields: true),
            'isEditMode' => false,
            'userCan'    => $this->buildUserCan($project),
        ]);
    }

    #[Route('/projects/{project}/collections/{collection}/content/{contentEntry}/edit', name: 'projects_collections_content_edit', requirements: ['project' => '\d+', 'collection' => '\d+', 'contentEntry' => '\d+'], priority: 10)]
    public function contentEdit(int $project, int $collection, int $contentEntry, Request $request): Response
    {
        [$project, $collection] = $this->resolveProjectAndCollection($project, $collection);

        $entry = $this->entryRepository->find($contentEntry);
        if (!$entry || $entry->collection->id !== $collection->id) {
            throw $this->createNotFoundException();
        }

        return $this->inertia($request, 'Collections/Show', [
            'project'      => $this->serializeProject($project, withCollections: true),
            'collection'   => $this->serializeCollection($collection, withFields: true),
            'contentEntry' => $this->serializeEntry($entry),
            'formData'     => $this->formatter->formatEntry($entry),
            'isEditMode'   => true,
            'userCan'      => $this->buildUserCan($project),
        ]);
    }

    #[Route('/projects/{project}/collections/{collection}/content/trash', name: 'projects_collections_content_trash', requirements: ['project' => '\d+', 'collection' => '\d+'], priority: 10)]
    public function contentTrash(int $project, int $collection, Request $request): Response
    {
        [$project, $collection] = $this->resolveProjectAndCollection($project, $collection);

        return $this->inertia($request, 'Content/ContentTrash', [
            'project'    => $this->serializeProject($project, withCollections: true),
            'collection' => $this->serializeCollection($collection, withFields: true),
            'userCan'    => $this->buildUserCan($project),
        ]);
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    #[Route('/projects/{project}/assets', name: 'assets_index', requirements: ['project' => '\d+'], priority: 10)]
    public function assets(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $where = 'm.project = :project AND m.deletedAt IS NULL';
        $params = ['project' => $project];

        // Filtre par dossier
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
        $typeMap = [
            'image' => 'image/%',
            'video' => 'video/%',
            'audio' => 'audio/%',
            'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.%', 'application/vnd.ms-%', 'application/vnd.oasis.%', 'text/plain', 'text/csv'],
        ];
        if ($type !== '' && $type !== 'all') {
            if ($type === 'document') {
                $docTypes = $typeMap['document'];
                $orParts = [];
                foreach ($docTypes as $k => $dt) {
                    $orParts[] = "m.mimeType LIKE :type_doc_{$k}";
                    $params["type_doc_{$k}"] = $dt;
                }
                $where .= ' AND (' . implode(' OR ', $orParts) . ')';
            } elseif (isset($typeMap[$type])) {
                $where .= ' AND m.mimeType LIKE :type';
                $params['type'] = $typeMap[$type];
            } else {
                // "other" — tout ce qui n'est pas image/video/audio/document
                $where .= ' AND m.mimeType NOT LIKE :type_img AND m.mimeType NOT LIKE :type_vid AND m.mimeType NOT LIKE :type_aud';
                $params['type_img'] = 'image/%';
                $params['type_vid'] = 'video/%';
                $params['type_aud'] = 'audio/%';
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
        $from  = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $to    = min($page * $perPage, $total);

        return $this->inertia($request, 'Assets/Index', [
            'project' => $this->serializeProject($project, withCollections: true),
            'userCan' => $this->buildUserCan($project),
            'assets'  => [
                'data'         => array_map(fn (Media $m) => $this->mediaSerializer->serialize($m), $media),
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'total'        => $total,
                'per_page'     => $perPage,
                'from'         => $from,
                'to'           => $to,
            ],
            'filters' => [
                'search'      => $request->query->get('search', ''),
                'type'        => $request->query->get('type', ''),
                'date_filter' => $request->query->get('date_filter', ''),
                'sort'        => $request->query->get('sort', 'newest'),
                'per_page'    => (string) $perPage,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Project Settings
    // -------------------------------------------------------------------------

    #[Route('/projects/{project}/settings/project', name: 'projects_settings_project', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsProject(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/Project');
    }

    #[Route('/projects/{project}/settings/security', name: 'projects_settings_security', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsProjectSecurity(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/Security');
    }

    #[Route('/projects/{project}/settings/localization', name: 'projects_settings_localization', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsLocalization(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/Localization');
    }

    #[Route('/projects/{project}/settings/user-access', name: 'projects_settings_user_access', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsUserAccess(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/UserAccess');
    }

    #[Route('/projects/{project}/settings/api-access', name: 'projects_settings_api_access', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsApiAccess(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $tokens = $this->apiTokenRepository->findByProject($project);

        return $this->inertia($request, 'Projects/Settings/APIAccess', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $this->buildUserCan($project),
            'tokens'  => array_map(fn ($t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'abilities'  => $t->abilities,
                'created_at' => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $tokens),
        ]);
    }

    #[Route('/projects/{project}/settings/api-docs', name: 'projects_settings_api_docs', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsApiDocs(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $userCan = $this->buildUserCan($project);
        if (!($userCan['access_api_access_settings'] ?? false)) {
            throw $this->createAccessDeniedException();
        }

        return $this->inertia($request, 'Projects/Settings/ApiDocs', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $userCan,
        ]);
    }

    #[Route('/projects/{project}/settings/jwt-ttl', name: 'projects_settings_jwt_ttl', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsJwtTtl(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        return $this->inertia($request, 'Projects/Settings/JwtTtl', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $this->buildUserCan($project),
        ]);
    }

    #[Route('/projects/{project}/settings/storage', name: 'projects_settings_storage', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsStorage(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        return $this->inertia($request, 'Projects/Settings/Storage', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $this->buildUserCan($project),
        ]);
    }

    #[Route('/projects/{project}/settings/mailer', name: 'projects_settings_mailer', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsMailer(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        return $this->inertia($request, 'Projects/Settings/Mailer', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $this->buildUserCan($project),
        ]);
    }

    #[Route('/projects/{project}/settings/webhooks', name: 'projects_settings_webhooks', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsWebhooks(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/Webhooks');
    }

    #[Route('/projects/{project}/settings/mcp-access', name: 'projects_settings_mcp_access', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsMcpAccess(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $userCan = $this->buildUserCan($project);
        if (!($userCan['access_api_access_settings'] ?? false)) {
            throw $this->createAccessDeniedException();
        }

        $tokens = $this->apiTokenRepository->findByProject($project);

        return $this->inertia($request, 'Projects/Settings/McpAccess', [
            'project' => $this->serializeProject($project, true),
            'userCan' => $userCan,
            'tokens'  => array_map(fn ($t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'abilities'  => $t->abilities,
                'created_at' => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $tokens),
        ]);
    }

    #[Route('/projects/{project}/settings/webhook-logs', name: 'projects_settings_webhook_logs', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsWebhookLogs(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/WebhookLogs');
    }

    #[Route('/projects/{project}/settings/automations', name: 'projects_settings_automations', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsAutomations(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/Automations/Index');
    }

    // -------------------------------------------------------------------------
    // End Users Management
    // -------------------------------------------------------------------------

    #[Route('/projects/{project}/settings/end-users', name: 'projects_settings_end_users', requirements: ['project' => '\d+'], methods: ['GET'], priority: 10)]
    public function settingsEndUsers(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $status = $request->query->get('status', '');
        $search = $request->query->get('search', '');
        $page   = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->createQueryBuilder()
            ->select('eu')
            ->from(EndUser::class, 'eu')
            ->where('eu.project = :project')
            ->setParameter('project', $project)
            ->orderBy('eu.createdAt', 'DESC');

        if ($status !== '' && in_array($status, ['active', 'banned', 'pending'], true)) {
            $qb->andWhere('eu.status = :status')->setParameter('status', $status);
        }
        if ($search !== '') {
            $qb->andWhere('eu.email LIKE :search OR eu.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(eu.id)')->getQuery()->getSingleScalarResult();

        $qb->setMaxResults($perPage)->setFirstResult(($page - 1) * $perPage);
        $endUsers = $qb->getQuery()->getResult();

        return $this->inertia($request, 'Projects/Settings/EndUsers/Index', [
            'project'   => $this->serializeProject($project, true),
            'userCan'   => $this->buildUserCan($project),
            'endUsers'  => [
                'data'         => array_map(fn (EndUser $eu) => $this->serializeEndUser($eu), $endUsers),
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'total'        => $total,
                'per_page'     => $perPage,
            ],
            'filters' => [
                'status'   => $status,
                'search'   => $search,
                'per_page' => (string) $perPage,
            ],
        ]);
    }

    #[Route('/projects/{project}/settings/end-users/schema', name: 'projects_settings_end_users_schema', requirements: ['project' => '\d+'], priority: 11)]
    public function settingsEndUsersSchema(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $fields = $this->endUserFieldRepository->findByProject($project);

        return $this->inertia($request, 'Projects/Settings/EndUsers/Schema', [
            'project'       => $this->serializeProject($project, true),
            'userCan'       => $this->buildUserCan($project),
            'endUserFields' => array_map(fn (EndUserField $f) => $this->serializeEndUserField($f), $fields),
        ]);
    }

    #[Route('/projects/{project}/settings/end-users/create', name: 'projects_settings_end_users_create', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsEndUsersCreate(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $fields = $this->endUserFieldRepository->findByProject($project);

        return $this->inertia($request, 'Projects/Settings/EndUsers/Create', [
            'project'       => $this->serializeProject($project, true),
            'userCan'       => $this->buildUserCan($project),
            'endUserFields' => array_map(fn (EndUserField $f) => $this->serializeEndUserField($f), $fields),
        ]);
    }

    #[Route('/projects/{project}/settings/end-users', name: 'projects_settings_end_users_store', requirements: ['project' => '\d+'], methods: ['POST'], priority: 10)]
    public function settingsEndUsersStore(int $project, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $data = $request->toArray();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $username = $data['username'] ?? null;
        $status = $data['status'] ?? 'active';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['errors' => ['email' => 'Valid email is required']], 422);
        }
        if (strlen($password) < 8) {
            return $this->json(['errors' => ['password' => 'Password must be at least 8 characters']], 422);
        }
        if (!in_array($status, ['active', 'pending'], true)) {
            $status = 'active';
        }

        $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if ($existing) {
            return $this->json(['errors' => ['email' => 'Email already registered in this project']], 422);
        }

        $endUser = new EndUser($project, $email);
        $endUser->username = $username;
        $endUser->password = $this->hasher->hashPassword($endUser, $password);
        $endUser->status = $status;

        if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
            $endUser->customFields = $data['custom_fields'];
        }

        $this->em->persist($endUser);
        $this->em->flush();

        return $this->redirectToRoute('projects_settings_end_users', ['project' => $project->id], 303);
    }

    #[Route('/projects/{project}/settings/end-users/{endUserUuid}', name: 'projects_settings_end_users_show', requirements: ['project' => '\d+', 'endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'], priority: 10)]
    public function settingsEndUsersShow(int $project, string $endUserUuid, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) {
            throw $this->createNotFoundException();
        }

        $fields = $this->endUserFieldRepository->findByProject($project);

        return $this->inertia($request, 'Projects/Settings/EndUsers/Show', [
            'project'       => $this->serializeProject($project, true),
            'userCan'       => $this->buildUserCan($project),
            'endUser'       => $this->serializeEndUser($endUser),
            'endUserFields' => array_map(fn (EndUserField $f) => $this->serializeEndUserField($f), $fields),
        ]);
    }

    #[Route('/projects/{project}/settings/end-users/{endUserUuid}/edit', name: 'projects_settings_end_users_edit', requirements: ['project' => '\d+', 'endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], priority: 10)]
    public function settingsEndUsersEdit(int $project, string $endUserUuid, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) {
            throw $this->createNotFoundException();
        }

        $fields = $this->endUserFieldRepository->findByProject($project);

        return $this->inertia($request, 'Projects/Settings/EndUsers/Edit', [
            'project'       => $this->serializeProject($project, true),
            'userCan'       => $this->buildUserCan($project),
            'endUser'       => $this->serializeEndUser($endUser),
            'endUserFields' => array_map(fn (EndUserField $f) => $this->serializeEndUserField($f), $fields),
        ]);
    }

    #[Route('/projects/{project}/settings/end-users/{endUserUuid}', name: 'projects_settings_end_users_update', requirements: ['project' => '\d+', 'endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['PATCH'], priority: 10)]
    public function settingsEndUsersUpdate(int $project, string $endUserUuid, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) {
            throw $this->createNotFoundException();
        }

        $data = $request->toArray();

        if (isset($data['email']) && $data['email'] !== $endUser->email) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->json(['errors' => ['email' => 'Valid email is required']], 422);
            }
            $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $data['email']);
            if ($existing) {
                return $this->json(['errors' => ['email' => 'Email already registered in this project']], 422);
            }
            $endUser->email = $data['email'];
        }

        if (isset($data['username'])) {
            $endUser->username = $data['username'] ?: null;
        }

        if (isset($data['custom_fields'])) {
            $endUser->customFields = $data['custom_fields'];
        }

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                return $this->json(['errors' => ['password' => 'Password must be at least 8 characters']], 422);
            }
            $endUser->password = $this->hasher->hashPassword($endUser, $data['password']);
            $endUser->tokenVersion++;
        }

        $this->em->flush();

        return $this->redirectToRoute('projects_settings_end_users_show', ['project' => $project->id, 'endUserUuid' => $endUserUuid], 303);
    }

    #[Route('/projects/{project}/settings/end-users/{endUserUuid}/status', name: 'projects_settings_end_users_status', requirements: ['project' => '\d+', 'endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['PATCH'], priority: 10)]
    public function settingsEndUsersStatus(int $project, string $endUserUuid, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) {
            throw $this->createNotFoundException();
        }

        $data = $request->toArray();
        $newStatus = $data['status'] ?? '';
        if (!in_array($newStatus, ['active', 'banned', 'pending'], true)) {
            return $this->json(['errors' => ['status' => 'Invalid status']], 422);
        }

        $endUser->status = $newStatus;
        if ($newStatus === 'banned') {
            $endUser->tokenVersion++;
        }
        $this->em->flush();

        // NOTE: Le frontend utilise maintenant l'API JSON (EndUserAdminController).
        // Cette route Inertia est conservée pour compatibilité mais n'est plus appelée.
        return $this->json(['success' => true, 'status' => $newStatus]);
    }

    #[Route('/projects/{project}/settings/end-users/{endUserUuid}', name: 'projects_settings_end_users_destroy', requirements: ['project' => '\d+', 'endUserUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['DELETE'], priority: 10)]
    public function settingsEndUsersDestroy(int $project, string $endUserUuid, Request $request): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $endUserUuid, 'project' => $project]);
        if (!$endUser) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($endUser);
        $this->em->flush();

        // NOTE: Le frontend utilise maintenant l'API JSON (EndUserAdminController).
        // Cette route Inertia est conservée pour compatibilité mais n'est plus appelée.
        return $this->json(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // App Settings (admin)
    // -------------------------------------------------------------------------

    #[Route('/admin/app-settings', name: 'admin_app_settings', priority: 10)]
    public function adminAppSettings(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->inertia($request, 'AdminSettings/AppSettings', [
            'userCan' => $this->buildUserCan(),
        ]);
    }

    // -------------------------------------------------------------------------
    // User Management
    // -------------------------------------------------------------------------

    #[Route('/user-management/users', name: 'users_index', priority: 10)]
    public function users(Request $request): Response
    {
        $roles = $this->em->getRepository(\App\Entity\Role::class)->findAll();

        return $this->inertia($request, 'UserManagement/Users', [
            'userCan' => $this->buildUserCan(),
            'roles'   => array_map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'label' => $r->label], $roles),
        ]);
    }

    #[Route('/user-management/roles', name: 'users_roles', priority: 10)]
    public function roles(Request $request): Response
    {
        return $this->inertia($request, 'UserManagement/Roles', [
            'userCan'          => $this->buildUserCan(),
            'permissionGroups' => $this->buildPermissionGroups(),
        ]);
    }

    #[Route('/user-management/permissions', name: 'users_permissions', priority: 10)]
    public function permissions(Request $request): Response
    {
        return $this->inertia($request, 'UserManagement/Permissions', [
            'userCan' => $this->buildUserCan(),
        ]);
    }

    // -------------------------------------------------------------------------
    // User Settings
    // -------------------------------------------------------------------------

    #[Route('/settings/profile', name: 'settings_profile', priority: 10)]
    public function settingsProfile(Request $request): Response
    {
        return $this->inertia($request, 'settings/profile', [
            'user' => $this->serializeCurrentUser(),
        ]);
    }

    #[Route('/settings/password', name: 'settings_password', priority: 10)]
    public function settingsPassword(Request $request): Response
    {
        return $this->inertia($request, 'settings/password', []);
    }

    #[Route('/settings/appearance', name: 'settings_appearance', priority: 10)]
    public function settingsAppearance(Request $request): Response
    {
        return $this->inertia($request, 'settings/appearance', []);
    }

    #[Route('/settings/security', name: 'settings_security', priority: 10)]
    public function settingsSecurity(Request $request): Response
    {
        return $this->inertia($request, 'settings/security', [
            'user' => $this->serializeCurrentUser(),
        ]);
    }

    #[Route('/settings/personal-access-tokens', name: 'settings_personal_access_tokens', priority: 10)]
    public function settingsPersonalAccessTokens(Request $request): Response
    {
        return $this->inertia($request, 'settings/personal-access-tokens', []);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function settingsPage(int $project, Request $request, string $component): Response
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException();
        }
        $this->denyProjectAccess($project);

        return $this->inertia($request, $component, [
            'project' => $this->serializeProject($project, true),
            'userCan' => $this->buildUserCan($project),
        ]);
    }

    private function resolveProjectAndCollection(int $project, int $collection): array
    {
        $project = $this->projectRepository->find($project);
        if (!$project) {
            throw $this->createNotFoundException('Project not found');
        }
        $this->denyProjectAccess($project);

        $collection = $this->collectionRepository->find($collection);
        if (!$collection || $collection->project->id !== $project->id || $collection->isDeleted()) {
            throw $this->createNotFoundException('Collection not found');
        }

        return [$project, $collection];
    }

    private function denyProjectAccess(Project $project): void
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }
        $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
        if ($member === null) {
            throw $this->createAccessDeniedException();
        }
        $this->currentProjectMember = $member; // cache for buildUserCan
    }

    private function serializeProject(Project $project, bool $withCollections = false): array
    {
        $security = ($project->settings ?? [])['security'] ?? [];
        $socialProviders = [];
        foreach (($security['endUserSocialProviders'] ?? []) as $p => $cfg) {
            $socialProviders[$p] = [
                'enabled'    => (bool) ($cfg['enabled'] ?? false),
                'configured' => !empty($cfg['clientId']) && !empty($cfg['clientSecret']),
            ];
        }

        $data = [
            'id'              => $project->id,
            'uuid'            => $project->uuid?->toRfc4122(),
            'name'            => $project->name,
            'description'     => $project->description,
            'disk'            => $project->disk,
            'default_locale'  => $project->defaultLocale,
            'locales'         => $project->locales,
            'settings'        => null,
            'security'        => [
                'endUserTwoFactor'          => $security['endUserTwoFactor'] ?? false,
                'endUserTwoFactorMethods'   => $security['endUserTwoFactorMethods'] ?? ['totp', 'email'],
                'endUserSocialLogin'        => $security['endUserSocialLogin'] ?? false,
                'endUserSocialProviders'    => $socialProviders,
            ],
            'public_api'       => $project->publicApi,
            'jwt_access_ttl'   => $project->jwtAccessTtl,
            'jwt_refresh_ttl'  => $project->jwtRefreshTtl,
            'created_at'      => null,
            'updated_at'      => null,
        ];

        if ($withCollections) {
            $collections = $project->collections->filter(fn ($c) => !$c->isDeleted())->getValues();
            usort($collections, fn ($a, $b) => $a->order <=> $b->order);
            $data['collections']        = array_map(fn ($c) => $this->serializeCollection($c), $collections);
            $data['collections_count']  = count($collections);
        } else {
            $data['collections_count'] = $project->collections->filter(fn ($c) => !$c->isDeleted())->count();
        }

        return $data;
    }

    private function serializeCollection(Collection $collection, bool $withFields = false): array
    {
        $data = [
            'id'           => $collection->id,
            'uuid'         => $collection->uuid?->toRfc4122(),
            'project_id'   => $collection->project->id,
            'name'         => $collection->name,
            'slug'         => $collection->slug,
            'description'  => $collection->description,
            'is_singleton' => $collection->isSingleton,
            'order'        => $collection->order,
            'created_at'   => null,
            'updated_at'   => null,
        ];

        if ($withFields) {
            $fields = $this->fieldRepository->findByCollection($collection);
            $data['fields'] = array_map(fn ($f) => $this->serializeField($f, $collection), $fields);
        }

        return $data;
    }

    private function serializeField(Field $field, Collection $collection): array
    {
        $options = $field->options ?? [];

        // Normalise les options relation au format canonique (relation.collection id,
        // relation.type, collection_slug dérivé, targetCollection réservé à end_users),
        // en absorbant tous les formats legacy.
        if ($field->type === 'relation') {
            $options = $this->relationOptionsNormalizer->normalize($options, $collection->project) ?? [];
        }

        return [
            'id'            => $field->id,
            'project_id'    => $collection->project->id,
            'project_uuid'  => $collection->project->uuid?->toRfc4122(),
            'collection_id' => $collection->id,
            'name'          => $field->name,
            'label'         => $field->name,
            'slug'          => $field->slug,
            'type'          => $field->type,
            'required'      => $field->isRequired,
            'order'         => $field->order,
            'description'   => null,
            'placeholder'   => null,
            'validations'   => null,
            'options'       => $options,
            'created_at'    => null,
            'updated_at'    => null,
        ];
    }

    private function serializeEntry(ContentEntry $entry): array
    {
        return [
            'id'           => $entry->id,
            'uuid'         => $entry->uuid?->toRfc4122(),
            'status'       => $entry->status,
            'locale'       => $entry->locale,
            'created_at'   => $entry->createdAt?->format(\DateTimeInterface::ATOM),
            'updated_at'   => $entry->updatedAt?->format(\DateTimeInterface::ATOM),
            'deleted_at'   => $entry->deletedAt?->format(\DateTimeInterface::ATOM),
            'published_at' => $entry->publishedAt?->format(\DateTimeInterface::ATOM),
            'creator'      => $entry->createdBy ? ['name' => $entry->createdBy->name ?? $entry->createdBy->email] : null,
            'updater'      => $entry->updatedBy ? ['name' => $entry->updatedBy->name ?? $entry->updatedBy->email] : null,
        ];
    }

    private function serializeCurrentUser(): array
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->getUserIdentifier(),
        ];
    }

    private function serializeEndUserField(EndUserField $field): array
    {
        $options = $field->options ?? [];

        // Parité avec serializeField : enrichit les options de relation
        // (collection_slug dérivé) pour que l'édition d'options fonctionne aussi
        // sur les champs end-user.
        if ($field->type === 'relation') {
            $options = $this->relationOptionsNormalizer->normalize($options, $field->project) ?? [];
        }

        return [
            'id'           => $field->id,
            'project_id'   => $field->project->id,
            'project_uuid' => $field->project->uuid?->toString(),
            'collection_id'=> 0,
            'name'         => $field->name,
            'label'        => $field->name,
            'slug'         => $field->slug,
            'type'         => $field->type,
            'options'      => $options,
            'order'        => $field->order,
            'required'     => $field->isRequired,
            'is_system'    => $field->isSystem,
        ];
    }

    private function serializeEndUser(EndUser $eu): array
    {
        return [
            'uuid'          => $eu->uuid?->toString(),
            'email'         => $eu->email,
            'name'          => $eu->name,
            'status'        => $eu->status,
            'avatar_url'    => $eu->avatarUrl,
            'custom_fields' => $eu->customFields,
            'created_at'    => $eu->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'    => $eu->updatedAt->format(\DateTimeInterface::ATOM),
            'token_version' => $eu->tokenVersion,
        ];
    }

    private function buildPermissionGroups(): array
    {
        $permissions = $this->permissionRepository->findBy([], ['group' => 'ASC', 'name' => 'ASC']);

        $projectLevelGroups = ['project', 'collection', 'content', 'assets', 'end_users', 'webhook', 'media'];
        $groupIconMap = [
            'user' => 'Users', 'users' => 'Users',
            'admin' => 'Shield', 'role' => 'Shield', 'roles' => 'Shield',
        ];

        $groups = [];
        $projects = [];

        foreach ($permissions as $p) {
            $group = $p->group;
            if (in_array($group, $projectLevelGroups, true)) {
                $projects[] = [
                    'name'       => $p->label ?: $p->name,
                    'permission' => $p->name,
                    'icon'       => 'Key',
                ];
            } else {
                if (!isset($groups[$group])) {
                    $groups[$group] = [
                        'group'       => $group,
                        'label'       => ucfirst(str_replace('_', ' ', $group)),
                        'icon'        => $groupIconMap[$group] ?? 'Key',
                        'permissions' => [],
                    ];
                }
                $groups[$group]['permissions'][] = $p->name;
            }
        }

        return [
            'groups'   => array_values($groups),
            'projects' => $projects,
        ];
    }

    private function buildUserCan(?Project $project = null): array
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        // Project-scoped: check member role in THIS project
        $member = ($project !== null && !$isSuperAdmin)
            ? ($this->currentProjectMember ?? $this->memberRepo->findActiveByUserAndProject($user, $project))
            : null;

        $canProject = static fn (string $perm) =>
            $isSuperAdmin || $member?->role?->hasPermission($perm) === true;

        // System-scoped: check global user roles (users.manage, roles.manage)
        $canSystem = static fn (string $perm) =>
            $isSuperAdmin || $user->hasPermission($perm);

        return [
            'create_project'               => $canSystem('project.create'),
            'create_collection'            => $canProject('collection.create'),
            'update_collection'            => $canProject('collection.update'),
            'delete_collection'            => $canProject('collection.delete'),
            'access_collection_settings'   => $canProject('collection.update'),
            'create_field'                 => $canProject('collection.update'),
            'update_field'                 => $canProject('collection.update'),
            'delete_field'                 => $canProject('collection.update'),
            'create_content'               => $canProject('content.create'),
            'update_content'               => $canProject('content.update'),
            'publish_content'              => $canProject('content.update'),
            'unpublish_content'            => $canProject('content.update'),
            'move_content_to_trash'        => $canProject('content.trash'),
            'restore_content'              => $canProject('content.restore'),
            'delete_content'               => $canProject('content.delete'),
            'access_assets'                => $canProject('assets.view'),
            'upload_asset'                 => $canProject('assets.view'),
            'update_asset'                 => $canProject('assets.view'),
            'delete_asset'                 => $canProject('assets.view'),
            'access_project_settings'      => $canProject('project.manage'),
            'access_localization_settings' => $canProject('project.manage'),
            'access_user_access_settings'  => $canProject('project.manage'),
            'access_api_access_settings'   => $canProject('project.manage'),
            'access_webhooks_settings'     => $canProject('project.manage'),
            'access_end_users_settings'   => $canProject('project.manage'),
            'delete_project'               => $canProject('project.manage'),
            'manage_users'                 => $canSystem('users.manage'),
            'manage_roles'                 => $canSystem('roles.manage'),
            'create_permissions'           => $canSystem('users.manage'),
            'update_permissions'           => $canSystem('users.manage'),
            'delete_permissions'           => $canSystem('users.manage'),
            'access_app_settings'          => $isSuperAdmin,
        ];
    }
}
