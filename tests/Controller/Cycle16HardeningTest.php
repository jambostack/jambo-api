<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\ProjectEmailController;
use App\Controller\DeploymentController;
use App\Controller\DirectUploadController;
use App\Controller\StudioController;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\AssetUploadIntentRepository;
use App\Repository\CollectionRepository;
use App\Repository\DeploymentRepository;
use App\Repository\EndUserRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\StudioChatMessageRepository;
use App\Service\AiContentService;
use App\Service\ApiTokenChecker;
use App\Service\DirectUploadService;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\FieldValueHydrator;
use App\Service\Form\AntiSpamService;
use App\Service\MediaSerializer;
use App\Service\ProjectMailerService;
use App\Service\Security\SsrfProtectionService;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class Cycle16HardeningTest extends TestCase
{
    private function createRejectingLimiterFactory(string $id = 'test'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create('127.0.0.1')->consume();
        return $factory;
    }

    private function createTestContainer(?User $user = null): Container
    {
        $container = new Container();
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        if ($user !== null) {
            $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }
        $container->set('security.token_storage', $tokenStorage);
        return $container;
    }

    public function testDirectUploadControllerRateLimitingReturns429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $intentRepo = $this->createMock(AssetUploadIntentRepository::class);
        $directUpload = $this->createMock(DirectUploadService::class);
        $serializer = $this->createMock(MediaSerializer::class);
        $limiter = $this->createRejectingLimiterFactory('direct_upload');

        $controller = new DirectUploadController(
            $projectRepo,
            $intentRepo,
            $directUpload,
            $serializer,
            $limiter,
        );
        $controller->setContainer($this->createTestContainer());

        $request = Request::create('/api/projects/some-uuid/media/direct-upload/intent', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response = $controller->createIntent('some-uuid', $request);
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Too many direct upload requests', $data['error']);
    }

    public function testDirectUploadControllerUnauthenticatedRejection(): void
    {
        $project = new Project();
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $intentRepo = $this->createMock(AssetUploadIntentRepository::class);
        $directUpload = $this->createMock(DirectUploadService::class);
        $serializer = $this->createMock(MediaSerializer::class);

        $limiter = new RateLimiterFactory(
            ['id' => 'direct_upload', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );

        $controller = new DirectUploadController(
            $projectRepo,
            $intentRepo,
            $directUpload,
            $serializer,
            $limiter,
        );
        $controller->setContainer($this->createTestContainer(null));

        $request = Request::create('/api/projects/some-uuid/media/direct-upload/intent', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        // No user, no _project attribute

        $response = $controller->createIntent('some-uuid', $request);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDeploymentControllerHookRateLimitingReturns429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployments = $this->createMock(DeploymentRepository::class);
        $projects = $this->createMock(ProjectRepository::class);
        $limiter = $this->createRejectingLimiterFactory('deployment_hook');

        $controller = new DeploymentController(
            $em,
            $deployments,
            $projects,
            $limiter,
        );
        $controller->setContainer($this->createTestContainer());

        $request = Request::create('/api/deployments/hook', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response = $controller->hook($request);
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Too many deployment hook requests', $data['error']);
    }

    public function testProjectEmailControllerRejectsForbiddenAttachmentExtension(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(ProjectMailerService::class);
        $cache = $this->createMock(CacheInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $security = $this->createMock(Security::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);

        $emailLimiter = new RateLimiterFactory(
            ['id' => 'email', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $adminLimiter = new RateLimiterFactory(
            ['id' => 'admin_email', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );

        $project = new Project();
        $project->id = 1;
        $projectRepo->method('findOneBy')->willReturn($project);
        $memberRepo->method('findActiveByUserAndProject')->willReturn(new \App\Entity\ProjectMember());

        $user = new User();
        $user->email = 'admin@example.com';
        $security->method('getUser')->willReturn($user);

        $controller = new ProjectEmailController(
            $em,
            $mailer,
            $cache,
            $projectRepo,
            $tokenChecker,
            $security,
            $memberRepo,
            $emailLimiter,
            $adminLimiter,
        );
        $controller->setContainer($this->createTestContainer($user));

        $dangerousPayload = json_encode([
            'to' => 'user@example.com',
            'subject' => 'Invoice',
            'body' => 'Here is your file',
            'attachments' => [
                [
                    'filename' => 'exploit.php',
                    'content' => base64_encode('<?php echo "bad";'),
                    'mimeType' => 'text/x-php',
                ],
            ],
        ]);

        $request = Request::create(
            '/api/admin/projects/00000000-0000-0000-0000-000000000001/email',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $dangerousPayload
        );

        $response = $controller->sendAsAdmin('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('.php est strictement interdite', $data['error']);
    }

    public function testAntiSpamServiceEnforcesTimeoutOnCaptcha(): void
    {
        $limiter = new RateLimiterFactory(
            ['id' => 'spam', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                $this->callback(function (array $options) {
                    return isset($options['timeout'])
                        && $options['timeout'] === 3.0
                        && isset($options['max_duration'])
                        && $options['max_duration'] === 5.0;
                })
            )
            ->willThrowException(new \RuntimeException('Network timeout'));

        $antiSpam = new AntiSpamService($limiter, $httpClient);

        $result = $antiSpam->verifyCaptcha('dummy_token', [
            'provider' => 'turnstile',
            'secret' => 'cf_secret_key',
        ]);

        $this->assertFalse($result);
    }

    public function testStudioControllerAiImageGenerationRejectsNonImageAndFallsBackToPlaceholder(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $settingsRepo = $this->createMock(AppSettingsRepository::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $chatRepo = $this->createMock(StudioChatMessageRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $aiService = $this->createMock(AiContentService::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $normalizer = new FieldRelationOptionsNormalizer($colRepo);
        $hydrator = $this->createMock(FieldValueHydrator::class);

        $aiService->method('generateImage')->willReturn('https://example.com/not-an-image.html');
        $aiService->method('generatePlaceholder')->willReturn('https://example.com/placeholder.svg');

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn('<!DOCTYPE html><html><body><h1>Fake Image</h1></body></html>');

        $httpClient->method('request')->willReturn($responseMock);

        $uploadValidator = new UploadSecurityValidator($mediaRepo);

        $controller = new StudioController(
            $em,
            $projectRepo,
            $settingsRepo,
            $httpClient,
            $chatRepo,
            $endUserRepo,
            $hasher,
            $mediaRepo,
            $aiService,
            $normalizer,
            $hydrator,
            new NullLogger(),
            new SsrfProtectionService(),
            $uploadValidator,
        );

        $project = new Project();
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $container = new Container();
        $authChecker = $this->createMock(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authChecker);
        $controller->setContainer($container);

        $request = Request::create(
            '/api/projects/00000000-0000-0000-0000-000000000001/studio/ai-generate-image',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['prompt' => 'A beautiful mountain sunset'])
        );

        $response = $controller->aiGenerateImage('00000000-0000-0000-0000-000000000001', $request);
        $data = json_decode((string) $response->getContent(), true);

        // Should gracefully fall back to placeholder
        $this->assertSame('placeholder', $data['type']);
        $this->assertSame('https://example.com/placeholder.svg', $data['url']);
    }
}
