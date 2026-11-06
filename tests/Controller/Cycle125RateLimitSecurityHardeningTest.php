<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminApi\ApiKeyController;
use App\Controller\AdminApi\PingController;
use App\Controller\Api\CaptchaController;
use App\Controller\Api\CollectionController as ApiCollectionController;
use App\Controller\Api\ContentController as ApiContentController;
use App\Controller\Api\ProjectInfoController;
use App\Controller\Api\ProjectOpenApiController;
use App\Controller\AuditController;
use App\Controller\CollectionTemplateController;
use App\Controller\InstallController;
use App\Controller\MercureFallbackController;
use App\Controller\ProjectPluginController;
use App\Controller\ProjectTemplateController;
use App\Controller\Public\RedirectPublicController;
use App\Controller\Public\SeoPublicController;
use App\Entity\Project;
use App\Repository\ApiTokenRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CollectionRepository;
use App\Repository\CollectionTemplateRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\EndUserFieldRepository;
use App\Repository\EndUserRepository;
use App\Repository\FieldRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\EavDataFormatterService;
use App\Service\EavFieldHelperService;
use App\Service\FieldValueHydrator;
use App\Service\Plugin\PluginManager;
use App\Service\ProjectTemplateBuilder;
use App\Service\Redirect\RedirectResolver;
use App\Service\Seo\SitemapGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle125RateLimitSecurityHardeningTest extends TestCase
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

    public function testRedirectPublicControllerReturnsRateLimitHeadersOn429(): void
    {
        $projRepo = $this->createMock(ProjectRepository::class);
        $resolver = $this->createMock(RedirectResolver::class);
        $limiter = $this->createRejectingLimiterFactory('redirect_test');

        $controller = new RedirectPublicController($projRepo, $resolver, null, $limiter);
        $request = Request::create('/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/redirects/resolve', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->resolve('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testSeoPublicControllerReturnsRateLimitHeadersOn429(): void
    {
        $projRepo = $this->createMock(ProjectRepository::class);
        $generator = $this->createMock(SitemapGenerator::class);
        $limiter = $this->createRejectingLimiterFactory('seo_test');

        $controller = new SeoPublicController($projRepo, $generator, null, $limiter);
        $request = Request::create('/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/sitemap.xml', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->sitemap('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testMercureFallbackControllerReturnsRateLimitHeadersOn429(): void
    {
        $limiter = $this->createRejectingLimiterFactory('mercure_test');
        $controller = new MercureFallbackController($limiter);
        $request = Request::create('/.well-known/mercure', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->handle($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testAuditControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $auditRepo = $this->createMock(AuditLogRepository::class);
        $limiter = $this->createRejectingLimiterFactory('audit_test');

        $controller = new AuditController($em, $auditRepo, null, null, $limiter);
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/audit-logs', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->list('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testProjectPluginControllerReturnsRateLimitHeadersOn429(): void
    {
        $projRepo = $this->createMock(ProjectRepository::class);
        $pm = $this->createMock(PluginManager::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $auditService = $this->createMock(AuditService::class);
        $limiter = $this->createRejectingLimiterFactory('plugin_test');

        $controller = new ProjectPluginController($projRepo, $pm, $em, $auditService, $limiter);
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/plugins', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->list('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testProjectTemplateControllerReturnsRateLimitHeadersOn429(): void
    {
        $tmplRepo = $this->createMock(ProjectTemplateRepository::class);
        $builder = $this->createMock(ProjectTemplateBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $limiter = $this->createRejectingLimiterFactory('tmpl_test');

        $controller = new ProjectTemplateController($tmplRepo, $builder, $em, null, $limiter);
        $request = Request::create('/api/project-templates', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testCollectionTemplateControllerReturnsRateLimitHeadersOn429(): void
    {
        $ctRepo = $this->createMock(CollectionTemplateRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $projRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $limiter = $this->createRejectingLimiterFactory('col_tmpl_test');

        $controller = new CollectionTemplateController($ctRepo, $colRepo, $projRepo, $em, null, $limiter);
        $request = Request::create('/api/collection-templates', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testAdminApiApiKeyControllerReturnsRateLimitHeadersOn429(): void
    {
        $apiRepo = $this->createMock(ApiTokenRepository::class);
        $projRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $limiter = $this->createRejectingLimiterFactory('admin_key_test');

        $controller = new ApiKeyController($projRepo, $apiRepo, $em, 'test_secret', $limiter, null);
        $request = Request::create('/admin-api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/api-keys', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testAdminApiPingControllerReturnsRateLimitHeadersOn429(): void
    {
        $limiter = $this->createRejectingLimiterFactory('admin_ping_test');

        $controller = new PingController($limiter);
        $request = Request::create('/admin-api/ping', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->ping($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testApiCaptchaControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $cache = $this->createMock(CacheInterface::class);
        $projRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $em->method('getRepository')->willReturn($projRepo);
        $projRepo->method('findOneBy')->willReturn($project);
        $limiter = $this->createRejectingLimiterFactory('captcha_test');

        $controller = new CaptchaController($em, $cache, $limiter);
        $request = Request::create('/api/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/captcha', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->captcha('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testApiCollectionControllerReturnsRateLimitHeadersOn429(): void
    {
        $colRepo = $this->createMock(CollectionRepository::class);
        $checker = $this->createMock(ApiTokenChecker::class);
        $limiter = $this->createRejectingLimiterFactory('api_col_test');

        $controller = new ApiCollectionController($checker, $colRepo, null, $limiter);
        $request = Request::create('/public-api/collections', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testApiContentControllerReturnsRateLimitHeadersOn429(): void
    {
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $projRepo = $this->createMock(ProjectRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $checker = $this->createMock(ApiTokenChecker::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $hydrator = $this->createMock(FieldValueHydrator::class);
        $helper = $this->createMock(EavFieldHelperService::class);
        $slugger = $this->createMock(SluggerInterface::class);
        $limiter = $this->createRejectingLimiterFactory('api_content_test', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');

        $controller = new ApiContentController($checker, $projRepo, $colRepo, $entryRepo, $fieldRepo, $mediaRepo, $endUserRepo, $formatter, $em, $memberRepo, $hydrator, $helper, $slugger, null, $limiter);
        $request = Request::create('/api/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/posts', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index($request, 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'posts');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testApiProjectInfoControllerReturnsRateLimitHeadersOn429(): void
    {
        $projRepo = $this->createMock(ProjectRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $limiter = $this->createRejectingLimiterFactory('api_info_test');

        $controller = new ProjectInfoController($projRepo, $colRepo, null, $limiter);
        $request = Request::create('/api/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->show('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testApiProjectOpenApiControllerReturnsRateLimitHeadersOn429(): void
    {
        $projRepo = $this->createMock(ProjectRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $endUserFieldRepo = $this->createMock(EndUserFieldRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $checker = $this->createMock(ApiTokenChecker::class);
        $security = $this->createMock(Security::class);
        $limiter = $this->createRejectingLimiterFactory('api_openapi_test');

        $controller = new ProjectOpenApiController($projRepo, $colRepo, $fieldRepo, $endUserFieldRepo, $memberRepo, $checker, $security, $limiter, null);
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/openapi.json', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller($request, 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testInstallControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(UserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Rate limited');
        $limiter = $this->createRejectingLimiterFactory('install_test');

        $controller = new InstallController($em, $userRepo, $hasher, sys_get_temp_dir(), $limiter, $translator);
        $request = Request::create('/install/setup-admin', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], json_encode(['email' => 'admin@test.com', 'password' => 'Passw0rd123!']));
        $response = $controller->setupAdmin($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
    }

    public function testTranslationSymmetryAcrossAllFourLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $dicts = [];
        foreach ($locales as $loc) {
            $path = __DIR__ . "/../../translations/messages.{$loc}.php";
            $this->assertFileExists($path);
            $dicts[$loc] = require $path;
            $this->assertIsArray($dicts[$loc]);
        }

        $enCount = count($dicts['en']);
        foreach ($locales as $loc) {
            $this->assertCount(
                $enCount,
                $dicts[$loc],
                sprintf('Locale %s must have exactly %d keys, got %d', $loc, $enCount, count($dicts[$loc]))
            );
        }

        $enKeys = array_keys($dicts['en']);
        foreach (['fr', 'es', 'ar'] as $otherLoc) {
            $diff = array_diff($enKeys, array_keys($dicts[$otherLoc]));
            $this->assertEmpty($diff, sprintf('Locale %s is missing keys: %s', $otherLoc, implode(', ', array_slice($diff, 0, 5))));
        }
    }
}
