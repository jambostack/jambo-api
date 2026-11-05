<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminApi\TokenController;
use App\Controller\AiController;
use App\Controller\InsightsController;
use App\Controller\WebhookController;
use App\Entity\Collection;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Webhook;
use App\Repository\CollectionRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectRepository;
use App\Repository\WebhookLogRepository;
use App\Repository\WebhookRepository;
use App\Security\ProjectVoter;
use App\Service\AiContentService;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\EavDataFormatterService;
use App\Service\Insights\InsightsService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle87SecurityAndQuotaHardeningTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            $msg = 'trans:' . $id;
            foreach ($params as $k => $v) {
                $msg .= "[$k=$v]";
            }
            return $msg;
        });
        return $translator;
    }

    private function createProject(string $uuid): Project
    {
        $project = new Project();
        $project->id = 42;
        $project->name = 'Test Project';
        $project->uuid = Uuid::fromString($uuid);
        $project->defaultLocale = 'en';
        return $project;
    }

    private function setContainer(object $controller, ?User $user = null): void
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
        $authChecker->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authChecker);

        $ref = new \ReflectionClass($controller);
        if ($ref->hasMethod('setContainer')) {
            $controller->setContainer($container);
        }
    }

    // ─── 1. AiController Payload Protection, Catch Safety & Audit Logging ───

    public function testAiControllerRejectsPayloadExceedingMaxLimit(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $ai = $this->createMock(AiContentService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $translator = $this->createTranslator();
        $audit = $this->createMock(AuditService::class);

        $projectUuid = '00000000-0000-4000-8000-000000000001';
        $project = $this->createProject($projectUuid);

        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = new AiController(
            em: $em,
            ai: $ai,
            formatter: $formatter,
            aiLimiter: null,
            tokenChecker: null,
            translator: $translator,
            auditService: $audit,
        );
        $this->setContainer($controller);

        $oversizedData = str_repeat('A', AiController::MAX_PAYLOAD_BYTES + 100);
        $request = Request::create(
            "/api/projects/{$projectUuid}/ai/generate",
            'POST',
            [],
            [],
            [],
            ['HTTP_CONTENT_LENGTH' => (string) strlen($oversizedData)],
            $oversizedData
        );

        $response = $controller->generate($projectUuid, $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('ai.payload_too_large', $data['error']);
    }

    public function testAiControllerTranslateAndSummarizeRejectOversizedPayload(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $ai = $this->createMock(AiContentService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $translator = $this->createTranslator();

        $projectUuid = '00000000-0000-4000-8000-000000000001';
        $project = $this->createProject($projectUuid);

        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = new AiController(
            em: $em,
            ai: $ai,
            formatter: $formatter,
            aiLimiter: null,
            tokenChecker: null,
            translator: $translator,
        );
        $this->setContainer($controller);

        $oversized = str_repeat('X', AiController::MAX_PAYLOAD_BYTES + 50);

        $req1 = Request::create("/api/projects/{$projectUuid}/ai/translate", 'POST', [], [], [], [], $oversized);
        $res1 = $controller->translate($projectUuid, $req1);
        $this->assertSame(413, $res1->getStatusCode());

        $req2 = Request::create("/api/projects/{$projectUuid}/ai/summarize", 'POST', [], [], [], [], $oversized);
        $res2 = $controller->summarize($projectUuid, $req2);
        $this->assertSame(413, $res2->getStatusCode());

        $req3 = Request::create("/api/projects/{$projectUuid}/ai/seo", 'POST', [], [], [], [], $oversized);
        $res3 = $controller->seo($projectUuid, $req3);
        $this->assertSame(413, $res3->getStatusCode());
    }

    public function testAiControllerAuditLoggingOnSuccessfulActions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $ai = $this->createMock(AiContentService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $translator = $this->createTranslator();
        $audit = $this->createMock(AuditService::class);

        $projectUuid = '00000000-0000-4000-8000-000000000001';
        $project = $this->createProject($projectUuid);

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturnCallback(function (array $criteria) use ($project, $collection) {
            if (isset($criteria['uuid'])) {
                return $project;
            }
            if (isset($criteria['slug'])) {
                return $collection;
            }
            return null;
        });
        $em->method('getRepository')->willReturn($repo);

        $ai->method('generateContent')->willReturn(['title' => 'Generated Title']);
        $ai->method('summarize')->willReturn('Summary text');
        $ai->method('generateSeo')->willReturn(['meta_title' => 'SEO Title']);
        $ai->method('suggestSchema')->willReturn(['fields' => []]);

        $loggedTools = [];
        $audit->method('log')->willReturnCallback(function (string $toolName) use (&$loggedTools) {
            $loggedTools[] = $toolName;
            return new \App\Entity\AuditLog();
        });

        $controller = new AiController(
            em: $em,
            ai: $ai,
            formatter: $formatter,
            aiLimiter: null,
            tokenChecker: null,
            translator: $translator,
            auditService: $audit,
        );
        $this->setContainer($controller);

        // 1. Generate
        $genReq = Request::create("/api/projects/{$projectUuid}/ai/generate", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'brief' => 'Write an article about quantum computing',
            'collection' => 'articles',
            'locale' => 'en',
        ]));
        $genRes = $controller->generate($projectUuid, $genReq);
        $this->assertSame(200, $genRes->getStatusCode());

        // 2. Summarize
        $sumReq = Request::create("/api/projects/{$projectUuid}/ai/summarize", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'text' => 'Quantum computing is a rapidly-emerging technology that harnesses the laws of quantum mechanics to solve problems too complex for classical computers.',
        ]));
        $sumRes = $controller->summarize($projectUuid, $sumReq);
        $this->assertSame(200, $sumRes->getStatusCode());

        // 3. SEO
        $seoReq = Request::create("/api/projects/{$projectUuid}/ai/seo", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'content' => ['title' => 'Quantum Computing Basics'],
        ]));
        $seoRes = $controller->seo($projectUuid, $seoReq);
        $this->assertSame(200, $seoRes->getStatusCode());

        // 4. Suggest Schema
        $schemaReq = Request::create("/api/projects/{$projectUuid}/ai/suggest-schema?collection=articles", 'GET');
        $schemaRes = $controller->suggestSchema($projectUuid, $schemaReq);
        $this->assertSame(200, $schemaRes->getStatusCode());

        $this->assertContains('ai_generate', $loggedTools);
        $this->assertContains('ai_summarize', $loggedTools);
        $this->assertContains('ai_seo', $loggedTools);
        $this->assertContains('ai_suggest_schema', $loggedTools);
    }

    public function testAiControllerSeoCatchesThrowableCleanly(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $ai = $this->createMock(AiContentService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $translator = $this->createTranslator();

        $projectUuid = '00000000-0000-4000-8000-000000000001';
        $project = $this->createProject($projectUuid);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($repo);

        $ai->method('generateSeo')->willThrowException(new \RuntimeException('Provider error occurred'));

        $controller = new AiController(
            em: $em,
            ai: $ai,
            formatter: $formatter,
            aiLimiter: null,
            tokenChecker: null,
            translator: $translator,
        );
        $this->setContainer($controller);

        $seoReq = Request::create("/api/projects/{$projectUuid}/ai/seo", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'content' => ['title' => 'SEO Failure Test'],
        ]));
        $response = $controller->seo($projectUuid, $seoReq);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('ai.error.seo_generation_failed', $data['error']);
    }

    // ─── 2. Personal Access Token Quota & Lifecycle Audit Hardening ───

    public function testTokenControllerRejectsWhenUserReachesMaxTokensQuota(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();
        $audit = $this->createMock(AuditService::class);

        $user = new User();
        $user->email = 'admin@example.com';
        $user->roles = ['ROLE_SUPER_ADMIN'];

        // User already has MAX_TOKENS_PER_USER tokens
        $repo->method('countByUser')->willReturn(TokenController::MAX_TOKENS_PER_USER);

        $controller = new TokenController(
            repo: $repo,
            em: $em,
            appSecret: 'secret_app_key_for_testing_1234567890',
            tokenLimiter: null,
            translator: $translator,
            auditService: $audit,
        );
        $this->setContainer($controller, $user);

        $request = Request::create('/admin-api/tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Exceeded Quota Token',
            'scopes' => ['content:read'],
        ]));

        $response = $controller->create($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('token.max_tokens_reached', $data['error']);
        $this->assertStringContainsString((string) TokenController::MAX_TOKENS_PER_USER, $data['error']);
    }

    public function testTokenControllerAuditLogsOnLifecycleEvents(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();
        $audit = $this->createMock(AuditService::class);

        $user = new User();
        $user->email = 'owner@example.com';

        $repo->method('countByUser')->willReturn(5);

        $loggedTools = [];
        $audit->method('log')->willReturnCallback(function (string $toolName) use (&$loggedTools) {
            $loggedTools[] = $toolName;
            return new \App\Entity\AuditLog();
        });

        $controller = new TokenController(
            repo: $repo,
            em: $em,
            appSecret: 'test_secret_key_32_bytes_long_string_abc',
            tokenLimiter: null,
            translator: $translator,
            auditService: $audit,
        );
        $this->setContainer($controller, $user);

        // 1. Create
        $createReq = Request::create('/admin-api/tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'CI Deployment Token',
            'scopes' => ['content:write', 'content:read'],
        ]));
        $createRes = $controller->create($createReq);
        $this->assertSame(201, $createRes->getStatusCode());
        $this->assertContains('personal_access_token_create', $loggedTools);

        // 2. Update
        $existingToken = new PersonalAccessToken();
        $existingToken->id = 77;
        $existingToken->name = 'Old Token Name';
        $existingToken->user = $user;
        $existingToken->scopes = ['content:read'];
        $repo->method('find')->willReturn($existingToken);

        $updateReq = Request::create('/admin-api/tokens/77', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Updated Token Name',
        ]));
        $updateRes = $controller->update(77, $updateReq);
        $this->assertSame(200, $updateRes->getStatusCode());
        $this->assertContains('personal_access_token_update', $loggedTools);

        // 3. Delete
        $deleteReq = Request::create('/admin-api/tokens/77', 'DELETE');
        $deleteRes = $controller->delete(77, $deleteReq);
        $this->assertSame(204, $deleteRes->getStatusCode());
        $this->assertContains('personal_access_token_delete', $loggedTools);
    }

    // ─── 3. WebhookController Quotas Hardening ───

    public function testWebhookControllerRejectsCreationWhenProjectReachesMaxLimit(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $webhookRepo = $this->createMock(WebhookRepository::class);
        $logRepo = $this->createMock(WebhookLogRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $translator = $this->createTranslator();

        $projectUuid = '00000000-0000-4000-8000-000000000001';
        $project = $this->createProject($projectUuid);

        $projectRepo->method('findOneBy')->willReturn($project);
        // Project already has 50 webhooks
        $webhookRepo->method('count')->willReturn(WebhookController::MAX_WEBHOOKS_PER_PROJECT);

        $user = new User();
        $user->email = 'admin@example.com';
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $controller = new WebhookController(
            projectRepository: $projectRepo,
            webhookRepository: $webhookRepo,
            logRepository: $logRepo,
            collectionRepository: $collectionRepo,
            em: $em,
            secretService: $secretService,
            translator: $translator,
        );
        $this->setContainer($controller, $user);

        $request = Request::create("/api/projects/{$projectUuid}/webhooks", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Excess Webhook',
            'url' => 'https://api.external-service.org/webhook',
        ]));

        $response = $controller->create($projectUuid, $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('webhook.limit_exceeded', $data['error']);
        $this->assertSame('WEBHOOK_LIMIT_EXCEEDED', $data['code']);
    }

    // ─── 4. InsightsController Multi-Tenant JSON 404 & 403 Hardening ───

    public function testInsightsControllerReturnsJson404ForUnknownProject(): void
    {
        $insights = $this->createMock(InsightsService::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $translator = $this->createTranslator();

        $projectRepo->method('findOneBy')->willReturn(null);

        $controller = new InsightsController(
            insights: $insights,
            projectRepository: $projectRepo,
            translator: $translator,
        );

        $request = Request::create('/api/projects/00000000-0000-4000-8000-999999999999/insights', 'GET');
        $response = $controller->project('00000000-0000-4000-8000-999999999999', $request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code']);
        $this->assertStringContainsString('insights.project_not_found', $data['error']);
    }

    public function testInsightsControllerBlocksCrossTenantTokenAccess(): void
    {
        $insights = $this->createMock(InsightsService::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $translator = $this->createTranslator();

        $projectA = $this->createProject('00000000-0000-4000-8000-000000000001');
        $projectB = $this->createProject('00000000-0000-4000-8000-000000000002');

        $projectRepo->method('findOneBy')->willReturn($projectA);

        $controller = new InsightsController(
            insights: $insights,
            projectRepository: $projectRepo,
            translator: $translator,
        );

        $request = Request::create('/api/projects/00000000-0000-4000-8000-000000000001/insights', 'GET');
        // Token is scoped to Project B
        $request->attributes->set('_project', $projectB);

        $response = $controller->project('00000000-0000-4000-8000-000000000001', $request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('TOKEN_PROJECT_MISMATCH', $data['code']);
        $this->assertStringContainsString('insights.token_project_mismatch', $data['error']);
    }

    // ─── 5. 100% Strict I18n Parity Across All 4 Locales ───

    public function testTranslationDictionariesHaveStrictParityAndNewKeys(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $this->assertCount(count($en), $fr, 'FR count matches EN');
        $this->assertCount(count($en), $es, 'ES count matches EN');
        $this->assertCount(count($en), $ar, 'AR count matches EN');

        $newKeys = [
            'ai.payload_too_large',
            'token.max_tokens_reached',
            'webhook.limit_exceeded',
            'insights.token_project_mismatch',
        ];

        foreach ($newKeys as $key) {
            $this->assertArrayHasKey($key, $en, "EN contains key {$key}");
            $this->assertArrayHasKey($key, $fr, "FR contains key {$key}");
            $this->assertArrayHasKey($key, $es, "ES contains key {$key}");
            $this->assertArrayHasKey($key, $ar, "AR contains key {$key}");

            $this->assertNotEmpty($en[$key], "EN key {$key} is not empty");
            $this->assertNotEmpty($fr[$key], "FR key {$key} is not empty");
            $this->assertNotEmpty($es[$key], "ES key {$key} is not empty");
            $this->assertNotEmpty($ar[$key], "AR key {$key} is not empty");
        }
    }
}
