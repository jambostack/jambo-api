<?php

namespace App\Controller;

use App\Entity\EndUserField;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenChecker;
use App\Service\EndUserJwtService;
use App\Service\EndUserSchemaSeeder;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects', name: 'api_project_')]
class ProjectController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const MAX_MEMBERS_LIMIT = 500;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private ProjectMemberRepository $memberRepo,
        private RoleRepository $roleRepository,
        private EndUserSchemaSeeder $endUserSchemaSeeder,
        private ApiTokenChecker $tokenChecker,
        private WebhookSecretService $secretService,
        private TranslatorInterface $translator,
        #[Autowire(service: 'limiter.project_limiter')]
        private readonly ?RateLimiterFactory $projectLimiter = null,
    ) {}

    private ?ProjectMember $currentMember = null;

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

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return $this->secureJson(['error' => $this->trans('project.access_denied', [], 'Access denied.')], 401);
        }
        $projects = $this->projectRepository->findByMember($user);

        return $this->secureJson([
            'data' => array_map(fn ($p) => $this->serialize($p), $projects),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($this->projectLimiter !== null) {
            $identifier = ($this->getUser()?->getUserIdentifier() ?? $request->getClientIp()) ?: '127.0.0.1';
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('project.rate_limit_exceeded', [], 'Too many project requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson(['error' => $this->trans('project.access_denied', [], 'Access denied.')], 401);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project.payload_too_large', [], 'Project payload exceeds maximum allowed size (64 KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON body.')], 400);
        }

        $rawName = isset($data['name']) ? trim((string) $data['name']) : '';
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $rawName);
        if ($name === '') {
            return $this->secureJson(['error' => $this->trans('project.name_required', [], 'name is required.')], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->secureJson(['error' => $this->trans('project.name_too_long', [], 'Project name cannot exceed 255 characters.')], 422);
        }

        $description = null;
        if (isset($data['description'])) {
            $descClean = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $data['description']));
            $description = mb_strlen($descClean) > 2000 ? mb_substr($descClean, 0, 2000) : $descClean;
        }

        $defaultLocale = (string) ($data['default_locale'] ?? 'en');
        if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $defaultLocale) || strlen($defaultLocale) > 10) {
            return $this->secureJson(['error' => $this->trans('project.invalid_default_locale', [], 'Invalid default locale format.')], 422);
        }

        $locales = [$defaultLocale];
        if (isset($data['locales'])) {
            if (!is_array($data['locales']) || count($data['locales']) > 50) {
                return $this->secureJson(['error' => $this->trans('project.invalid_locales_list', [], 'Locales list must be a valid array of locale codes.')], 422);
            }
            $cleanLocales = [];
            foreach ($data['locales'] as $loc) {
                if (is_string($loc) && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', trim($loc))) {
                    $cleanLocales[] = trim($loc);
                }
            }
            $locales = !empty($cleanLocales) ? array_values(array_unique($cleanLocales)) : [$defaultLocale];
        }

        $disk = (string) ($data['disk'] ?? 'public');
        if (!in_array($disk, ['public', 'local', 's3', 'r2', 'spaces', 'minio', 'azure', 'gcs'], true)) {
            return $this->secureJson(['error' => $this->trans('project.invalid_disk', [], 'Invalid storage disk specified.')], 422);
        }

        $project = new Project();
        $project->name          = $name;
        $project->description   = $description;
        $project->defaultLocale = $defaultLocale;
        $project->locales       = $locales;
        $project->disk          = $disk;
        $project->publicApi     = (bool) ($data['public_api'] ?? false);

        $this->em->persist($project);

        // Add creator as active member
        $creator           = new ProjectMember();
        $creator->project  = $project;
        $creator->user     = $user;
        $creator->email    = $user->email;
        $creator->status   = ProjectMemberStatus::Active;
        $creator->joinedAt = new \DateTimeImmutable();
        $this->em->persist($creator);

        $this->endUserSchemaSeeder->seed($project);

        $this->em->flush();

        return $this->secureJson(['data' => $this->serialize($project)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return $this->secureJson(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $uuid, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && !in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->secureJson(['error' => $this->trans('project.permission_denied', [], 'You do not have permission to manage this project.')], 403);
            }
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project.payload_too_large', [], 'Project payload exceeds maximum allowed size (64 KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON body.')], 400);
        }

        if (isset($data['name'])) {
            $rawName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $data['name']));
            if ($rawName === '') {
                return $this->secureJson(['error' => $this->trans('project.name_empty', [], 'name cannot be empty.')], 422);
            }
            if (mb_strlen($rawName) > 255) {
                return $this->secureJson(['error' => $this->trans('project.name_too_long', [], 'Project name cannot exceed 255 characters.')], 422);
            }
            $project->name = $rawName;
        }
        if (array_key_exists('description', $data)) {
            $descClean = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $data['description']));
            $project->description = mb_strlen($descClean) > 2000 ? mb_substr($descClean, 0, 2000) : $descClean;
        }
        if (isset($data['default_locale'])) {
            $loc = (string) $data['default_locale'];
            if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $loc) || strlen($loc) > 10) {
                return $this->secureJson(['error' => $this->trans('project.invalid_default_locale', [], 'Invalid default locale format.')], 422);
            }
            $project->defaultLocale = $loc;
        }
        if (isset($data['locales'])) {
            if (!is_array($data['locales']) || count($data['locales']) > 50) {
                return $this->secureJson(['error' => $this->trans('project.invalid_locales_list', [], 'Locales list must be a valid array of locale codes.')], 422);
            }
            $cleanLocales = [];
            foreach ($data['locales'] as $l) {
                if (is_string($l) && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', trim($l))) {
                    $cleanLocales[] = trim($l);
                }
            }
            $project->locales = !empty($cleanLocales) ? array_values(array_unique($cleanLocales)) : [$project->defaultLocale];
        }
        if (isset($data['disk'])) {
            $disk = (string) $data['disk'];
            if (!in_array($disk, ['public', 'local', 's3', 'r2', 'spaces', 'minio', 'azure', 'gcs'], true)) {
                return $this->secureJson(['error' => $this->trans('project.invalid_disk', [], 'Invalid storage disk specified.')], 422);
            }
            $project->disk = $disk;
        }
        if (isset($data['public_api'])) {
            $project->publicApi = (bool) $data['public_api'];
        }
        if (array_key_exists('preview_url', $data) || array_key_exists('previewUrl', $data)) {
            $pUrl = $data['preview_url'] ?? $data['previewUrl'];
            if ($pUrl === null || $pUrl === '') {
                $project->previewUrl = null;
            } else {
                $pUrl = trim((string) $pUrl);
                if (strlen($pUrl) > 2048 || preg_match('/[\r\n\x00-\x1F\x7F]/', $pUrl) || !filter_var($pUrl, FILTER_VALIDATE_URL)) {
                    return $this->secureJson(['error' => $this->trans('project.preview_url_invalid', [], 'Invalid preview_url.')], 422);
                }
                $pScheme = strtolower((string) parse_url($pUrl, PHP_URL_SCHEME));
                if (!in_array($pScheme, ['http', 'https'], true)) {
                    return $this->secureJson(['error' => $this->trans('project.preview_url_scheme_invalid', [], 'Invalid preview_url scheme.')], 422);
                }
                $project->previewUrl = $pUrl;
            }
        }
        if (array_key_exists('preview_enabled', $data) || array_key_exists('previewEnabled', $data)) {
            $project->previewEnabled = (bool) ($data['preview_enabled'] ?? $data['previewEnabled']);
        }
        if (array_key_exists('preview_mode', $data) || array_key_exists('previewMode', $data)) {
            $mode = (string) ($data['preview_mode'] ?? $data['previewMode']);
            if (in_array($mode, ['toggle', 'side-by-side'], true)) {
                $project->previewMode = $mode;
            }
        }
        if (array_key_exists('jwt_access_ttl', $data)) {
            $val = $data['jwt_access_ttl'];
            if ($val === '' || $val === null || $val === '0' || $val === 0) {
                $project->jwtAccessTtl = null;
            } else {
                $ttl = (int) $val;
                if ($ttl < 60) {
                    return $this->secureJson(['error' => $this->trans('project.jwt_access_ttl_min', [], 'jwt_access_ttl must be at least 60 seconds.')], 422);
                }
                if ($ttl > EndUserJwtService::MAX_TTL) {
                    return $this->secureJson(['error' => sprintf($this->trans('project.jwt_access_ttl_max', [], 'jwt_access_ttl must not exceed %d seconds.'), EndUserJwtService::MAX_TTL)], 422);
                }
                $project->jwtAccessTtl = $ttl;
            }
        }
        if (array_key_exists('jwt_refresh_ttl', $data)) {
            $val = $data['jwt_refresh_ttl'];
            if ($val === '' || $val === null || $val === '0' || $val === 0) {
                $project->jwtRefreshTtl = null;
            } else {
                $ttl = (int) $val;
                if ($ttl < 60) {
                    return $this->secureJson(['error' => $this->trans('project.jwt_refresh_ttl_min', [], 'jwt_refresh_ttl must be at least 60 seconds.')], 422);
                }
                if ($ttl > EndUserJwtService::MAX_TTL) {
                    return $this->secureJson(['error' => sprintf($this->trans('project.jwt_refresh_ttl_max', [], 'jwt_refresh_ttl must not exceed %d seconds.'), EndUserJwtService::MAX_TTL)], 422);
                }
                $project->jwtRefreshTtl = $ttl;
            }
        }

        if (array_key_exists('security', $data) && is_array($data['security'])) {
            $current = ($project->settings ?? [])['security'] ?? [];
            $incoming = $data['security'];

            if (array_key_exists('endUserTwoFactor', $incoming)) {
                $current['endUserTwoFactor'] = (bool) $incoming['endUserTwoFactor'];
            }
            if (array_key_exists('endUserTwoFactorMethods', $incoming)) {
                $current['endUserTwoFactorMethods'] = (array) $incoming['endUserTwoFactorMethods'];
            }
            if (array_key_exists('endUserSocialLogin', $incoming)) {
                $current['endUserSocialLogin'] = (bool) $incoming['endUserSocialLogin'];
            }
            if (array_key_exists('endUserSocialProviders', $incoming) && is_array($incoming['endUserSocialProviders'])) {
                $current['endUserSocialProviders'] = $current['endUserSocialProviders'] ?? [];
                foreach (['google', 'microsoft', 'github', 'gitlab'] as $provider) {
                    if (!array_key_exists($provider, $incoming['endUserSocialProviders'])) {
                        continue;
                    }
                    $p = $incoming['endUserSocialProviders'][$provider];
                    if (array_key_exists('enabled', $p)) {
                        $current['endUserSocialProviders'][$provider]['enabled'] = (bool) $p['enabled'];
                    }
                    if (array_key_exists('clientId', $p)) {
                        $current['endUserSocialProviders'][$provider]['clientId'] = trim((string) $p['clientId']) ?: null;
                    }
                    if (array_key_exists('clientSecret', $p)) {
                        $val = trim((string) $p['clientSecret']);
                        $current['endUserSocialProviders'][$provider]['clientSecret'] = $val !== ''
                            ? 'enc:' . $this->secretService->encrypt($val)
                            : null;
                    }
                }
            }

            $project->settings = array_merge($project->settings ?? [], ['security' => $current]);
        }

        // Cross-check: refresh TTL must be >= access TTL
        $finalAccess  = EndUserJwtService::resolveTtl($project->jwtAccessTtl, EndUserJwtService::DEFAULT_ACCESS_TTL);
        $finalRefresh = EndUserJwtService::resolveTtl($project->jwtRefreshTtl, EndUserJwtService::DEFAULT_REFRESH_TTL);
        if ($finalRefresh < $finalAccess) {
            return $this->secureJson([
                'error' => sprintf(
                    $this->trans('project.jwt_refresh_lt_access', [], 'jwt_refresh_ttl (%d s) must be >= jwt_access_ttl (%d s).'),
                    $finalRefresh, $finalAccess,
                ),
            ], 422);
        }

        $this->em->flush();

        // Inertia form submissions expect a redirect on success
        if ($request->headers->has('X-Inertia')) {
            return $this->redirect('/projects/' . $project->id . '/settings/project', 303);
        }

        return $this->secureJson(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && !in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->secureJson(['error' => $this->trans('project.permission_denied', [], 'You do not have permission to manage this project.')], 403);
            }
        }

        $this->em->remove($project);
        $this->em->flush();

        if ($request->headers->has('X-Inertia')) {
            return $this->redirect('/', 303);
        }

        return new Response(null, 204, self::SECURITY_HEADERS);
    }

    #[Route('/{uuid}/clone', name: 'clone', methods: ['POST'])]
    public function cloneProject(string $uuid, Request $request): JsonResponse
    {
        if ($this->projectLimiter !== null) {
            $identifier = ($this->getUser()?->getUserIdentifier() ?? $request->getClientIp()) ?: '127.0.0.1';
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('project.rate_limit_exceeded', [], 'Too many project requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->secureJson(['error' => $this->trans('project.access_denied', [], 'Access denied.')], 401);
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        $source = $this->resolveProject($uuid);
        if ($source instanceof JsonResponse) {
            return $source;
        }

        if (!in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->secureJson(['error' => $this->trans('project.permission_denied', [], 'You do not have permission to manage this project.')], 403);
            }
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project.payload_too_large', [], 'Project payload exceeds maximum allowed size (64 KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON body.')], 400);
        }

        $rawCloneName = isset($data['name']) ? trim((string) $data['name']) : ($source->name . ' (Copy)');
        $cloneName = preg_replace('/[\x00-\x1F\x7F]/', '', $rawCloneName);
        if ($cloneName === '') {
            return $this->secureJson(['error' => $this->trans('project.name_empty', [], 'name cannot be empty.')], 422);
        }
        if (mb_strlen($cloneName) > 255) {
            return $this->secureJson(['error' => $this->trans('project.name_too_long', [], 'Project name cannot exceed 255 characters.')], 422);
        }

        $clone = new Project();
        $clone->name          = $cloneName;
        $clone->description   = $source->description;
        $clone->defaultLocale = $source->defaultLocale;
        $clone->locales       = $source->locales;
        $clone->disk          = $source->disk;
        $clone->publicApi     = false;

        // Clone collections and fields (without content)
        foreach ($source->collections as $sourceCollection) {
            if ($sourceCollection->isDeleted()) {
                continue;
            }
            $newCollection = new \App\Entity\Collection();
            $newCollection->name        = $sourceCollection->name;
            $newCollection->slug        = $sourceCollection->slug;
            $newCollection->description = $sourceCollection->description;
            $newCollection->isSingleton = $sourceCollection->isSingleton;
            $newCollection->order       = $sourceCollection->order;
            $newCollection->project     = $clone;

            foreach ($sourceCollection->fields as $sourceField) {
                if ($sourceField->isDeleted()) {
                    continue;
                }
                $newField = new \App\Entity\Field();
                $newField->name       = $sourceField->name;
                $newField->slug       = $sourceField->slug;
                $newField->type       = $sourceField->type;
                $newField->options    = $sourceField->options;
                $newField->order      = $sourceField->order;
                $newField->isRequired = $sourceField->isRequired;
                $newField->collection = $newCollection;
                $this->em->persist($newField);
            }

            $this->em->persist($newCollection);
        }

        $this->em->persist($clone);

        // Add cloner as active member
        $cloner           = new ProjectMember();
        $cloner->project  = $clone;
        $cloner->user     = $currentUser;
        $cloner->email    = $currentUser->email;
        $cloner->status   = ProjectMemberStatus::Active;
        $cloner->joinedAt = new \DateTimeImmutable();
        $this->em->persist($cloner);

        // Clone EndUser custom fields (not system ones)
        $sourceEndUserFields = $this->em->getRepository(EndUserField::class)->findBy(['project' => $source]);
        $this->endUserSchemaSeeder->seed($clone);
        foreach ($sourceEndUserFields as $srcField) {
            if ($srcField->isSystem) {
                continue;
            }
            $newField            = new EndUserField();
            $newField->project   = $clone;
            $newField->name      = $srcField->name;
            $newField->slug      = $srcField->slug;
            $newField->type      = $srcField->type;
            $newField->options   = $srcField->options;
            $newField->order     = $srcField->order;
            $newField->isRequired = $srcField->isRequired;
            $newField->isSystem  = false;
            $this->em->persist($newField);
        }

        $this->em->flush();

        return $this->secureJson(['data' => $this->serialize($clone)], 201);
    }

    #[Route('/{uuid}/members', name: 'members_add', methods: ['POST'])]
    public function addMember(string $uuid, Request $request): JsonResponse
    {
        if ($this->projectLimiter !== null) {
            $identifier = ($this->getUser()?->getUserIdentifier() ?? $request->getClientIp()) ?: '127.0.0.1';
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('project.rate_limit_exceeded', [], 'Too many project requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->secureJson(['error' => $this->trans('project.permission_denied_members', [], 'You do not have permission to manage members.')], 403);
            }
        }

        if ($this->memberRepo->count(['project' => $project]) >= self::MAX_MEMBERS_LIMIT) {
            return $this->secureJson(['error' => $this->trans('project.max_members_reached', [], 'Maximum member limit reached for this project (500 max).')], 422);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project.payload_too_large', [], 'Project payload exceeds maximum allowed size (64 KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json', [], 'Invalid JSON body.')], 400);
        }
        $roleId = $data['role_id'] ?? null;
        $role   = null;
        if ($roleId !== null && $roleId !== '') {
            $role = $this->roleRepository->find((int) $roleId);
            if ($role === null) {
                return $this->secureJson(['error' => $this->trans('project.role_not_found')], 404);
            }
        }

        if (isset($data['user_id']) && $data['user_id'] !== '' && $data['user_id'] !== null) {
            $user = $this->userRepository->find((int) $data['user_id']);
            if ($user === null) {
                return $this->secureJson(['error' => $this->trans('project.user_not_found')], 404);
            }
            $existing = $this->memberRepo->findOneBy(['project' => $project, 'user' => $user]);
            if ($existing !== null) {
                return $this->secureJson(['error' => $this->trans('project.user_already_member')], 409);
            }
            $member           = new ProjectMember();
            $member->project  = $project;
            $member->user     = $user;
            $member->email    = $user->email;
            $member->role     = $role;
            $member->status   = ProjectMemberStatus::Active;
            $member->invitedBy = $currentUser;
            $member->joinedAt = new \DateTimeImmutable();
            $this->em->persist($member);
            $this->em->flush();
            return $this->secureJson(['data' => $this->serializeMember($member)], 201);
        }

        if (!empty($data['email']) && is_string($data['email'])) {
            $email = trim($data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->secureJson(['error' => $this->trans('project.email_invalid')], 422);
            }
            $existing = $this->memberRepo->findOneBy(['project' => $project, 'email' => $email]);
            if ($existing !== null) {
                return $this->secureJson(['error' => $this->trans('project.email_already_member')], 409);
            }
            $token                   = bin2hex(random_bytes(32));
            $member                  = new ProjectMember();
            $member->project         = $project;
            $member->user            = null;
            $member->email           = $email;
            $member->role            = $role;
            $member->status          = ProjectMemberStatus::Pending;
            $member->invitedBy       = $currentUser;
            $member->invitationToken = $token;
            $member->tokenExpiresAt  = new \DateTimeImmutable('+72 hours');
            $this->em->persist($member);
            $this->em->flush();
            return $this->secureJson(['data' => $this->serializeMember($member)], 201);
        }

        return $this->secureJson(['error' => $this->trans('project.user_id_or_email_required')], 422);
    }

    #[Route('/{uuid}/members/{memberId}', name: 'members_remove', methods: ['DELETE'])]
    public function removeMember(string $uuid, int $memberId): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found')], 404);
        }

        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->secureJson(['error' => $this->trans('project.permission_denied_members')], 403);
            }
        }

        $member = $this->memberRepo->findOneBy(['id' => $memberId, 'project' => $project]);
        if ($member === null) {
            return $this->secureJson(['error' => $this->trans('project.member_not_found')], 404);
        }

        if ($member->status === ProjectMemberStatus::Active && $this->memberRepo->countActiveByProject($project) <= 1) {
            return $this->secureJson(['error' => $this->trans('project.last_active_member')], 422);
        }

        $soleManage = $this->memberRepo->findSoleManagePermissionMember($project);
        if ($soleManage !== null && $soleManage->id === $member->id) {
            return $this->secureJson(['error' => $this->trans('project.last_manage_member')], 422);
        }

        $this->em->remove($member);
        $this->em->flush();
        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
    }

    #[Route('/{uuid}/members/{memberId}/role', name: 'members_update_role', methods: ['PATCH'])]
    public function updateMemberRole(string $uuid, int $memberId, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->secureJson(['error' => $this->trans('project.permission_denied_members')], 403);
            }
        }

        $member = $this->memberRepo->findOneBy(['id' => $memberId, 'project' => $project]);
        if ($member === null) {
            return $this->secureJson(['error' => $this->trans('project.member_not_found')], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json')], 400);
        }
        $roleId       = $data['role_id'] ?? null;
        $member->role = $roleId ? $this->roleRepository->find((int) $roleId) : null;
        $this->em->flush();
        return $this->secureJson(['data' => $this->serializeMember($member)]);
    }

    public function serializeMember(ProjectMember $member): array
    {
        return [
            'id'         => $member->id,
            'user'       => $member->user ? [
                'id'    => $member->user->id,
                'name'  => $member->user->name,
                'email' => $member->user->email,
            ] : null,
            'role'       => $member->role ? [
                'id'    => $member->role->id,
                'name'  => $member->role->name,
                'label' => $member->role->label,
            ] : null,
            'email'      => $member->email,
            'status'     => $member->status->value,
            'joined_at'  => $member->joinedAt?->format(\DateTimeInterface::ATOM),
            'created_at' => $member->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveProject(string $uuid, ?Request $request = null): Project|JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }
        if ($project === null) {
            return $this->secureJson(['error' => $this->trans('project.not_found', [], 'Project not found.')], 404);
        }

        // Session auth (admin UI)
        $user = $this->getUser();
        if ($user !== null) {
            if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
                if ($member === null) {
                    return $this->secureJson(['error' => $this->trans('project.access_denied', [], 'Access denied.')], 403);
                }
                $this->currentMember = $member;
            }
            return $project;
        }

        // ApiToken auth (CRM apps)
        if ($request !== null) {
            $token = $this->tokenChecker->resolve($request);
            if ($token !== null && $token->project->uuid?->toString() === $uuid && $token->can('create')) {
                return $project;
            }
        }

        return $this->secureJson(['error' => $this->trans('project.access_denied', [], 'Access denied.')], 403);
    }


    private function serialize(Project $project): array
    {
        $security = ($project->settings ?? [])['security'] ?? [];

        // Ne jamais exposer les secrets — on filtre pour le frontend
        $socialProviders = [];
        foreach (($security['endUserSocialProviders'] ?? []) as $p => $cfg) {
            $socialProviders[$p] = [
                'enabled'    => (bool) ($cfg['enabled'] ?? false),
                'configured' => !empty($cfg['clientId']) && !empty($cfg['clientSecret']),
            ];
        }

        return [
            'uuid'             => $project->uuid?->toString(),
            'name'             => $project->name,
            'description'      => $project->description,
            'defaultLocale'    => $project->defaultLocale,
            'locales'          => $project->locales,
            'disk'             => $project->disk,
            'publicApi'        => $project->publicApi,
            'jwt_access_ttl'   => $project->jwtAccessTtl,
            'jwt_refresh_ttl'  => $project->jwtRefreshTtl,
            'collectionsCount' => $project->collections->count(),
            'membersCount'     => $project->projectMembers->count(),
            'security'         => [
                'endUserTwoFactor'        => $security['endUserTwoFactor'] ?? false,
                'endUserTwoFactorMethods' => $security['endUserTwoFactorMethods'] ?? ['totp', 'email'],
                'endUserSocialLogin'      => $security['endUserSocialLogin'] ?? false,
                'endUserSocialProviders'  => $socialProviders,
            ],
        ];
    }
}
