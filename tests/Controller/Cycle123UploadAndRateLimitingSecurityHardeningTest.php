<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BackupController;
use App\Controller\DirectUploadController;
use App\Controller\MediaController;
use App\Controller\PreviewController;
use App\Controller\Public\FormPublicController;
use App\Controller\TusController;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\AssetUploadIntentRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\FormRepository;
use App\Repository\MediaFolderRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\AuditService;
use App\Service\Backup\BackupManager;
use App\Service\DirectUploadService;
use App\Service\EavDataFormatterService;
use App\Service\Form\FormBuilder;
use App\Service\Form\SubmitHandler;
use App\Service\MediaSerializer;
use App\Service\MercurePublisher;
use App\Service\PreviewTokenService;
use App\Service\Security\SsrfProtectionService;
use App\Service\StorageDriverFactory;
use App\Service\TusServer;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Vich\UploaderBundle\Handler\UploadHandler;

class Cycle123UploadAndRateLimitingSecurityHardeningTest extends TestCase
{
    private function createRejectingLimiterFactory(string $id = 'test_reject', string $identifier = '127.0.0.1'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
        $factory->create($identifier)->consume(1);
        return $factory;
    }

    private function createUploadHandler(): UploadHandler
    {
        return (new \ReflectionClass(UploadHandler::class))->newInstanceWithoutConstructor();
    }

    public function testDirectUploadControllerReturnsRateLimitHeadersOn429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $intentRepo = $this->createMock(AssetUploadIntentRepository::class);
        $uploadService = $this->createMock(DirectUploadService::class);
        $serializer = $this->createMock(MediaSerializer::class);
        $limiterFactory = $this->createRejectingLimiterFactory('direct_upload_test');

        $controller = new DirectUploadController(
            $projectRepo,
            $intentRepo,
            $uploadService,
            $serializer,
            $limiterFactory
        );

        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/media/direct-upload/intent', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->createIntent('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testTusControllerReturnsRateLimitHeadersOn429(): void
    {
        $tusServer = $this->createMock(TusServer::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $serializer = $this->createMock(MediaSerializer::class);
        $folderRepo = $this->createMock(MediaFolderRepository::class);
        $profileRepo = $this->createMock(ProjectStorageProfileRepository::class);
        $ruleRepo = $this->createMock(StorageRuleRepository::class);
        $driverFactory = $this->createMock(StorageDriverFactory::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $mercure = $this->createMock(MercurePublisher::class);
        $validator = $this->createMock(UploadSecurityValidator::class);
        $limiterFactory = $this->createRejectingLimiterFactory('tus_test');

        $controller = new TusController(
            $tusServer,
            $em,
            $projectRepo,
            $serializer,
            $folderRepo,
            $profileRepo,
            $ruleRepo,
            $driverFactory,
            $memberRepo,
            $mercure,
            $validator,
            $limiterFactory
        );

        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/files/tus', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->create('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
    }

    public function testMediaControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $serializer = $this->createMock(MediaSerializer::class);
        $uploadHandler = $this->createUploadHandler();
        $projectRepo = $this->createMock(ProjectRepository::class);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $folderRepo = $this->createMock(MediaFolderRepository::class);
        $mercure = $this->createMock(MercurePublisher::class);
        $validator = $this->createMock(UploadSecurityValidator::class);
        $limiterFactory = $this->createRejectingLimiterFactory('media_test');

        $controller = new MediaController(
            $em,
            $serializer,
            $uploadHandler,
            $projectRepo,
            $mediaRepo,
            $folderRepo,
            $mercure,
            $validator,
            $limiterFactory
        );

        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/media', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
    }

    public function testBackupControllerReturnsRateLimitHeadersOn429(): void
    {
        $backupManager = $this->createMock(BackupManager::class);
        $auditService = $this->createMock(AuditService::class);
        $limiterFactory = $this->createRejectingLimiterFactory('backup_test', 'admin@example.com');

        $controller = new class($backupManager, $auditService, $limiterFactory) extends BackupController {
            public ?User $mockUser = null;
            public function getUser(): ?User {
                return $this->mockUser;
            }
        };

        $user = new User();
        $user->email = 'admin@example.com';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $controller->mockUser = $user;

        $request = Request::create('/api/admin/backups', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->list($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
    }

    public function testFormPublicControllerReturnsRateLimitHeadersOn429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $formRepo = $this->createMock(FormRepository::class);
        $builder = $this->createMock(FormBuilder::class);
        $submitHandler = $this->createMock(SubmitHandler::class);
        $submitLimiter = $this->createRejectingLimiterFactory('form_submit_test');
        $viewLimiter = $this->createRejectingLimiterFactory('form_view_test');

        $controller = new FormPublicController(
            $projectRepo,
            $formRepo,
            $builder,
            $submitHandler,
            $submitLimiter,
            $viewLimiter
        );

        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/forms/contact-us', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->show('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'contact-us', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));

        $submitRequest = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/forms/contact-us/submit', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $submitResponse = $controller->submit('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'contact-us', $submitRequest);

        $this->assertSame(429, $submitResponse->getStatusCode());
        $this->assertTrue($submitResponse->headers->has('Retry-After'));
        $this->assertTrue($submitResponse->headers->has('X-RateLimit-Limit'));
        $this->assertSame('1', $submitResponse->headers->get('X-RateLimit-Limit'));
    }

    public function testPreviewControllerReturnsRateLimitHeadersOn429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $tokenService = $this->createMock(PreviewTokenService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $ssrf = $this->createMock(SsrfProtectionService::class);
        $limiter = $this->createRejectingLimiterFactory('preview_test');

        $controller = new PreviewController(
            $projectRepo,
            $entryRepo,
            $tokenService,
            $formatter,
            $ssrf,
            $limiter
        );

        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/preview/token/b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->token('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'b1eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('1', $response->headers->get('X-RateLimit-Limit'));
    }

    public function testTranslationParityForCycle123Keys(): void
    {
        $cycle123Keys = [
            'upload.error.compound_extension_forbidden',
            'upload.error.svg_cdata_script',
            'media.manual_quarantine_reason',
        ];

        $locales = ['en', 'fr', 'es', 'ar'];
        foreach ($locales as $loc) {
            $dict = require __DIR__ . "/../../translations/messages.{$loc}.php";
            foreach ($cycle123Keys as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale '{$loc}' must have key '{$key}'");
                $this->assertNotEmpty($dict[$key], "Key '{$key}' in locale '{$loc}' must not be empty");
            }
        }
    }
}
