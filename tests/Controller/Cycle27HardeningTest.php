<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\ProjectOpenApiController;
use App\Controller\Settings\AdminApiOpenApiController;
use App\Entity\Collection;
use App\Entity\EndUserField;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\EventSubscriber\CorsSubscriber;
use App\EventSubscriber\SecurityHeadersSubscriber;
use App\Repository\CollectionRepository;
use App\Repository\EndUserFieldRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\Handlers\Transform\SetHandler;
use App\Service\Flow\Handlers\Transform\TemplateHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Flow\NodeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 27 Hardening Tests
 *
 * Covers:
 *  1. AdminApiOpenApiController & ProjectOpenApiController rate limiting & schema sanitization.
 *  2. SecurityHeadersSubscriber anti-clickjacking (DENY on sensitive auth, frame-ancestors on studio), COOP and CORP.
 *  3. CorsSubscriber apex matching and restricted credential security.
 *  4. FlowContext, FlowValidator, TemplateHandler strict environment isolation and variable sandboxing.
 */
class Cycle27HardeningTest extends TestCase
{
    private function makeResponseEvent(Request $request, Response $response, bool $mainRequest = true): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ResponseEvent(
            $kernel,
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response,
        );
    }

    private function makeRequestEvent(Request $request, bool $mainRequest = true): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent(
            $kernel,
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }

    private function createExhaustedLimiter(string $id = 'test'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create('127.0.0.1')->consume(1);
        return $factory;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. AdminApiOpenApiController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testAdminApiSpecReturnsValidOpenApi3StructureAndCacheControl(): void
    {
        $controller = new AdminApiOpenApiController();
        $request = Request::create('/api/settings/admin-api/openapi.json', 'GET');

        $response = $controller->spec($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Cache-Control'));
        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('3.0.3', $data['openapi']);
        $this->assertArrayHasKey('components', $data);
        $this->assertArrayHasKey('schemas', $data['components']);
        $this->assertArrayHasKey('Project', $data['components']['schemas']);
        $this->assertArrayHasKey('Token', $data['components']['schemas']);

        // Check internal fields are hidden
        $projectProps = $data['components']['schemas']['Project']['properties'];
        $this->assertArrayNotHasKey('databaseUrl', $projectProps);
        $this->assertArrayNotHasKey('mailerDsn', $projectProps);
        $this->assertArrayNotHasKey('id', $projectProps);

        $tokenProps = $data['components']['schemas']['Token']['properties'];
        $this->assertArrayNotHasKey('tokenHash', $tokenProps);
    }

    public function testAdminApiSpecRateLimitingWhenQuotaExceeded(): void
    {
        $factory = $this->createExhaustedLimiter('admin_api_test');
        $controller = new AdminApiOpenApiController($factory);
        $request = Request::create('/api/settings/admin-api/openapi.json', 'GET');

        $response = $controller->spec($request);
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Too many requests', $data['error']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. ProjectOpenApiController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testProjectSpecOptionsPreflightReturnsCorsNoBody(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $endUserFieldRepo = $this->createMock(EndUserFieldRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $security = $this->createMock(Security::class);

        $controller = new ProjectOpenApiController(
            $projectRepo,
            $colRepo,
            $fieldRepo,
            $endUserFieldRepo,
            $memberRepo,
            $tokenChecker,
            $security,
        );

        $request = Request::create('/api/00000000-0000-0000-0000-000000000001/openapi.json', 'OPTIONS');
        $response = $controller($request, '00000000-0000-0000-0000-000000000001');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('GET', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function testProjectSpecRateLimitingReturns429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $endUserFieldRepo = $this->createMock(EndUserFieldRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $security = $this->createMock(Security::class);

        $factory = $this->createExhaustedLimiter('project_api_test');

        $controller = new ProjectOpenApiController(
            $projectRepo,
            $colRepo,
            $fieldRepo,
            $endUserFieldRepo,
            $memberRepo,
            $tokenChecker,
            $security,
            $factory,
        );

        $request = Request::create('/api/00000000-0000-0000-0000-000000000001/openapi.json', 'GET');
        $response = $controller($request, '00000000-0000-0000-0000-000000000001');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Too many requests', $data['error']);
    }

    public function testProjectSpecMasksSensitiveFieldsAndDeletedFields(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);
        $project->name = 'Test Sandbox';
        $project->publicApi = true;

        $collection = new Collection();
        $collection->id = 1;
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;

        // Normal field
        $normalField = new Field();
        $normalField->id = 1;
        $normalField->name = 'Title';
        $normalField->slug = 'title';
        $normalField->type = 'text';
        $normalField->collection = $collection;

        // Soft-deleted field
        $deletedField = new Field();
        $deletedField->id = 2;
        $deletedField->name = 'Old Internal';
        $deletedField->slug = 'old_internal';
        $deletedField->type = 'text';
        $deletedField->collection = $collection;
        $deletedField->deletedAt = new \DateTimeImmutable('-1 hour');

        // Sensitive field
        $sensitiveField = new Field();
        $sensitiveField->id = 3;
        $sensitiveField->name = 'Secret Key';
        $sensitiveField->slug = 'secret_token';
        $sensitiveField->type = 'text';
        $sensitiveField->collection = $collection;

        // Internal prefix field
        $internalField = new Field();
        $internalField->id = 4;
        $internalField->name = 'Internal Sys';
        $internalField->slug = '_internal_node';
        $internalField->type = 'text';
        $internalField->collection = $collection;

        // End user fields
        $normalEuField = new EndUserField();
        $normalEuField->id = 1;
        $normalEuField->name = 'Company';
        $normalEuField->slug = 'company';
        $normalEuField->type = 'text';
        $normalEuField->isSystem = false;

        $sensitiveEuField = new EndUserField();
        $sensitiveEuField->id = 2;
        $sensitiveEuField->name = 'Password Hash';
        $sensitiveEuField->slug = 'password_hash';
        $sensitiveEuField->type = 'text';
        $sensitiveEuField->isSystem = false;

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $colRepo = $this->createMock(CollectionRepository::class);
        $colRepo->method('findByProject')->willReturn([$collection]);

        $fieldRepo = $this->createMock(FieldRepository::class);
        $fieldRepo->method('findByCollectionsGrouped')->willReturn([
            $collection->id => [$normalField, $deletedField, $sensitiveField, $internalField],
        ]);

        $endUserFieldRepo = $this->createMock(EndUserFieldRepository::class);
        $endUserFieldRepo->method('findByProject')->willReturn([$normalEuField, $sensitiveEuField]);

        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $security = $this->createMock(Security::class);

        $controller = new ProjectOpenApiController(
            $projectRepo,
            $colRepo,
            $fieldRepo,
            $endUserFieldRepo,
            $memberRepo,
            $tokenChecker,
            $security,
        );

        $request = Request::create('/api/' . $projectUuid . '/openapi.json', 'GET');
        $response = $controller($request, $projectUuid);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);

        // Check Articles schema
        $articleSchema = $data['components']['schemas']['Articles']['properties'];
        $this->assertArrayHasKey('title', $articleSchema);
        $this->assertArrayNotHasKey('old_internal', $articleSchema);
        $this->assertArrayNotHasKey('secret_token', $articleSchema);
        $this->assertArrayNotHasKey('_internal_node', $articleSchema);

        // Check EndUserProfile schema
        $euProperties = $data['components']['schemas']['EndUserProfile']['properties']['custom_fields']['properties'];
        $this->assertArrayHasKey('company', $euProperties);
        $this->assertArrayNotHasKey('password_hash', $euProperties);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. SecurityHeadersSubscriber & Anti-Clickjacking Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testStrictAntiClickjackingEnforcesSameOriginAcrossEndpoints(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $paths = [
            '/login',
            '/register',
            '/auth/reset-password',
            '/install',
            '/api/settings/admin-api/tokens',
            '/admin-api/projects',
        ];

        foreach ($paths as $path) {
            $request = Request::create($path);
            $response = new Response('{}', 200, ['Content-Type' => 'text/html']);
            $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));

            $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'), "Path {$path} should have X-Frame-Options: SAMEORIGIN");
        }
    }

    public function testStudioRouteEnforcesFrameAncestorsSelfClickjackingDefense(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/projects/1/settings/studio');
        $response = new Response('<html>Studio</html>', 200, ['Content-Type' => 'text/html']);
        $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame("frame-ancestors 'self';", $response->headers->get('Content-Security-Policy'));
    }

    public function testEmbedRoutesOmitXFrameOptionsToAllowEmbedding(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $embedPaths = [
            '/forms/embed.js',
            '/share/abc12345',
            '/embed/widget',
        ];

        foreach ($embedPaths as $path) {
            $request = Request::create($path);
            $response = new Response('// embed', 200, ['Content-Type' => 'application/javascript']);
            $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));

            $this->assertNull($response->headers->get('X-Frame-Options'), "Path {$path} should omit X-Frame-Options for safe embedding");
        }
    }

    public function testCrossOriginOpenerPolicyIsSetGlobally(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/content');
        $response = new Response('{}', 200, ['Content-Type' => 'application/json']);
        $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));

        $this->assertSame('same-origin-allow-popups', $response->headers->get('Cross-Origin-Opener-Policy'));
    }

    public function testCrossOriginResourcePolicyPartitioning(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');

        // Public API route -> cross-origin
        $publicReq = Request::create('/api/projects/1/openapi.json');
        $publicResp = new Response('{}', 200, ['Content-Type' => 'application/json']);
        $subscriber->onKernelResponse($this->makeResponseEvent($publicReq, $publicResp));
        $this->assertSame('cross-origin', $publicResp->headers->get('Cross-Origin-Resource-Policy'));

        // Admin internal route -> same-origin
        $adminReq = Request::create('/projects/1/settings/studio');
        $adminResp = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $subscriber->onKernelResponse($this->makeResponseEvent($adminReq, $adminResp));
        $this->assertSame('same-origin', $adminResp->headers->get('Cross-Origin-Resource-Policy'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. CorsSubscriber Wildcard & Subdomain Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testCorsWildcardSubdomainMatchesApexAndSubdomains(): void
    {
        $previousEnv = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*.jambostack.site';

        try {
            $subscriber = new CorsSubscriber();
            $request = Request::create('/admin-api/projects', 'GET');

            $this->assertTrue($subscriber->isAllowedOrigin('https://jambostack.site', $request));
            $this->assertTrue($subscriber->isAllowedOrigin('https://sub.jambostack.site', $request));
            $this->assertTrue($subscriber->isAllowedOrigin('https://deep.sub.jambostack.site', $request));
            $this->assertFalse($subscriber->isAllowedOrigin('https://evil-jambostack.site', $request));
            $this->assertFalse($subscriber->isAllowedOrigin('https://otherdomain.com', $request));
        } finally {
            if ($previousEnv !== null) {
                $_ENV['CORS_ALLOWED_ORIGINS'] = $previousEnv;
            } else {
                unset($_ENV['CORS_ALLOWED_ORIGINS']);
            }
        }
    }

    public function testCorsRestrictedOriginWithWildcardNeverAllowsCredentials(): void
    {
        $subscriber = new CorsSubscriber();
        $request = Request::create('/admin-api/projects', 'GET', [], [], [], [
            'HTTP_ORIGIN' => '*',
        ]);
        $response = new Response('{}', 200);

        $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. FlowContext & Variable Cloisonnement Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testFlowValidatorRejectsForbiddenOrSensitiveVariables(): void
    {
        $registry = new NodeRegistry();
        $validator = new FlowValidator();

        $invalidGraph = [
            'nodes' => [
                ['id' => 'n1', 'type' => 'trigger.cron', 'data' => ['label' => 'Cron', 'config' => []]],
                ['id' => 'n2', 'type' => 'util.log', 'data' => ['label' => 'Log', 'config' => []]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'n1', 'target' => 'n2'],
            ],
            'variables' => [
                '_ENV' => 'attack',
                'APP_SECRET' => 'leak',
                'DATABASE_URL' => 'leak',
            ],
        ];

        $res = $validator->validate($invalidGraph, $registry);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString("Variable '_ENV' invalide", implode('; ', $res['errors']));
    }

    public function testFlowValidatorAcceptsCleanVariables(): void
    {
        $registry = $this->createMock(NodeRegistry::class);
        $handler = $this->createMock(FlowNodeHandler::class);
        $registry->method('resolve')->willReturn($handler);
        $validator = new FlowValidator();

        $validGraph = [
            'nodes' => [
                ['id' => 'n1', 'type' => 'trigger.cron', 'data' => ['label' => 'Cron', 'config' => []]],
                ['id' => 'n2', 'type' => 'util.log', 'data' => ['label' => 'Log', 'config' => []]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'n1', 'target' => 'n2'],
            ],
            'variables' => [
                'recipientName' => 'Alice',
                'defaultCategory' => 'Support',
            ],
        ];

        $res = $validator->validate($validGraph, $registry);
        $this->assertTrue($res['valid'], implode('; ', $res['errors']));
    }

    public function testFlowContextVariableSandboxing(): void
    {
        $ctx = new FlowContext(automationId: 1, projectUuid: '00000000-0000-0000-0000-000000000001');

        $this->assertFalse($ctx->setVariable('_ENV', 'malicious'));
        $this->assertFalse($ctx->setVariable('GLOBALS', 'malicious'));
        $this->assertFalse($ctx->setVariable('APP_SECRET', 'leak'));
        $this->assertFalse($ctx->setVariable('database_url', 'leak'));
        $this->assertFalse($ctx->setVariable('my_token', 'leak'));

        $this->assertTrue($ctx->setVariable('greeting', 'Hello World'));
        $this->assertSame('Hello World', $ctx->getVariable('greeting'));

        // Internal config setter does not leak into safe variables
        $ctx->setCurrentNodeConfig(['secret' => 'internal_cfg']);
        $safeVars = $ctx->getSafeVariables();
        $this->assertArrayHasKey('greeting', $safeVars);
        $this->assertArrayNotHasKey('_node_config', $safeVars);
    }

    public function testFlowContextSanitizesStepLogAndRedactsSecrets(): void
    {
        $ctx = new FlowContext(automationId: 1, projectUuid: '00000000-0000-0000-0000-000000000001', debugMode: true);
        $ctx->logStep(
            nodeId: 'n1',
            type: 'http.request',
            label: 'Call External API',
            status: 'success',
            durationMs: 45,
            input: ['password' => 'super_secret', 'user' => 'admin', 'auth_token' => 'bearer_123'],
            output: ['status' => 200, 'api_key' => 'key_xyz', 'data' => ['name' => 'Demo']],
        );

        $sanitized = $ctx->getSanitizedStepLog();
        $this->assertCount(1, $sanitized);

        $step = $sanitized[0];
        $this->assertSame('***REDACTED***', $step['input']['password']);
        $this->assertSame('admin', $step['input']['user']);
        $this->assertSame('***REDACTED***', $step['input']['auth_token']);
        $this->assertSame('***REDACTED***', $step['output']['api_key']);
        $this->assertSame('Demo', $step['output']['data']['name']);
    }

    public function testTemplateHandlerStripsGlobalEnvVariablesAndEvaluatesContext(): void
    {
        $handler = new TemplateHandler();
        $ctx = new FlowContext(automationId: 1, projectUuid: '00000000-0000-0000-0000-000000000001');
        $ctx->setVariable('companyName', 'Acme Corp');

        $ctx->setCurrentNodeConfig([
            'template' => 'Bonjour {{ name }}, bienvenue chez {{ companyName }}. Env leak: {{ _ENV.APP_SECRET }} {{ env.DATABASE_URL }} {{ GLOBALS }}',
        ]);

        $input = [
            'trigger' => new NodeOutput(data: ['name' => 'Bob']),
        ];

        $output = $handler->execute($input, $ctx);
        $rendered = $output->data['rendered'];

        $this->assertStringContainsString('Bonjour Bob', $rendered);
        $this->assertStringContainsString('bienvenue chez Acme Corp', $rendered);
        $this->assertStringNotContainsString('{{ _ENV.APP_SECRET }}', $rendered);
        $this->assertStringNotContainsString('{{ env.DATABASE_URL }}', $rendered);
        $this->assertStringNotContainsString('{{ GLOBALS }}', $rendered);
    }

    public function testSetHandlerEnforcesContextVariableIsolation(): void
    {
        $handler = new SetHandler();
        $ctx = new FlowContext(automationId: 1, projectUuid: '00000000-0000-0000-0000-000000000001');

        // Trying to set forbidden variable
        $ctx->setCurrentNodeConfig(['key' => '_ENV', 'value' => 'hacked']);
        $handler->execute(['default' => new NodeOutput(data: ['status' => 'ok'])], $ctx);
        $this->assertNull($ctx->getVariable('_ENV'));

        // Setting legitimate variable
        $ctx->setCurrentNodeConfig(['key' => 'orderStatus', 'value' => 'completed']);
        $handler->execute(['default' => new NodeOutput(data: ['status' => 'ok'])], $ctx);
        $this->assertSame('completed', $ctx->getVariable('orderStatus'));
    }
}
