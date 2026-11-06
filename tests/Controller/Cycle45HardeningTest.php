<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\CollectionTemplateController;
use App\Controller\DeploymentController;
use App\Controller\PublicShareController;
use App\Entity\Collection;
use App\Entity\CollectionTemplate;
use App\Entity\ContentEntry;
use App\Entity\Deployment;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\CollectionTemplateRepository;
use App\Repository\DeploymentRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\EavDataFormatterService;
use App\Service\Security\SsrfProtectionService;
use App\Service\Share\ShareService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle45HardeningTest extends TestCase
{
    private function createContainerWithUser(?User $user = null, bool $isAdmin = true): Container
    {
        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }
        $container->set('security.token_storage', $tokenStorage);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn($isAdmin);
        $container->set('security.authorization_checker', $authChecker);

        return $container;
    }

    private function createTranslatorMock(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            return $id;
        });
        return $translator;
    }

    // =========================================================================
    // 1. DeploymentController Tests
    // =========================================================================

    public function testDeploymentSystemInfoIncludesSecurityHeaders(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $controller = new DeploymentController($em, $deployments, $projects);
        $paramBag = new \Symfony\Component\DependencyInjection\ParameterBag\ParameterBag([
            'kernel.project_dir' => sys_get_temp_dir(),
        ]);
        $container = new Container($paramBag);
        $controller->setContainer($container);

        $response = $controller->systemInfo();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testDeploymentIndexRequiresAuthAndAdmin(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $controller = new DeploymentController($em, $deployments, $projects);

        // Unauthenticated
        $controller->setContainer($this->createContainerWithUser(null, false));
        $respUnauth = $controller->index(new Request());
        $this->assertSame(401, $respUnauth->getStatusCode());
        $this->assertTrue($respUnauth->headers->hasCacheControlDirective('no-store'));

        // Non-admin user
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user, false));
        $respNonAdmin = $controller->index(new Request());
        $this->assertSame(403, $respNonAdmin->getStatusCode());
        $this->assertTrue($respNonAdmin->headers->hasCacheControlDirective('no-store'));
    }

    public function testDeploymentHookRejectsDisabledSecret(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        unset($_ENV['DEPLOY_WEBHOOK_SECRET']);

        $controller = new DeploymentController($em, $deployments, $projects);
        $response = $controller->hook(new Request([], [], [], [], [], [], '{}'));
        $this->assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('WEBHOOK_DISABLED', $data['code']);
    }

    public function testDeploymentHookRejectsInvalidSignature(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $_ENV['DEPLOY_WEBHOOK_SECRET'] = 'secret-deployment-key-32-chars!!';

        $controller = new DeploymentController($em, $deployments, $projects);

        // Missing signature prefix
        $reqNoSig = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => 'md5=invalid'], '{}');
        $respNoSig = $controller->hook($reqNoSig);
        $this->assertSame(401, $respNoSig->getStatusCode());
        $this->assertSame('SIGNATURE_MISSING', json_decode((string) $respNoSig->getContent(), true)['code']);

        // Invalid signature hash (constant time comparison test)
        $reqBadSig = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => 'sha256=' . str_repeat('0', 64)], '{}');
        $respBadSig = $controller->hook($reqBadSig);
        $this->assertSame(401, $respBadSig->getStatusCode());
        $this->assertSame('SIGNATURE_INVALID', json_decode((string) $respBadSig->getContent(), true)['code']);
    }

    public function testDeploymentHookRejectsInvalidJsonPayload(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $secret = 'secret-deployment-key-32-chars!!';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;

        $controller = new DeploymentController($em, $deployments, $projects);

        $payload = '{not valid json...';
        $sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $req = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sig], $payload);

        $response = $controller->hook($req);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_JSON', json_decode((string) $response->getContent(), true)['code']);
    }

    public function testDeploymentHookRejectsInvalidBranchNames(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $secret = 'secret-deployment-key-32-chars!!';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;

        $controller = new DeploymentController($em, $deployments, $projects);

        $invalidBranches = [
            'feat/branch\r\ninjection',
            'branch..traversal',
            'branch//double-slash',
            '/leading-slash',
            'trailing-slash/',
            '.leading-dot',
            'trailing-dot.',
            'ref~tilde',
            'ref^caret',
            'ref:colon',
            'ref?question',
            'ref*asterisk',
            'ref[bracket',
            'ref\\backslash',
            'ref@{upstream}',
            str_repeat('a', 101), // over 100 chars
        ];

        foreach ($invalidBranches as $badBranch) {
            $payload = json_encode(['branch' => $badBranch]);
            $sig = 'sha256=' . hash_hmac('sha256', (string) $payload, $secret);
            $req = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sig], (string) $payload);

            $response = $controller->hook($req);
            $this->assertSame(422, $response->getStatusCode(), "Branch '{$badBranch}' should be rejected");
            $this->assertSame('INVALID_BRANCH', json_decode((string) $response->getContent(), true)['code']);
        }
    }

    public function testDeploymentHookAcceptsValidBranchNames(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);
        $auditService = $this->createMock(AuditService::class);

        $secret = 'secret-deployment-key-32-chars!!';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;

        $controller = new DeploymentController($em, $deployments, $projects, null, null, null, $auditService);

        $validBranches = [
            'main',
            'feature/user-auth_v2',
            'release/1.26.100',
            'patch-hotfix.1',
        ];

        foreach ($validBranches as $goodBranch) {
            $payload = json_encode([
                'branch' => $goodBranch,
                'commit_sha' => '43fd50de9fc627ce480a09a92fc98a085c341adb',
                'status' => 'succeeded',
                'environment' => 'production',
            ]);
            $sig = 'sha256=' . hash_hmac('sha256', (string) $payload, $secret);
            $req = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sig], (string) $payload);

            $response = $controller->hook($req);
            $this->assertSame(201, $response->getStatusCode(), "Branch '{$goodBranch}' should be accepted");
        }
    }

    public function testDeploymentHookRejectsOversizedUrlAndNonOciImage(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $secret = 'secret-deployment-key-32-chars!!';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;

        $controller = new DeploymentController($em, $deployments, $projects);

        // Preview URL > 500 chars
        $longUrl = 'https://example.com/' . str_repeat('x', 500);
        $payloadUrl = json_encode(['preview_url' => $longUrl]);
        $sigUrl = 'sha256=' . hash_hmac('sha256', (string) $payloadUrl, $secret);
        $reqUrl = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sigUrl], (string) $payloadUrl);
        $respUrl = $controller->hook($reqUrl);
        $this->assertSame(422, $respUrl->getStatusCode());
        $this->assertSame('INVALID_PREVIEW_URL', json_decode((string) $respUrl->getContent(), true)['code']);

        // Run URL > 500 chars
        $payloadRun = json_encode(['run_url' => $longUrl]);
        $sigRun = 'sha256=' . hash_hmac('sha256', (string) $payloadRun, $secret);
        $reqRun = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sigRun], (string) $payloadRun);
        $respRun = $controller->hook($reqRun);
        $this->assertSame(422, $respRun->getStatusCode());
        $this->assertSame('INVALID_RUN_URL', json_decode((string) $respRun->getContent(), true)['code']);

        // Malicious image reference with injection characters
        $payloadImg = json_encode(['image' => 'docker.io/library/image; rm -rf /']);
        $sigImg = 'sha256=' . hash_hmac('sha256', (string) $payloadImg, $secret);
        $reqImg = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sigImg], (string) $payloadImg);
        $respImg = $controller->hook($reqImg);
        $this->assertSame(422, $respImg->getStatusCode());
        $this->assertSame('INVALID_IMAGE_REFERENCE', json_decode((string) $respImg->getContent(), true)['code']);
    }

    // =========================================================================
    // 2. PublicShareController Tests
    // =========================================================================

    public function testPublicShareRejectsUnsupportedFormat(): void
    {
        $shareService = $this->createMock(ShareService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);

        $controller = new PublicShareController($shareService, $formatter);
        $response = $controller->show('jbo_share_abcdef1234567890', 'xml', new Request());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testPublicShareRejectsInvalidTokenCharactersAndBounds(): void
    {
        $shareService = $this->createMock(ShareService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);

        $controller = new PublicShareController($shareService, $formatter);

        // Too short (< 8 chars)
        $respShort = $controller->show('short', 'json', new Request());
        $this->assertSame(404, $respShort->getStatusCode());
        $this->assertSame('invalid', json_decode((string) $respShort->getContent(), true)['error']);

        // Too long (> 128 chars)
        $respLong = $controller->show(str_repeat('a', 129), 'json', new Request());
        $this->assertSame(404, $respLong->getStatusCode());

        // Illegal characters (CRLF, spaces, quotes, etc.)
        $respChars = $controller->show("token_with_\r\n_crlf", 'json', new Request());
        $this->assertSame(404, $respChars->getStatusCode());
    }

    public function testPublicShareEnumerationProtectionReturnsConsistent404(): void
    {
        $shareService = $this->createMock(ShareService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);

        $controller = new PublicShareController($shareService, $formatter);

        // 1. Non-existent share
        $shareService->method('resolve')->willReturn(null);
        $respNotFound = $controller->show('jbo_share_0000000000000000', 'json', new Request());
        $this->assertSame(404, $respNotFound->getStatusCode());
        $this->assertSame('invalid', json_decode((string) $respNotFound->getContent(), true)['error']);

        // 2. Revoked share
        $revokedShare = new Share();
        $revokedShare->revokedAt = new \DateTimeImmutable();
        $shareService2 = $this->createMock(ShareService::class);
        $shareService2->method('resolve')->willReturn($revokedShare);
        $controller2 = new PublicShareController($shareService2, $formatter);
        $respRevoked = $controller2->show('jbo_share_1111111111111111', 'json', new Request());
        $this->assertSame(404, $respRevoked->getStatusCode());
        $this->assertSame('invalid', json_decode((string) $respRevoked->getContent(), true)['error']);

        // 3. Share with deleted entry
        $deletedEntry = new ContentEntry();
        $deletedEntry->deletedAt = new \DateTimeImmutable();
        $shareWithDeleted = new Share();
        $shareWithDeleted->entry = $deletedEntry;
        $shareService3 = $this->createMock(ShareService::class);
        $shareService3->method('resolve')->willReturn($shareWithDeleted);
        $controller3 = new PublicShareController($shareService3, $formatter);
        $respDeleted = $controller3->show('jbo_share_2222222222222222', 'json', new Request());
        $this->assertSame(404, $respDeleted->getStatusCode());
        $this->assertSame('invalid', json_decode((string) $respDeleted->getContent(), true)['error']);
    }

    public function testPublicShareSetsStrictSecurityHeadersOnSuccess(): void
    {
        $shareService = $this->createMock(ShareService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);

        $project = new Project();
        $project->id = 42;

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;

        $share = new Share();
        $share->project = $project;
        $share->entry = $entry;

        $shareService->method('resolve')->willReturn($share);
        $formatter->method('formatEntry')->willReturn(['id' => 1, 'title' => 'Protected Share']);

        $controller = new PublicShareController($shareService, $formatter);
        $response = $controller->show('jbo_share_valid_token_12345678', 'json', new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    // =========================================================================
    // 3. CollectionTemplateController Tests
    // =========================================================================

    public function testCollectionTemplateIndexAndShowSecurityHeaders(): void
    {
        $templateRepo = $this->createMock(CollectionTemplateRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $template = new CollectionTemplate();
        $template->id = 1;
        $template->name = 'News';
        $templateRepo->method('findBy')->willReturn([$template]);
        $templateRepo->method('find')->willReturn($template);

        $controller = new CollectionTemplateController($templateRepo, $collectionRepo, $projectRepo, $em);

        $indexResp = $controller->index();
        $this->assertSame(200, $indexResp->getStatusCode());
        $this->assertTrue($indexResp->headers->hasCacheControlDirective('no-store'));

        $showResp = $controller->show(1);
        $this->assertSame(200, $showResp->getStatusCode());
        $this->assertTrue($showResp->headers->hasCacheControlDirective('no-store'));

        // Invalid show id
        $notFoundResp = $controller->show(0);
        $this->assertSame(404, $notFoundResp->getStatusCode());
    }

    public function testCollectionTemplateSaveFromCollectionRequiresAdminPrivileges(): void
    {
        $templateRepo = $this->createMock(CollectionTemplateRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CollectionTemplateController($templateRepo, $collectionRepo, $projectRepo, $em);

        $uuid = '12345678-1234-1234-1234-123456789abc';

        // 1. Unauthenticated -> 401
        $controller->setContainer($this->createContainerWithUser(null, false));
        $respUnauth = $controller->saveFromCollection($uuid, 'articles', new Request());
        $this->assertSame(401, $respUnauth->getStatusCode());

        // 2. Authenticated but non-admin -> AccessDeniedException (403)
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user, false));
        $this->expectException(AccessDeniedException::class);
        $controller->saveFromCollection($uuid, 'articles', new Request());
    }

    public function testCollectionTemplateEnforcesMaxQuotaLimit(): void
    {
        $templateRepo = $this->createMock(CollectionTemplateRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        // When templates count equals MAX_TEMPLATES_COUNT (200)
        $templateRepo->method('count')->willReturn(200);

        $controller = new CollectionTemplateController($templateRepo, $collectionRepo, $projectRepo, $em);
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user, true));

        // Create fails with 422
        $reqCreate = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Over Quota']));
        $respCreate = $controller->create($reqCreate);
        $this->assertSame(422, $respCreate->getStatusCode());
        $this->assertSame('MAX_TEMPLATES_EXCEEDED', json_decode((string) $respCreate->getContent(), true)['code']);

        // Save from collection fails with 422
        $uuid = '12345678-1234-1234-1234-123456789abc';
        $respSave = $controller->saveFromCollection($uuid, 'articles', new Request());
        $this->assertSame(422, $respSave->getStatusCode());
        $this->assertSame('MAX_TEMPLATES_EXCEEDED', json_decode((string) $respSave->getContent(), true)['code']);
    }

    public function testCollectionTemplateRejectsUnsupportedFieldTypesAndHugeOptions(): void
    {
        $templateRepo = $this->createMock(CollectionTemplateRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $templateRepo->method('count')->willReturn(5);

        $controller = new CollectionTemplateController($templateRepo, $collectionRepo, $projectRepo, $em);
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user, true));

        // Unsupported field type: 'executable_script'
        $reqBadType = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Template with Malicious Type',
            'fields' => [
                ['name' => 'Exploit', 'slug' => 'exploit', 'type' => 'executable_script'],
            ],
        ]));
        $respBadType = $controller->create($reqBadType);
        $this->assertSame(422, $respBadType->getStatusCode());
        $this->assertSame('INVALID_FIELD_TYPE', json_decode((string) $respBadType->getContent(), true)['code']);

        // Huge options payload (> 32KB)
        $hugeOptions = ['data' => str_repeat('A', 35000)];
        $reqHugeOpts = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Template with Huge Options',
            'fields' => [
                ['name' => 'FieldWithHugeOptions', 'slug' => 'huge_opts', 'type' => 'text', 'options' => $hugeOptions],
            ],
        ]));
        $respHugeOpts = $controller->create($reqHugeOpts);
        $this->assertSame(422, $respHugeOpts->getStatusCode());
        $this->assertSame('OPTIONS_TOO_LARGE', json_decode((string) $respHugeOpts->getContent(), true)['code']);
    }

    public function testDeploymentHookSecretTooShort(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $_ENV['DEPLOY_WEBHOOK_SECRET'] = 'short-secret'; // < 16 chars

        $controller = new DeploymentController($em, $deployments, $projects);
        $response = $controller->hook(new Request([], [], [], [], [], [], '{}'));
        $this->assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('WEBHOOK_DISABLED', $data['code']);
    }

    public function testDeploymentHookRejectsInvalidEnvironmentAndStatus(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $secret = 'secret-deployment-key-32-chars!!';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;
        $controller = new DeploymentController($em, $deployments, $projects);

        // Invalid environment
        $payloadBadEnv = json_encode(['environment' => 'development_local']);
        $sigBadEnv = 'sha256=' . hash_hmac('sha256', (string) $payloadBadEnv, $secret);
        $reqBadEnv = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sigBadEnv], (string) $payloadBadEnv);
        $respBadEnv = $controller->hook($reqBadEnv);
        $this->assertSame(422, $respBadEnv->getStatusCode());
        $this->assertSame('INVALID_ENVIRONMENT', json_decode((string) $respBadEnv->getContent(), true)['code']);

        // Invalid status
        $payloadBadStatus = json_encode(['status' => 'exploded']);
        $sigBadStatus = 'sha256=' . hash_hmac('sha256', (string) $payloadBadStatus, $secret);
        $reqBadStatus = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sigBadStatus], (string) $payloadBadStatus);
        $respBadStatus = $controller->hook($reqBadStatus);
        $this->assertSame(422, $respBadStatus->getStatusCode());
        $this->assertSame('INVALID_STATUS', json_decode((string) $respBadStatus->getContent(), true)['code']);
    }

    public function testDeploymentHookRejectsMalformedProjectUuidAndNonExistentProject(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $secret = 'secret-deployment-key-32-chars!!';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;
        $controller = new DeploymentController($em, $deployments, $projects);

        // Malformed UUID
        $payloadBadUuid = json_encode(['project_uuid' => 'not-a-valid-uuid']);
        $sigBadUuid = 'sha256=' . hash_hmac('sha256', (string) $payloadBadUuid, $secret);
        $reqBadUuid = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sigBadUuid], (string) $payloadBadUuid);
        $respBadUuid = $controller->hook($reqBadUuid);
        $this->assertSame(422, $respBadUuid->getStatusCode());
        $this->assertSame('INVALID_PROJECT_UUID', json_decode((string) $respBadUuid->getContent(), true)['code']);

        // Non-existent UUID
        $uuid = '00000000-0000-0000-0000-000000000000';
        $projects->expects($this->once())->method('findOneBy')->with(['uuid' => $uuid])->willReturn(null);
        $payloadMissing = json_encode(['project_uuid' => $uuid]);
        $sigMissing = 'sha256=' . hash_hmac('sha256', (string) $payloadMissing, $secret);
        $reqMissing = new Request([], [], [], [], [], ['HTTP_X_JAMBO_SIGNATURE' => $sigMissing], (string) $payloadMissing);
        $respMissing = $controller->hook($reqMissing);
        $this->assertSame(404, $respMissing->getStatusCode());
        $this->assertSame('PROJECT_NOT_FOUND', json_decode((string) $respMissing->getContent(), true)['code']);
    }

    public function testDeploymentHookSupportsHubSignatureHeader(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);
        $auditService = $this->createMock(AuditService::class);

        $secret = 'secret-deployment-key-32-chars!!';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;

        $controller = new DeploymentController($em, $deployments, $projects, null, null, null, $auditService);

        $payload = json_encode([
            'branch' => 'main',
            'commit_sha' => '43fd50de9fc627ce480a09a92fc98a085c341adb',
            'status' => 'succeeded',
            'environment' => 'production',
        ]);
        $sig = 'sha256=' . hash_hmac('sha256', (string) $payload, $secret);
        // Using X-Hub-Signature-256 instead of X-Jambo-Signature
        $req = new Request([], [], [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => $sig], (string) $payload);

        $response = $controller->hook($req);
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testDeploymentIndexFilterByProject(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);

        $project = new Project();
        $project->id = 10;
        $uuid = '11111111-2222-3333-4444-555555555555';
        $projects->expects($this->once())->method('findOneBy')->with(['uuid' => $uuid])->willReturn($project);

        $d = new Deployment();
        $d->branch = 'feature-x';
        $deployments->expects($this->once())->method('findForProject')->with($project, 25)->willReturn([$d]);

        $controller = new DeploymentController($em, $deployments, $projects);
        $user = new User();
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($user, true));

        $request = new Request(['project' => $uuid]);
        $response = $controller->index($request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertCount(1, $data);
        $this->assertSame('feature-x', $data[0]['branch']);
    }

    public function testPublicShareIncludesSurrogateAndRobotsHeaders(): void
    {
        $shareService = $this->createMock(ShareService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);

        $controller = new PublicShareController($shareService, $formatter);
        $response = $controller->show('jbo_share_unknown_token', 'json', new Request());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('no-store', $response->headers->get('Surrogate-Control'));
        $this->assertSame('noindex, nofollow, noarchive, nosnippet', $response->headers->get('X-Robots-Tag'));
    }

    public function testCollectionTemplateRejectsMissingFieldNameOrSlug(): void
    {
        $templateRepo = $this->createMock(CollectionTemplateRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CollectionTemplateController($templateRepo, $collectionRepo, $projectRepo, $em);
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user, true));

        // Missing field name
        $reqNoName = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Template with empty field name',
            'fields' => [
                ['name' => '', 'slug' => 'valid_slug', 'type' => 'text'],
            ],
        ]));
        $respNoName = $controller->create($reqNoName);
        $this->assertSame(422, $respNoName->getStatusCode());
        $this->assertSame('FIELD_NAME_REQUIRED', json_decode((string) $respNoName->getContent(), true)['code']);

        // Missing field slug
        $reqNoSlug = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Template with empty field slug',
            'fields' => [
                ['name' => 'Valid Name', 'slug' => '', 'type' => 'text'],
            ],
        ]));
        $respNoSlug = $controller->create($reqNoSlug);
        $this->assertSame(422, $respNoSlug->getStatusCode());
        $this->assertSame('FIELD_SLUG_REQUIRED', json_decode((string) $respNoSlug->getContent(), true)['code']);

        // Invalid field slug
        $reqBadSlug = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Template with invalid field slug',
            'fields' => [
                ['name' => 'Valid Name', 'slug' => 'slug with spaces & symbols!', 'type' => 'text'],
            ],
        ]));
        $respBadSlug = $controller->create($reqBadSlug);
        $this->assertSame(422, $respBadSlug->getStatusCode());
        $this->assertSame('INVALID_FIELD_SLUG', json_decode((string) $respBadSlug->getContent(), true)['code']);
    }

    public function testCollectionTemplateRejectsSaveFromDeletedCollection(): void
    {
        $templateRepo = $this->createMock(CollectionTemplateRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $uuid = '22222222-3333-4444-5555-666666666666';
        $project = new Project();
        $project->id = 5;
        $projectRepo->expects($this->once())->method('findOneBy')->with(['uuid' => $uuid])->willReturn($project);

        $collection = new Collection();
        $collection->deletedAt = new \DateTimeImmutable();
        $collectionRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $controller = new CollectionTemplateController($templateRepo, $collectionRepo, $projectRepo, $em, $this->createTranslatorMock());
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user, true));

        $response = $controller->saveFromCollection($uuid, 'archived_collection', new Request());
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('template.collection_not_found', json_decode((string) $response->getContent(), true)['error']);
    }
}
