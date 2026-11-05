<?php

namespace App\Tests\Service;

use App\Service\FrontendRouteManifestService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class FrontendRouteManifestServiceTest extends TestCase
{
    private RouterInterface $router;
    private RouteCollection $routes;

    protected function setUp(): void
    {
        $this->routes = new RouteCollection();
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->method('getRouteCollection')->willReturn($this->routes);
    }

    public function testBuildManifestWithValidHost(): void
    {
        $this->routes->add('dashboard', new Route('/dashboard', methods: ['GET']));
        $this->routes->add('app_login', new Route('/login', methods: ['GET', 'POST']));

        $service = new FrontendRouteManifestService($this->router, 'https://api.jambostack.site');
        $request = Request::create('https://api.jambostack.site/dashboard');

        $manifest = $service->buildManifest($request);

        $this->assertSame('https://api.jambostack.site', $manifest['url']);
        $this->assertNull($manifest['port']);
        $this->assertArrayHasKey('dashboard', $manifest['routes']);
        $this->assertSame('dashboard', $manifest['routes']['dashboard']['uri']);
        $this->assertSame(['GET'], $manifest['routes']['dashboard']['methods']);
        $this->assertArrayHasKey('login', $manifest['routes']);
        $this->assertSame('login', $manifest['routes']['login']['uri']);
        $this->assertSame(['GET', 'POST'], $manifest['routes']['login']['methods']);
    }

    public function testBuildManifestWithCustomPort(): void
    {
        $service = new FrontendRouteManifestService($this->router, 'http://localhost:8000');
        $request = Request::create('http://localhost:8000/dashboard');

        $manifest = $service->buildManifest($request);

        $this->assertSame('http://localhost:8000', $manifest['url']);
        $this->assertSame(8000, $manifest['port']);
    }

    public function testHostHeaderInjectionWithCrlfFallsBackToDefault(): void
    {
        $service = new FrontendRouteManifestService($this->router, 'https://api.jambostack.site');
        $request = Request::create('http://localhost/dashboard');
        // Simule un Host header injecté avec CRLF
        $request->headers->set('HOST', "evil.com\r\nX-Injected: true");

        $manifest = $service->buildManifest($request);

        $this->assertSame('https://api.jambostack.site', $manifest['url']);
    }

    public function testHostHeaderInjectionWithXssFallsBackToDefault(): void
    {
        $service = new FrontendRouteManifestService($this->router, 'https://api.jambostack.site');
        $request = Request::create('http://localhost/dashboard');
        $request->headers->set('HOST', 'evil.com"><script>alert(1)</script>');

        $manifest = $service->buildManifest($request);

        $this->assertSame('https://api.jambostack.site', $manifest['url']);
    }

    public function testHostHeaderWithSlashOrBackslashFallsBackToDefault(): void
    {
        $service = new FrontendRouteManifestService($this->router, 'https://api.jambostack.site');
        $request = Request::create('http://localhost/dashboard');
        $request->headers->set('HOST', 'evil.com/malicious');

        $manifest = $service->buildManifest($request);

        $this->assertSame('https://api.jambostack.site', $manifest['url']);
    }

    public function testRouteParameterCleaningWithInlineRequirements(): void
    {
        $this->routes->add('projects_show', new Route('/projects/{id<\d+>}', methods: ['GET']));

        $service = new FrontendRouteManifestService($this->router, 'https://api.jambostack.site');
        $request = Request::create('https://api.jambostack.site/projects/1');

        $manifest = $service->buildManifest($request);

        $this->assertArrayHasKey('projects.show', $manifest['routes']);
        $this->assertSame('projects/{id}', $manifest['routes']['projects.show']['uri']);
        $this->assertSame(['id'], $manifest['routes']['projects.show']['parameters']);
    }

    public function testCustomUrisIncludedInManifest(): void
    {
        $service = new FrontendRouteManifestService($this->router, 'https://api.jambostack.site');
        $request = Request::create('https://api.jambostack.site/');

        $manifest = $service->buildManifest($request);

        $this->assertArrayHasKey('verification.send', $manifest['routes']);
        $this->assertSame('email/verify', $manifest['routes']['verification.send']['uri']);
        $this->assertArrayHasKey('projects.settings.webhooks.logs', $manifest['routes']);
        $this->assertSame('projects/{projectId}/settings/webhook-logs', $manifest['routes']['projects.settings.webhooks.logs']['uri']);
        $this->assertSame(['projectId'], $manifest['routes']['projects.settings.webhooks.logs']['parameters']);
    }
}
