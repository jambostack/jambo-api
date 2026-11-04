<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ColumnSettingController;
use App\Controller\CommentController;
use App\Controller\FlowController;
use App\Controller\MediaController;
use App\Controller\MediaFolderController;
use App\Controller\RealtimeController;
use App\Controller\VersioningController;
use App\Entity\Collection;
use App\Entity\Comment;
use App\Entity\ContentEntry;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\ColumnSettingRepository;
use App\Repository\CommentRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentVersionRepository;
use App\Repository\MediaFolderRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\Flow\NodeRegistry;
use App\Service\MediaSerializer;
use App\Service\MercurePublisher;
use App\Service\UploadSecurityValidator;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Handler\UploadHandler;
use Vich\UploaderBundle\Injector\FileInjectorInterface;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;
use Vich\UploaderBundle\Storage\StorageInterface;

class Cycle17HardeningTest extends TestCase
{
    private function createRejectingLimiterFactory(string $id = 'realtime'): RateLimiterFactory
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

    private function createUploadHandler(): UploadHandler
    {
        return (new \ReflectionClass(UploadHandler::class))->newInstanceWithoutConstructor();
    }

    public function testRealtimeInvalidUuidRejection(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);

        $controller = new RealtimeController($projectRepo, $memberRepo, false, null);
        $controller->setContainer($this->createTestContainer());
        $request = new Request();

        $resPoll = $controller->poll('../../../invalid-uuid', $request);
        $this->assertSame(400, $resPoll->getStatusCode());
        $dataPoll = json_decode((string) $resPoll->getContent(), true);
        $this->assertSame('Invalid project UUID format', $dataPoll['error']);

