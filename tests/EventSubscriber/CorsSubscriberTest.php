<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\CorsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class CorsSubscriberTest extends TestCase
{
    private CorsSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new CorsSubscriber();
    }

    protected function tearDown(): void
    {
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
        parent::tearDown();
    }

    public function testPublicRouteAllowsAnyOriginOnOptions(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/content/articles', 'OPTIONS');
        $request->headers->set('Origin', 'https://example.com');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testPublicRouteAddsCorsOnResponse(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/projects/some-uuid/graphql', 'POST');
        $response = new Response('{}', 200);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->subscriber->onKernelResponse($event);

        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testRestrictedRouteWithEvilOriginRejectsPreflight(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin/users', 'OPTIONS');
        $request->headers->set('Origin', 'https://evil-attacker.com');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testRestrictedRouteWithEvilOriginDoesNotAddCorsHeaders(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin/users', 'GET');
        $request->headers->set('Origin', 'https://evil-attacker.com');
        $response = new Response('secret data', 200);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->subscriber->onKernelResponse($event);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testRestrictedRouteWithAllowedOriginSetsCredentialsAndVary(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://admin.jambostack.site,https://app.jambostack.site';

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin/users', 'GET');
        $request->headers->set('Origin', 'https://admin.jambostack.site');
        $response = new Response('ok', 200);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->subscriber->onKernelResponse($event);

        $this->assertSame('https://admin.jambostack.site', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $this->assertSame('Origin', $response->headers->get('Vary'));
    }

    public function testRestrictedRouteWithLocalhostAllowed(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin/dashboard', 'OPTIONS');
        $request->headers->set('Origin', 'http://localhost:3000');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }
}
