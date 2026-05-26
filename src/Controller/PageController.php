<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Field;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Repository\ApiTokenRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use App\Service\MediaSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private EntityManagerInterface $em,
        private EavDataFormatterService $formatter,
        private MediaSerializer $mediaSerializer,
        private ProjectMemberRepository $memberRepo,
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

        return $this->inertia($request, 'Projects/Show', [
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
            'project'    => $this->serializeProject($project),
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
            'project'      => $this->serializeProject($project),
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
            'project'    => $this->serializeProject($project),
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

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.project = :project')
            ->setParameter('project', $project)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage);

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Media::class, 'm')
            ->where('m.project = :project')
            ->setParameter('project', $project)
            ->getQuery()->getSingleScalarResult();

        $media = $qb->getQuery()->getResult();
        $from  = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
        $to    = min($page * $perPage, $total);

        return $this->inertia($request, 'Assets/Index', [
            'project' => $this->serializeProject($project),
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

    #[Route('/projects/{project}/settings/webhooks', name: 'projects_settings_webhooks', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsWebhooks(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/Webhooks');
    }

    #[Route('/projects/{project}/settings/webhook-logs', name: 'projects_settings_webhook_logs', requirements: ['project' => '\d+'], priority: 10)]
    public function settingsWebhookLogs(int $project, Request $request): Response
    {
        return $this->settingsPage($project, $request, 'Projects/Settings/WebhookLogs');
    }

    // -------------------------------------------------------------------------
    // User Management
    // -------------------------------------------------------------------------

    #[Route('/users', name: 'users_index', priority: 10)]
    public function users(Request $request): Response
    {
        return $this->inertia($request, 'UserManagement/Users', []);
    }

    #[Route('/users/roles', name: 'users_roles', priority: 10)]
    public function roles(Request $request): Response
    {
        return $this->inertia($request, 'UserManagement/Roles', []);
    }

    #[Route('/users/permissions', name: 'users_permissions', priority: 10)]
    public function permissions(Request $request): Response
    {
        return $this->inertia($request, 'UserManagement/Permissions', []);
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
        $data = [
            'id'              => $project->id,
            'uuid'            => $project->uuid?->toRfc4122(),
            'name'            => $project->name,
            'description'     => $project->description,
            'disk'            => $project->disk,
            'default_locale'  => $project->defaultLocale,
            'locales'         => $project->locales,
            'settings'        => null,
            'public_api'      => $project->publicApi,
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

        // Enrich relation options with the target collection's slug so the
        // frontend can build API URLs without resolving integer IDs itself.
        if ($field->type === 'relation' && isset($options['relation']['collection'])) {
            $targetColl = $this->collectionRepository->find((int) $options['relation']['collection']);
            if ($targetColl) {
                $options['relation']['collection_slug'] = $targetColl->slug;
            }
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
            'id'         => $entry->id,
            'uuid'       => $entry->uuid?->toRfc4122(),
            'status'     => $entry->status,
            'locale'     => $entry->locale,
            'created_at' => $entry->createdAt?->format(\DateTimeInterface::ATOM),
            'updated_at' => $entry->updatedAt?->format(\DateTimeInterface::ATOM),
            'deleted_at' => $entry->deletedAt?->format(\DateTimeInterface::ATOM),
            'creator'    => $entry->createdBy ? ['name' => $entry->createdBy->name ?? $entry->createdBy->email] : null,
            'updater'    => $entry->updatedBy ? ['name' => $entry->updatedBy->name ?? $entry->updatedBy->email] : null,
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
            'delete_project'               => $canProject('project.manage'),
            'manage_users'                 => $canSystem('users.manage'),
            'manage_roles'                 => $canSystem('roles.manage'),
        ];
    }
}
