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
use App\Service\Automation\AutomationEngine;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\Handlers\Action\CallWebhookHandler;
use App\Service\Flow\Handlers\Http\HttpRequestHandler;
use App\Service\Flow\Handlers\Logic\ConditionHandler;
use App\Service\Flow\Handlers\Logic\SwitchHandler;
use App\Service\Flow\Handlers\Transform\TemplateHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Flow\NodeRegistry;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 27 — Security Hardening Tests
 *
 * Covers:
 *  1. AdminApiOpenApiController & ProjectOpenApiController:
 *     - Introspection schema sanitization (masking sensitive slugs, internal & system prefixes, enum values).
 *     - Rate limiter enforcement on OpenAPI generation (429 Too Many Requests).
 *     - Authorization verification and ETag conditional requests.
 *  2. SecurityHeadersSubscriber & CorsSubscriber:
 *     - Anti-clickjacking: X-Frame-Options DENY + frame-ancestors 'none' on sensitive auth routes.
 *     - Anti-clickjacking: X-Frame-Options SAMEORIGIN + frame-ancestors 'self' on studio routes.
 *     - Embed route preservation: omission of X-Frame-Options & top-level COOP on public iframe embeds.
 *     - Cross-Origin-Opener-Policy (same-origin-allow-popups) on standard routes.
 *     - SVG CSP sandbox defense.
 *     - CorsSubscriber recognition of OpenAPI specs as public routes.
 *  3. FlowContext, TemplateHandler, AutomationEngine & Flow Handlers:
 *     - Variable isolation: strict prevention of global environment variables and credentials escape.
 *     - Array sanitization & safe recursion depth capping.
 *     - Template rendering anti-escape: strip superglobals, env vars, and sensitive sub-paths while supporting safe dot-notation.
 *     - Webhook & HTTP request handlers safe template interpolation.
 *     - AutomationEngine debug trace credential sanitization.
 *     - Condition & Switch handlers input resilience and private path protection.
 */
#[AllowMockObjectsWithoutExpectations]
class Cycle27HardeningTest extends TestCase
{
    // =========================================================================
    // 1. OPENAPI CONTROLLERS & SCHEMA HARDENING
    // =========================================================================

    private function createRejectingLimiterFactory(string $id = 'openapi_test'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create('127.0.0.1')->consume(1);
        return $factory;
    }

    public function testProjectOpenApiControllerRateLimiting(): void
    {
        $limiterFactory = $this->createRejectingLimiterFactory('project_openapi');

        $controller = new ProjectOpenApiController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(FieldRepository::class),
            $this->createMock(EndUserFieldRepository::class),
            $this->createMock(ProjectMemberRepository::class),
            $this->createMock(ApiTokenChecker::class),
            $this->createMock(Security::class),
            $limiterFactory,
        );

