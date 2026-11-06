<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\StudioController;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\CollectionRepository;
use App\Repository\EndUserRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use App\Repository\StudioChatMessageRepository;
use App\Service\AiContentService;
use App\Service\AuditService;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\FieldValueHydrator;
use App\Service\Security\PageCompositionSecurityService;
use App\Service\Security\SsrfProtectionService;
use App\Service\UploadSecurityValidator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle #96: Security audit, hardening, quotas & sanitization tests for StudioController.
 */
class Cycle96StudioHardeningTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $params = []) => strtr($id, $params)
        );
        return $translator;
    }

    private function createController(
        ?EntityManagerInterface $em = null,
        ?AuditService $auditService = null,
        ?TranslatorInterface $translator = null
    ): StudioController {
        $em ??= $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $chatRepo = $this->createMock(StudioChatMessageRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $aiService = $this->createMock(AiContentService::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $relationNormalizer = new FieldRelationOptionsNormalizer($colRepo);
        $hydrator = $this->createMock(FieldValueHydrator::class);
        $ssrf = $this->createMock(SsrfProtectionService::class);
        $uploadValidator = $this->createMock(UploadSecurityValidator::class);
        $translator ??= $this->createTranslator();
        $compositionSecurity = new PageCompositionSecurityService(null, $ssrf, $translator);

        $controller = new StudioController(
            em: $em,
            projectRepository: $projectRepo,
            appSettingsRepository: $appSettingsRepo,
            httpClient: $httpClient,
            chatMessageRepository: $chatRepo,
            endUserRepository: $endUserRepo,
            passwordHasher: $hasher,
            mediaRepository: $mediaRepo,
            aiService: $aiService,
            relationOptionsNormalizer: $relationNormalizer,
            fieldValueHydrator: $hydrator,
            logger: new NullLogger(),
            ssrfProtection: $ssrf,
            uploadValidator: $uploadValidator,
            aiLimiter: null,
            translator: $translator,
            compositionSecurity: $compositionSecurity,
            auditService: $auditService,
        );

        // Configure container for denyAccessUnlessGranted
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn ($id) => $id === 'security.authorization_checker');
        $container->method('get')->willReturnCallback(fn ($id) => $id === 'security.authorization_checker' ? $authChecker : null);

        $controller->setContainer($container);

        return $controller;
    }

    public function testAiSchemaRejectsPayloadExceedingMaxBytes(): void
    {
        $controller = $this->createController();
        $largeContent = str_repeat('a', StudioController::MAX_PAYLOAD_BYTES + 10);
        $request = new Request([], [], [], [], [], [], $largeContent);

        $response = $controller->aiSchema('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.payload_too_large', $data['error']);
    }

    public function testAiChatRejectsPayloadExceedingMaxBytes(): void
    {
        $controller = $this->createController();
        $largeContent = str_repeat('a', StudioController::MAX_CHAT_PAYLOAD_BYTES + 10);
        $request = new Request([], [], [], [], [], [], $largeContent);

        $response = $controller->aiChat('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.payload_too_large', $data['error']);
    }

    public function testApplyEntriesRejectsPayloadExceedingMaxBytes(): void
    {
        $controller = $this->createController();
        $largeContent = str_repeat('a', StudioController::MAX_PAYLOAD_BYTES + 10);
        $request = new Request([], [], [], [], [], [], $largeContent);

        $response = $controller->applyEntries('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.payload_too_large', $data['error']);
    }

    public function testApplySchemaRejectsPayloadExceedingMaxBytes(): void
    {
        $controller = $this->createController();
        $largeContent = str_repeat('a', StudioController::MAX_PAYLOAD_BYTES + 10);
        $request = new Request([], [], [], [], [], [], $largeContent);

        $response = $controller->applySchema('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.payload_too_large', $data['error']);
    }

    public function testAiExecuteRejectsPayloadExceedingMaxBytes(): void
    {
        $controller = $this->createController();
        $largeContent = str_repeat('a', StudioController::MAX_PAYLOAD_BYTES + 10);
        $request = new Request([], [], [], [], [], [], $largeContent);

        $response = $controller->aiExecute('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.payload_too_large', $data['error']);
    }

    public function testAiGenerateImageRejectsPayloadExceedingMaxBytes(): void
    {
        $controller = $this->createController();
        $largeContent = str_repeat('a', StudioController::MAX_PAYLOAD_BYTES + 10);
        $request = new Request([], [], [], [], [], [], $largeContent);

        $response = $controller->aiGenerateImage('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.payload_too_large', $data['error']);
    }

    public function testAiExecuteRejectsExceedingMaxActions(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = $this->createController($em);

        $actions = [];
        for ($i = 0; $i <= StudioController::MAX_ACTIONS_PER_REQUEST; $i++) {
            $actions[] = ['tool' => 'explore_schema', 'params' => []];
        }

        $payload = json_encode(['actions' => $actions]);
        $request = new Request([], [], [], [], [], [], $payload);

        $response = $controller->aiExecute('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.too_many_actions', $data['error']);
    }

    public function testAiExecuteRejectsExceedingMaxBatchEntriesInUpdate(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = $this->createController($em);

        $uuids = [];
        for ($i = 0; $i <= PageCompositionSecurityService::MAX_BATCH_ENTRIES; $i++) {
            $uuids[] = '00000000-0000-0000-0000-' . sprintf('%012d', $i);
        }

        $actions = [
            [
                'tool' => 'update_entries',
                'params' => [
                    'collection' => 'articles',
                    'uuids' => $uuids,
                    'patch' => ['title' => 'Updated'],
                ],
            ],
        ];

        $payload = json_encode(['actions' => $actions]);
        $request = new Request([], [], [], [], [], [], $payload);

        $response = $controller->aiExecute('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('log', $data);
        $this->assertStringContainsString('studio.quota.max_batch_entries_exceeded', $data['log'][0]['result']['error']);
    }

    public function testAiExecuteRejectsExceedingMaxBatchEntriesInDelete(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = $this->createController($em);

        $uuids = [];
        for ($i = 0; $i <= PageCompositionSecurityService::MAX_BATCH_ENTRIES; $i++) {
            $uuids[] = '00000000-0000-0000-0000-' . sprintf('%012d', $i);
        }

        $actions = [
            [
                'tool' => 'delete_entries',
                'params' => [
                    'collection' => 'articles',
                    'uuids' => $uuids,
                ],
            ],
        ];

        $payload = json_encode(['actions' => $actions]);
        $request = new Request([], [], [], [], [], [], $payload);

        $response = $controller->aiExecute('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('log', $data);
        $this->assertStringContainsString('studio.quota.max_batch_entries_exceeded', $data['log'][0]['result']['error']);
    }

    public function testAiExecuteRejectsExceedingMaxImagePrompts(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = $this->createController($em);

        $prompts = [];
        for ($i = 0; $i <= StudioController::MAX_GENERATE_IMAGES_BATCH; $i++) {
            $prompts[] = ['label' => "Image {$i}", 'description' => "Prompt {$i}"];
        }

        $actions = [
            [
                'tool' => 'generate_images',
                'params' => [
                    'prompts' => $prompts,
                ],
            ],
        ];

        $payload = json_encode(['actions' => $actions]);
        $request = new Request([], [], [], [], [], [], $payload);

        $response = $controller->aiExecute('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('log', $data);
        $this->assertStringContainsString('studio.quota.max_prompts_exceeded', $data['log'][0]['result']['error']);
    }

    public function testApplySchemaRejectsExceedingProjectCollectionQuota(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        // 98 existing collections in project
        $existingCols = [];
        for ($i = 1; $i <= 98; $i++) {
            $col = new Collection();
            $col->slug = "existing_col_{$i}";
            $existingCols[] = $col;
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $colRepo = $this->createMock(EntityRepository::class);
        $colRepo->method('findBy')->willReturn($existingCols);

        $em->method('getRepository')->willReturnCallback(function ($class) use ($projectRepo, $colRepo) {
            if ($class === Project::class) return $projectRepo;
            if ($class === Collection::class) return $colRepo;
            return null;
        });

        $controller = $this->createController($em);

        // Try adding 5 new collections (98 + 5 = 103 > 100)
        $newCollections = [];
        for ($i = 1; $i <= 5; $i++) {
            $newCollections[] = [
                'name' => "NewCol{$i}",
                'slug' => "new_col_{$i}",
                'fields' => [
                    ['name' => 'title', 'slug' => 'title', 'type' => 'text'],
                ],
            ];
        }

        $payload = json_encode(['collections' => $newCollections]);
        $request = new Request([], [], [], [], [], [], $payload);

        $response = $controller->applySchema('00000000-0000-0000-0000-000000000001', $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.quota.project_collections_exceeded', $data['error']);
        $this->assertSame('MAX_COLLECTIONS_EXCEEDED', $data['code']);
    }

    public function testGenerateTypesSanitizesIdentifiersAndQuotesSpecialFields(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        $col = new Collection();
        $col->slug = 'blog-posts-2026';

        $f1 = new Field();
        $f1->name = 'title';
        $f1->slug = 'title';
        $f1->type = 'text';
        $f1->isRequired = true;

        $f2 = new Field();
        $f2->name = 'custom-header';
        $f2->slug = 'custom-header'; // contains hyphen -> must be quoted in TypeScript
        $f2->type = 'text';
        $f2->isRequired = false;

        $col->fields = new ArrayCollection([$f1, $f2]);

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $colRepo = $this->createMock(EntityRepository::class);
        $colRepo->method('findBy')->willReturn([$col]);

        $em->method('getRepository')->willReturnCallback(function ($class) use ($projectRepo, $colRepo) {
            if ($class === Project::class) return $projectRepo;
            if ($class === Collection::class) return $colRepo;
            return null;
        });

        $controller = $this->createController($em);

        $response = $controller->generateTypes('00000000-0000-0000-0000-000000000001', new Request());
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);

        $this->assertArrayHasKey('types', $data);
        $this->assertCount(1, $data['types']);
        $type = $data['types'][0];

        // Valid TypeScript PascalCase interface name without hyphens
        $this->assertSame('BlogPosts2026', $type['name']);
        // Contains standard field
        $this->assertStringContainsString('title: string;', $type['code']);
        // Contains quoted special field
        $this->assertStringContainsString('"custom-header"?: string;', $type['code']);
    }

    public function testAuditLoggingOnChatMessagesClear(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())
            ->method('log')
            ->with(
                'studio_chat_messages_clear',
                $project,
                [],
                ['success' => true],
                'success',
                null,
                'system',
                'studio_api'
            );
        $auditService->expects($this->once())->method('flush');

        $controller = $this->createController($em, $auditService);

        $response = $controller->clearChatMessages('00000000-0000-0000-0000-000000000001');
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
    }
}