        $resToken = $controller->token('not-a-valid-uuid', $request);
        $this->assertSame(400, $resToken->getStatusCode());
        $dataToken = json_decode((string) $resToken->getContent(), true);
        $this->assertSame('Invalid project UUID format', $dataToken['error']);
    }

    public function testRealtimeLimiterRejection(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $limiter = $this->createRejectingLimiterFactory('realtime_limiter');

        $controller = new RealtimeController($projectRepo, $memberRepo, false, $limiter);
        $controller->setContainer($this->createTestContainer());
        $validUuid = '00000000-0000-0000-0000-000000000001';
        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $res = $controller->poll($validUuid, $request);
        $this->assertSame(429, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertStringContainsString('Too many realtime requests', $data['error']);
    }

    public function testMercurePublisherSafeUuidOnly(): void
    {
        $tmpDir = sys_get_temp_dir() . '/realtime_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700, true);

        try {
            $publisher = new MercurePublisher($tmpDir, null);
            // Malicious traversal uuid
            $publisher->contentChanged('../malicious', 'update', ['name' => 'test']);
            $this->assertFileDoesNotExist($tmpDir . '/malicious.jsonl');
            $this->assertFileDoesNotExist(dirname($tmpDir) . '/malicious.jsonl');

            // Valid UUID
            $validUuid = '11111111-1111-1111-1111-111111111111';
            $publisher->contentChanged($validUuid, 'create', ['name' => 'test']);
            $this->assertFileExists($tmpDir . '/var/realtime/' . $validUuid . '.jsonl');
        } finally {
            if (is_dir($tmpDir . '/var/realtime')) {
                @unlink($tmpDir . '/var/realtime/' . $validUuid . '.jsonl');
                @rmdir($tmpDir . '/var/realtime');
                @rmdir($tmpDir . '/var');
            }
            @rmdir($tmpDir);
        }
    }

    public function testMediaCropRejectsNonImage(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $serializer = $this->createMock(MediaSerializer::class);
        $handler = $this->createUploadHandler();
        $projectRepo = $this->createMock(ProjectRepository::class);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $folderRepo = $this->createMock(MediaFolderRepository::class);
        $mercure = $this->createMock(MercurePublisher::class);
        $uploadValidator = $this->createMock(UploadSecurityValidator::class);

        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000001');

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::fromString('22222222-2222-2222-2222-222222222222');

        $projectRepo->method('findOneBy')->willReturn($project);
        $mediaRepo->method('findOneBy')->willReturn($media);

        $controller = new MediaController(
            $em, $serializer, $handler, $projectRepo, $mediaRepo,
            $folderRepo, $mercure, $uploadValidator
        );
        $controller->setContainer($this->createTestContainer());

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_crop');
        file_put_contents($tmpFile, '<?php phpinfo(); ?>');

        $uploadedFile = new UploadedFile($tmpFile, 'shell.php', 'application/x-php', null, true);
        $request = new Request([], [], [], [], ['file' => $uploadedFile]);

        $res = $controller->crop('00000000-0000-0000-0000-000000000001', '22222222-2222-2222-2222-222222222222', $request);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertStringContainsString('Cropping requires an image file', $data['error']);

        @unlink($tmpFile);
    }

    public function testMediaCropValidatesWithUploadSecurityValidator(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $serializer = $this->createMock(MediaSerializer::class);
        $handler = $this->createUploadHandler();
        $projectRepo = $this->createMock(ProjectRepository::class);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $folderRepo = $this->createMock(MediaFolderRepository::class);
        $mercure = $this->createMock(MercurePublisher::class);
        $uploadValidator = $this->createMock(UploadSecurityValidator::class);

        $uploadValidator->method('validateUploadedFile')->willThrowException(
            new \InvalidArgumentException('Corrupted or invalid JPEG image header.')
        );

        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000001');

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::fromString('22222222-2222-2222-2222-222222222222');

        $projectRepo->method('findOneBy')->willReturn($project);
        $mediaRepo->method('findOneBy')->willReturn($media);

        $controller = new MediaController(
            $em, $serializer, $handler, $projectRepo, $mediaRepo,
            $folderRepo, $mercure, $uploadValidator
        );
        $controller->setContainer($this->createTestContainer());

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_crop_corrupt');
        file_put_contents($tmpFile, 'not-a-jpeg');

        $uploadedFile = new UploadedFile($tmpFile, 'crop.jpg', 'image/jpeg', null, true);
        $request = new Request([], [], [], [], ['file' => $uploadedFile]);

        $res = $controller->crop('00000000-0000-0000-0000-000000000001', '22222222-2222-2222-2222-222222222222', $request);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertStringContainsString('Corrupted or invalid JPEG image header', $data['error']);

        @unlink($tmpFile);
    }

    public function testCommentResolveIsolation(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $commentRepo = $this->createMock(CommentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000001');

        $collection = new Collection();
        $collection->slug = 'posts';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->id = 10;
        $entry->project = $project;
        $entry->collection = $collection;

        $comment = new Comment();
        $comment->id = 55;
        $comment->entry = $entry;

        $commentRepo->method('find')->willReturn($comment);

        $controller = new CommentController($projectRepo, $collectionRepo, $entryRepo, $commentRepo, $em);
        $controller->setContainer($this->createTestContainer());

        // Mismatching project UUID
        $resMismatchProject = $controller->resolve(
            '99999999-9999-9999-9999-999999999999', 'posts', 10, 55, new Request()
        );
        $this->assertSame(404, $resMismatchProject->getStatusCode());

        // Mismatching collection slug
        $resMismatchCollection = $controller->resolve(
            '00000000-0000-0000-0000-000000000001', 'articles', 10, 55, new Request()
        );
        $this->assertSame(404, $resMismatchCollection->getStatusCode());

        // Mismatching entry ID
        $resMismatchEntry = $controller->resolve(
            '00000000-0000-0000-0000-000000000001', 'posts', 999, 55, new Request()
        );
        $this->assertSame(404, $resMismatchEntry->getStatusCode());
    }

    public function testMediaFolderLengthAndControlCharRejection(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $folderRepo = $this->createMock(MediaFolderRepository::class);

        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000001');
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new MediaFolderController($em, $projectRepo, $folderRepo);
        $controller->setContainer($this->createTestContainer());

        // Oversized name > 150 chars
        $reqOversized = new Request([], [], [], [], [], [], json_encode(['name' => str_repeat('A', 151)]));
        $resOversized = $controller->store('00000000-0000-0000-0000-000000000001', $reqOversized);
        $this->assertSame(422, $resOversized->getStatusCode());
        $dataOversized = json_decode((string) $resOversized->getContent(), true);
        $this->assertStringContainsString('cannot exceed 150 characters', $dataOversized['error']);

        // Name with control character
        $reqControl = new Request([], [], [], [], [], [], json_encode(['name' => "folder\x00malicious"]));
        $resControl = $controller->store('00000000-0000-0000-0000-000000000001', $reqControl);
        $this->assertSame(422, $resControl->getStatusCode());
        $dataControl = json_decode((string) $resControl->getContent(), true);
        $this->assertStringContainsString('forbidden control characters', $dataControl['error']);
    }

    public function testColumnSettingValidationAndCapping(): void
    {
        $user = new User();
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $settingRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $projectRepo->method('findOneBy')->willReturn($project);
        $collectionRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $controller = new ColumnSettingController($projectRepo, $collectionRepo, $settingRepo, $em);
        $controller->setContainer($this->createTestContainer($user));

        // Non-array input
        $reqNonArray = new Request([], [], [], [], [], [], json_encode(['visible_columns' => 'not-an-array']));
        $resNonArray = $controller->update('00000000-0000-0000-0000-000000000001', 'posts', $reqNonArray);
        $this->assertSame(422, $resNonArray->getStatusCode());

        // Array with > 50 items and control characters
        $columns = [];
        for ($i = 0; $i < 60; $i++) {
            $columns[] = 'col_' . $i;
        }
        $columns[] = "bad\x00col";
        $reqValid = new Request([], [], [], [], [], [], json_encode(['visible_columns' => $columns]));
        $resValid = $controller->update('00000000-0000-0000-0000-000000000001', 'posts', $reqValid);
        $this->assertSame(200, $resValid->getStatusCode());
        $dataValid = json_decode((string) $resValid->getContent(), true);
        $this->assertCount(50, $dataValid['visibleColumns']);
    }

    public function testFlowNodeCatalogHasIsGrantedAttribute(): void
    {
        $reflection = new \ReflectionClass(FlowController::class);
        $attributes = $reflection->getAttributes(IsGranted::class);

        $this->assertNotEmpty($attributes, 'FlowController must declare #[IsGranted] attribute.');
        $isGranted = $attributes[0]->newInstance();
        $this->assertSame('ROLE_USER', $isGranted->attribute);
    }

    public function testVersioningBoundsValidationAndI18n(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $versioning = $this->createMock(VersioningService::class);
        $versionRepo = $this->createMock(ContentVersionRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function ($key, $params = []) {
            if ($key === 'versioning.invalid_version') return 'Invalid version number.';
            if ($key === 'versioning.restored_successfully') return "Restored to version {$params['%version%']} successfully.";
            return $key;
        });

        $controller = new VersioningController($em, $versioning, $versionRepo, $translator);
        $controller->setContainer($this->createTestContainer());

        // Negative version number on restore
        $resRestoreNegative = $controller->restore(
            '00000000-0000-0000-0000-000000000001', 'posts', '33333333-3333-3333-3333-333333333333', -5, new Request()
        );
        $this->assertSame(422, $resRestoreNegative->getStatusCode());
        $dataRestore = json_decode((string) $resRestoreNegative->getContent(), true);
        $this->assertSame('Invalid version number.', $dataRestore['error']);

        // Diff bounds error
        $project = new Project();
        $collection = new Collection();
        $entry = new ContentEntry();

        $projectRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collectionRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $collectionRepo->method('findOneBy')->willReturn($collection);
        $entryRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $entryRepo->method('findOneBy')->willReturn($entry);

        $em->method('getRepository')->willReturnCallback(function ($class) use ($projectRepo, $collectionRepo, $entryRepo) {
            if ($class === Project::class) return $projectRepo;
            if ($class === Collection::class) return $collectionRepo;
            if ($class === ContentEntry::class) return $entryRepo;
            return null;
        });

        $reqDiff = new Request(['v1' => '-1', 'v2' => '5000000']);
        $resDiff = $controller->diff('00000000-0000-0000-0000-000000000001', 'posts', '33333333-3333-3333-3333-333333333333', $reqDiff);
        $this->assertSame(422, $resDiff->getStatusCode());
        $dataDiff = json_decode((string) $resDiff->getContent(), true);
        $this->assertSame('Invalid version number.', $dataDiff['error']);
    }
}