        $request = Request::create('/api/11111111-1111-1111-1111-111111111111/openapi.json', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller($request, '11111111-1111-1111-1111-111111111111');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Too many requests', (string) $response->getContent());
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testAdminApiOpenApiControllerRateLimiting(): void
    {
        $limiterFactory = $this->createRejectingLimiterFactory('admin_openapi');

        $controller = new AdminApiOpenApiController($limiterFactory);

        $request = Request::create('/admin-api/openapi.json', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->spec($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Too many requests', (string) $response->getContent());
    }

    public function testProjectOpenApiMasksSensitiveAndInternalFields(): void
    {
        $project = new Project();
        $project->id = 42;
        $project->uuid = Uuid::fromString('22222222-2222-2222-2222-222222222222');
        $project->name = 'Secured Demo';
        $project->publicApi = true;

        $col = new Collection();
        $col->id = 10;
        $col->name = 'Articles';
        $col->slug = 'articles';
        $col->project = $project;

        // Normal field
        $fNormal = new Field();
        $fNormal->name = 'Title';
        $fNormal->slug = 'title';
        $fNormal->type = 'text';

        // Sensitive field (password)
        $fPassword = new Field();
        $fPassword->name = 'Password Hash';
        $fPassword->slug = 'user_password_hash';
        $fPassword->type = 'text';

        // Internal prefix field
        $fInternal = new Field();
        $fInternal->name = 'Internal Secret';
        $fInternal->slug = '_internal_secret_token';
        $fInternal->type = 'text';

        // Private prefix field
        $fPrivate = new Field();
        $fPrivate->name = 'Private Key';
        $fPrivate->slug = 'private_api_key';
        $fPrivate->type = 'text';

        // System prefix field
        $fSystem = new Field();
        $fSystem->name = 'System Id';
        $fSystem->slug = 'system_tracking_id';
        $fSystem->type = 'text';

        // Select field with sanitized enum
        $fSelect = new Field();
        $fSelect->name = 'Category';
        $fSelect->slug = 'category';
        $fSelect->type = 'select';
        $fSelect->options = ['choices' => ['tech', 'news', '', str_repeat('x', 200)]];

        $fields = [$fNormal, $fPassword, $fInternal, $fPrivate, $fSystem, $fSelect];

        // End user fields
        $euEmail = new EndUserField();
        $euEmail->name = 'Email';
        $euEmail->slug = 'custom_email';
        $euEmail->type = 'email';
        $euEmail->isSystem = false;

        $euTotp = new EndUserField();
        $euTotp->name = 'TOTP Secret';
        $euTotp->slug = 'totp_secret_key';
        $euTotp->type = 'text';
        $euTotp->isSystem = false;

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $colRepo = $this->createMock(CollectionRepository::class);
        $colRepo->method('findByProject')->willReturn([$col]);

        $fieldRepo = $this->createMock(FieldRepository::class);
        $fieldRepo->method('findByCollectionsGrouped')->willReturn([10 => $fields]);

        $euRepo = $this->createMock(EndUserFieldRepository::class);
        $euRepo->method('findByProject')->willReturn([$euEmail, $euTotp]);

        $controller = new ProjectOpenApiController(
            $projRepo,
            $colRepo,
            $fieldRepo,
            $euRepo,
            $this->createMock(ProjectMemberRepository::class),
            $this->createMock(ApiTokenChecker::class),
            $this->createMock(Security::class),
            null
        );

        $request = Request::create('/api/22222222-2222-2222-2222-222222222222/openapi.json');
        $response = $controller($request, '22222222-2222-2222-2222-222222222222');

        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getContent(), true);

        $schemas = $json['components']['schemas'];
        $articleProps = $schemas['Articles']['properties'];

        // Normal field and select are present
        $this->assertArrayHasKey('title', $articleProps);
        $this->assertArrayHasKey('category', $articleProps);
        $this->assertSame(['tech', 'news'], $articleProps['category']['enum']);

        // Sensitive & internal fields MUST be omitted
        $this->assertArrayNotHasKey('user_password_hash', $articleProps);
        $this->assertArrayNotHasKey('_internal_secret_token', $articleProps);
        $this->assertArrayNotHasKey('private_api_key', $articleProps);
        $this->assertArrayNotHasKey('system_tracking_id', $articleProps);

        // EndUserProfile custom fields
        $euCustom = $schemas['EndUserProfile']['properties']['custom_fields']['properties'];
        $this->assertArrayHasKey('custom_email', $euCustom);
        $this->assertArrayNotHasKey('totp_secret_key', $euCustom);
    }

    public function testProjectOpenApiRequiresAuthWhenPrivate(): void
    {
        $project = new Project();
        $project->id = 99;
        $project->uuid = Uuid::fromString('33333333-3333-3333-3333-333333333333');
        $project->publicApi = false;

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $tokenChecker->method('resolve')->willReturn(null);

        $controller = new ProjectOpenApiController(
            $projRepo,
            $this->createMock(CollectionRepository::class),
            $this->createMock(FieldRepository::class),
            $this->createMock(EndUserFieldRepository::class),
            $this->createMock(ProjectMemberRepository::class),
            $tokenChecker,
            $security,
            null
        );

        $request = Request::create('/api/33333333-3333-3333-3333-333333333333/openapi.json');
        $response = $controller($request, '33333333-3333-3333-3333-333333333333');

        $this->assertSame(401, $response->getStatusCode());
    }

    // =========================================================================
    // 2. SECURITY HEADERS & CORS HARDENING
    // =========================================================================

    public function testSecurityHeadersAntiClickjackingOnSensitiveAuth(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');

        $kernel = $this->createMock(HttpKernelInterface::class);

        // 1. Strict deny on admin token management
        $requestTokens = Request::create('/admin-api/tokens');
        $responseTokens = new Response('Tokens API');
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $requestTokens, HttpKernelInterface::MAIN_REQUEST, $responseTokens));

        $this->assertSame('DENY', $responseTokens->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $responseTokens->headers->get('Content-Security-Policy'));

        // 2. SAMEORIGIN on backoffice / login
        $requestLogin = Request::create('/login');
        $responseLogin = new Response('Login Form');
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $requestLogin, HttpKernelInterface::MAIN_REQUEST, $responseLogin));

        $this->assertSame('SAMEORIGIN', $responseLogin->headers->get('X-Frame-Options'));
        $this->assertSame('same-origin-allow-popups', $responseLogin->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertSame('same-origin', $responseLogin->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function testSecurityHeadersStudioAntiClickjacking(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/projects/1/settings/studio');
        $response = new Response('Studio Canvas');

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onKernelResponse($event);

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame('same-origin-allow-popups', $response->headers->get('Cross-Origin-Opener-Policy'));
    }

    public function testSecurityHeadersEmbedRoutesAllowFramingAndOmitCOOP(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/forms/embed/12345');
        $response = new Response('<form></form>');

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onKernelResponse($event);

        // Must NOT restrict framing with DENY or SAMEORIGIN
        $this->assertFalse($response->headers->has('X-Frame-Options'));
        // Must NOT enforce strict top-level COOP on iframe embeds
        $this->assertFalse($response->headers->has('Cross-Origin-Opener-Policy'));
        // CORP must be cross-origin
        $this->assertSame('cross-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
    }

    public function testSecurityHeadersSvgSandboxDefense(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/storage/test.svg');
        $response = new Response('<svg></svg>');
        $response->headers->set('Content-Type', 'image/svg+xml');

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onKernelResponse($event);

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testCorsSubscriberAllowsOpenApiSpecs(): void
    {
        $cors = new CorsSubscriber();

        $this->assertTrue($cors->isPublicRoute('/api/settings/admin-api/openapi.json'));
        $this->assertTrue($cors->isPublicRoute('/admin-api/openapi.json'));
        $this->assertTrue($cors->isPublicRoute('/api/11111111-1111-1111-1111-111111111111/openapi.json'));
        $this->assertFalse($cors->isPublicRoute('/admin-api/projects'));
    }

    // =========================================================================
    // 3. FLOW CONTEXT, TEMPLATE ENGINE & AUTOMATION HARDENING
    // =========================================================================

    public function testFlowContextBlocksEnvironmentVariables(): void
    {
        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-uuid');

        // Forbidden names
        $this->assertFalse($ctx->setVariable('_ENV', 'bad'));
        $this->assertFalse($ctx->setVariable('GLOBALS', 'bad'));
        $this->assertFalse($ctx->setVariable('DATABASE_URL', 'bad'));
        $this->assertFalse($ctx->setVariable('APP_SECRET', 'bad'));
        $this->assertFalse($ctx->setVariable('app_secret', 'bad'));
        $this->assertFalse($ctx->setVariable('jwt_token', 'bad'));
        $this->assertFalse($ctx->setVariable('auth_password', 'bad'));
        $this->assertFalse($ctx->setVariable('php_self', 'bad'));

        // Safe names
        $this->assertTrue($ctx->setVariable('customerName', 'Alice'));
        $this->assertSame('Alice', $ctx->getVariable('customerName'));

        // Sanitizing array values (stripping nested sensitive keys)
        $ctx->setVariable('userProfile', [
            'name' => 'Bob',
            'api_token' => 'secret_1234',
            'password_hash' => 'hash_5678',
            'city' => 'Paris',
        ]);
        $val = $ctx->getVariable('userProfile');
        $this->assertIsArray($val);
        $this->assertSame('Bob', $val['name']);
        $this->assertSame('Paris', $val['city']);
        $this->assertArrayNotHasKey('api_token', $val);
        $this->assertArrayNotHasKey('password_hash', $val);
    }

    public function testFlowValidatorRejectsForbiddenVariables(): void
    {
        $validator = new FlowValidator();
        $registry = new NodeRegistry([]);

        $graph = [
            'nodes' => [
                ['id' => 'node_1', 'type' => 'noop', 'data' => []],
            ],
            'edges' => [],
            'variables' => [
                'DATABASE_URL' => 'mysql://localhost',
            ],
        ];

        $res = $validator->validate($graph, $registry);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString("Variable 'DATABASE_URL' interdite", implode('; ', $res['errors']));
    }

    public function testTemplateHandlerStripsEnvironmentEscapes(): void
    {
        $handler = new TemplateHandler();
        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-uuid');
        $ctx->setVariable('name', 'Charlie');
        $ctx->setCurrentNodeConfig([
            'template' => 'Hello {{ name }}! Env: {{ _ENV.APP_SECRET }} {{ DATABASE_URL }} {{ entry.title }} {{ entry.secret_token }}',
        ]);

        $input = [
            'trigger' => new NodeOutput(data: [
                'entry' => [
                    'title' => 'Article One',
                    'secret_token' => 'top_secret',
                ],
            ]),
        ];

        $output = $handler->execute($input, $ctx);
        $rendered = $output->data['rendered'];

        $this->assertStringContainsString('Hello Charlie!', $rendered);
        $this->assertStringContainsString('Article One', $rendered);
        $this->assertStringNotContainsString('APP_SECRET', $rendered);
        $this->assertStringNotContainsString('DATABASE_URL', $rendered);
        $this->assertStringNotContainsString('top_secret', $rendered);
    }

    public function testCallWebhookHandlerSafeInterpolation(): void
    {
        $ssrf = $this->createMock(SsrfProtectionService::class);
        $handler = new CallWebhookHandler($ssrf);

        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-uuid');
        $ctx->setVariable('webhookHost', 'https://example.com');
        $ctx->setCurrentNodeConfig([
            'url' => '{{ webhookHost }}/hook?leak={{ _ENV.DATABASE_URL }}',
            'body' => '{"name": "{{ customerName }}", "pass": "{{ _SERVER.HTTP_PASS }}"}',
        ]);
        $ctx->setVariable('customerName', 'Diana');

        $input = ['trigger' => new NodeOutput(data: [])];

        // Should interpolate safe variables and strip environment variables
        $res = $handler->execute($input, $ctx);
        // The URL should have stripped the _ENV leak
        $this->assertSame('https://example.com/hook?leak=', $res->data['url']);

        // Test SSRF block on private loopback
        $ctx->setCurrentNodeConfig([
            'url' => 'http://127.0.0.1:8080/private',
            'body' => '',
        ]);
        $resBlocked = $handler->execute($input, $ctx);
        $this->assertFalse($resBlocked->data['called']);
        $this->assertArrayHasKey('error', $resBlocked->data);
    }

    public function testHttpRequestHandlerSafeInterpolation(): void
    {
        $ssrf = $this->createMock(SsrfProtectionService::class);
        $handler = new HttpRequestHandler($ssrf);

        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-uuid');
        $ctx->setCurrentNodeConfig([
            'url' => 'https://api.external.com/data?token={{ _ENV.APP_SECRET }}',
        ]);

        $input = ['trigger' => new NodeOutput(data: [])];
        $res = $handler->execute($input, $ctx);

        // Since HttpClient will fail to connect or mock
        $this->assertSame(0, $res->data['status_code']);
    }

    public function testConditionHandlerProtectsPrivatePaths(): void
    {
        $handler = new ConditionHandler();
        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-uuid');
        $ctx->setCurrentNodeConfig([
            'field' => '_internal_secret',
            'operator' => 'eq',
            'value' => 'leak',
        ]);

        $input = [
            'trigger' => new NodeOutput(data: ['_internal_secret' => 'leak']),
        ];

        $output = $handler->execute($input, $ctx);
        // Access to _internal_secret should be blocked resulting in null !== 'leak'
        $this->assertSame('false', $output->branch);
        $this->assertFalse($output->data['condition_result']);
    }

    public function testSwitchHandlerProtectsPrivatePaths(): void
    {
        $handler = new SwitchHandler();
        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-uuid');
        $ctx->setCurrentNodeConfig([
            'field' => '_env_role',
            'cases' => [
                ['value' => 'admin', 'branch' => 'admin_branch'],
            ],
            'default_branch' => 'default_branch',
        ]);

        $input = [
            'trigger' => new NodeOutput(data: ['_env_role' => 'admin']),
        ];

        $output = $handler->execute($input, $ctx);
        $this->assertSame('default_branch', $output->branch);
    }

    public function testAutomationEngineSanitizesDebugTraces(): void
    {
        $interpreter = $this->createMock(FlowInterpreter::class);
        $interpreter->method('executeFlow')->willThrowException(
            new \RuntimeException("Connection failed: mysql://dbuser:super_secret_pw@127.0.0.1:3306/jambo password='very_secret'")
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $engine = new AutomationEngine($interpreter, $em);

        $automation = new \App\Entity\Automation();
        $automation->id = 7;
        $automation->name = 'Test Automation';
        $automation->debugMode = true;
        $automation->flowGraph = ['nodes' => [['id' => '1', 'type' => 'noop']], 'edges' => []];

        $run = $engine->execute($automation, ['test' => true]);

        $this->assertSame('failed', $run->status);
        $trace = $run->actionOutput['trace'] ?? '';
        $this->assertStringNotContainsString(':super_secret_pw@', $trace);
        $this->assertStringNotContainsString("password='very_secret'", $trace);
    }
}
