<?php

namespace App\Tests\Controller;

use App\Controller\FlowController;
use App\Controller\InsightsController;
use App\Controller\ProjectTemplateController;
use App\Controller\VersioningController;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\User;
use App\Repository\ContentVersionRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use App\Service\Flow\NodeRegistry;
use App\Service\Insights\InsightsService;
use App\Service\ProjectTemplateBuilder;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle47SecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $adminUser;
    private Project $project;
    private string $projectUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        $uniq = bin2hex(random_bytes(4));
        $this->adminUser = new User();
        $this->adminUser->email = "c47_admin_{$uniq}@example.com";
        $this->adminUser->password = 'password123';
        $this->adminUser->name = 'Cycle47 Admin';
        $this->adminUser->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->adminUser);

        $this->project = new Project();
        $this->project->name = "Cycle47 Project {$uniq}";
        $this->project->uuid = Uuid::v4();
        $this->project->owner = $this->adminUser;
        $this->em->persist($this->project);
        $this->projectUuid = $this->project->uuid->toRfc4122();

        $this->em->flush();
    }

    private function createRejectingLimiterFactory(string $id = 'c47_reject'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
        $factory->create('127.0.0.1')->consume(1);
        $factory->create($this->adminUser->getUserIdentifier())->consume(1);
        return $factory;
    }

    // ─── 1. InsightsController Hardening ─────────────────────────────────────

    public function testInsightsSummaryUnauthenticatedRejection(): void
    {
        $this->client->request('GET', '/insights/summary');

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status, [302, 401], true));
    }

    public function testInsightsRateLimitingEnforcement(): void
    {
        $insightsService = $this->createMock(InsightsService::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($key) => "translated_{$key}");
        $limiter = $this->createRejectingLimiterFactory('insights_test');

        $controller = new InsightsController($insightsService, $projectRepo, $translator, $limiter);

        $req = Request::create('/insights/summary', 'GET');
        $res = $controller->summary($req);

        $this->assertSame(429, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $payload['code']);
        $this->assertSame('translated_insights.rate_limit_exceeded', $payload['error']);

        $resProj = $controller->project('00000000-0000-0000-0000-000000000001', $req);
        $this->assertSame(429, $resProj->getStatusCode());
        $payloadProj = json_decode((string) $resProj->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $payloadProj['code']);
    }

    // ─── 2. FlowController Hardening ─────────────────────────────────────────

    public function testFlowControllerRateLimitingEnforcement(): void
    {
        $nodeRegistry = $this->createMock(NodeRegistry::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($key) => "translated_{$key}");
        $limiter = $this->createRejectingLimiterFactory('flow_test');

        $controller = new FlowController($nodeRegistry, null, $translator, $limiter);

        $req = Request::create('/api/automations/node-catalog', 'GET');
        $resCatalog = $controller->nodeCatalog($req);
        $this->assertSame(429, $resCatalog->getStatusCode());
        $catalogData = json_decode((string) $resCatalog->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $catalogData['code']);

        $resFlow = $controller->validateFlow($req);
        $this->assertSame(429, $resFlow->getStatusCode());
        $flowData = json_decode((string) $resFlow->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $flowData['code']);
    }

    public function testFlowControllerOversizedPayloadRejection(): void
    {
        $this->client->loginUser($this->adminUser);

        $hugePayload = json_encode([
            'flow_graph' => [
                'nodes' => [
                    ['id' => 'n1', 'type' => 'trigger.content.created', 'data' => ['junk' => str_repeat('X', 600000)]]
                ],
                'edges' => []
            ]
        ]);

        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $hugePayload);

        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code']);
    }

    public function testFlowControllerNodesLimitExceeded(): void
    {
        $this->client->loginUser($this->adminUser);

        $nodes = [];
        for ($i = 0; $i < 105; $i++) {
            $nodes[] = ['id' => "node_{$i}", 'type' => 'utility.noop', 'data' => []];
        }

        $payload = json_encode([
            'flow_graph' => [
                'nodes' => $nodes,
                'edges' => [],
            ]
        ]);

        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('NODES_LIMIT_EXCEEDED', $data['code']);
        $this->assertFalse($data['valid']);
    }

    public function testFlowControllerEdgesLimitExceeded(): void
    {
        $this->client->loginUser($this->adminUser);

        $nodes = [
            ['id' => 'n1', 'type' => 'utility.noop', 'data' => []],
            ['id' => 'n2', 'type' => 'utility.noop', 'data' => []],
        ];
        $edges = [];
        for ($i = 0; $i < 205; $i++) {
            $edges[] = ['id' => "edge_{$i}", 'source' => 'n1', 'target' => 'n2'];
        }

        $payload = json_encode([
            'flow_graph' => [
                'nodes' => $nodes,
                'edges' => $edges,
            ]
        ]);

        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('EDGES_LIMIT_EXCEEDED', $data['code']);
        $this->assertFalse($data['valid']);
    }

    // ─── 3. VersioningController Hardening ───────────────────────────────────

    public function testVersioningControllerRateLimitingEnforcement(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $versioning = $this->createMock(VersioningService::class);
        $repo = $this->createMock(ContentVersionRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($key) => "translated_{$key}");
        $limiter = $this->createRejectingLimiterFactory('version_test');

        $controller = new VersioningController($em, $versioning, $repo, $translator, $limiter);

        $req = Request::create('/api/projects/uuid/collections/col/entries/uuid/versions', 'GET');
        $resList = $controller->list('00000000-0000-0000-0000-000000000001', 'articles', '00000000-0000-0000-0000-000000000002', $req);
        $this->assertSame(429, $resList->getStatusCode());
        $listData = json_decode((string) $resList->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $listData['code']);

        $resRestore = $controller->restore('00000000-0000-0000-0000-000000000001', 'articles', '00000000-0000-0000-0000-000000000002', 1, $req);
        $this->assertSame(429, $resRestore->getStatusCode());
        $restoreData = json_decode((string) $resRestore->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $restoreData['code']);

        $resDiff = $controller->diff('00000000-0000-0000-0000-000000000001', 'articles', '00000000-0000-0000-0000-000000000002', $req);
        $this->assertSame(429, $resDiff->getStatusCode());
        $diffData = json_decode((string) $resDiff->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $diffData['code']);
    }

    public function testVersioningControllerBoundsAndInvalidVersionCheck(): void
    {
        $this->client->loginUser($this->adminUser);

        // Invalid version numbers (< 1 or > 1,000,000)
        $this->client->request('POST', "/api/projects/{$this->projectUuid}/collections/articles/entries/00000000-0000-0000-0000-000000000001/versions/0/restore");
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', "/api/projects/{$this->projectUuid}/collections/articles/entries/00000000-0000-0000-0000-000000000001/versions/9999999/restore");
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', "/api/projects/{$this->projectUuid}/collections/articles/entries/00000000-0000-0000-0000-000000000001/versions/diff?v1=-5&v2=2");
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    // ─── 4. ProjectTemplateController Hardening ──────────────────────────────

    public function testProjectTemplateRateLimitingEnforcement(): void
    {
        $repo = $this->createMock(ProjectTemplateRepository::class);
        $builder = $this->createMock(ProjectTemplateBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($key) => "translated_{$key}");
        $limiter = $this->createRejectingLimiterFactory('template_test');

        $controller = new ProjectTemplateController($repo, $builder, $em, $translator, $limiter);

        $req = Request::create('/api/project-templates', 'GET');
        $resIndex = $controller->index($req);
        $this->assertSame(429, $resIndex->getStatusCode());
        $indexData = json_decode((string) $resIndex->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $indexData['code']);

        $resShow = $controller->show(1, $req);
        $this->assertSame(429, $resShow->getStatusCode());
        $showData = json_decode((string) $resShow->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $showData['code']);
    }

    public function testProjectTemplatePayloadTooLarge(): void
    {
        $this->client->loginUser($this->adminUser);

        $hugePayload = json_encode([
            'name' => 'Huge Template',
            'description' => str_repeat('A', 600000),
        ]);

        $this->client->request('POST', "/api/project-templates/from-project/{$this->projectUuid}", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $hugePayload);

        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code']);
    }

    public function testProjectTemplateQuotaEnforcement(): void
    {
        $repo = $this->createMock(ProjectTemplateRepository::class);
        $repo->method('count')->willReturn(ProjectTemplateController::MAX_TEMPLATES_COUNT);
        $builder = $this->createMock(ProjectTemplateBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($key) => "translated_{$key}");

        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000001');
        $emRepo = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $emRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($emRepo);

        $controller = new ProjectTemplateController($repo, $builder, $em, $translator);

        $req = Request::create('/api/project-templates/from-project/00000000-0000-0000-0000-000000000001', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Quota Template']));
        $req->attributes->set('_project', $project);

        $res = $controller->saveFromProject('00000000-0000-0000-0000-000000000001', $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('MAX_TEMPLATES_EXCEEDED', $data['code']);
    }

    // ─── 5. Strict I18N Completeness ─────────────────────────────────────────

    public function testStrictI18nCompletenessAcrossAllLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $translations = [];

        foreach ($locales as $loc) {
            $path = __DIR__ . "/../../translations/messages.{$loc}.php";
            $this->assertFileExists($path);
            $dict = require $path;
            $this->assertIsArray($dict);
            $translations[$loc] = $dict;
        }

        $requiredKeys = [
            'insights.rate_limit_exceeded',
            'flow.admin.rate_limit_exceeded',
            'flow.admin.payload_too_large',
            'flow.admin.nodes_limit_exceeded',
            'flow.admin.edges_limit_exceeded',
            'versioning.rate_limit_exceeded',
            'template.rate_limit_exceeded',
            'template.payload_too_large',
        ];

        foreach ($locales as $loc) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $translations[$loc], "Missing {$key} in {$loc}");
                $this->assertNotEmpty($translations[$loc][$key], "Empty {$key} in {$loc}");
            }
        }
    }
}
