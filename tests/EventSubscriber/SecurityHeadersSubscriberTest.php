<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriberTest extends TestCase
{
    private function makeEvent(Request $request, Response $response, bool $mainRequest = true): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ResponseEvent(
            $kernel,
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
            $response,
        );
    }

    public function testSubscribesCorrectly(): void
    {
        $events = SecurityHeadersSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testXContentTypeOptionsIsSet(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $request->server->set('HTTPS', 'on');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testHstsSetOnHttpsRequest(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('https://example.com/api/test');
        $request->server->set('HTTPS', 'on');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $hsts = $response->headers->get('Strict-Transport-Security');
        $this->assertNotNull($hsts);
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
        $this->assertStringContainsString('preload', $hsts);
    }

    public function testHstsSetInProdEvenOnHttp(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('http://example.com/api/test');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertNotNull($response->headers->get('Strict-Transport-Security'));
    }

    public function testHstsNotSetInDevOnHttp(): void
    {
        $subscriber = new SecurityHeadersSubscriber('dev');
        $request = Request::create('http://example.com/api/test');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function testHstsSetInDevOnHttps(): void
    {
        $subscriber = new SecurityHeadersSubscriber('dev');
        $request = Request::create('https://example.com/api/test');
        $request->server->set('HTTPS', 'on');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertNotNull($response->headers->get('Strict-Transport-Security'));
    }

    public function testPermissionsPolicyIsSet(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $pp = $response->headers->get('Permissions-Policy');
        $this->assertNotNull($pp);
        $this->assertStringContainsString('camera=()', $pp);
        $this->assertStringContainsString('microphone=()', $pp);
        $this->assertStringContainsString('geolocation=()', $pp);
    }

    public function testReferrerPolicyIsSet(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame(
            'strict-origin-when-cross-origin',
            $response->headers->get('Referrer-Policy'),
        );
    }

    public function testXFrameOptionsIsSet(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testXFrameOptionsNotSetWhenCspFrameAncestorsPresent(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $response = new Response('', 200);
        $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'");

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        // X-Frame-Options should NOT override when CSP frame-ancestors is present
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    public function testXPermittedCrossDomainPoliciesIsSet(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
    }

    public function testCspSandboxAddedForSvgResponse(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/media/test.svg');
        $response = new Response('<svg/>', 200, ['Content-Type' => 'image/svg+xml']);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
    }

    public function testCspNotAddedForNonSvgResponse(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $response = new Response('{}', 200, ['Content-Type' => 'application/json']);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function testNoHeadersAddedOnSubRequest(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $request->server->set('HTTPS', 'on');
        $response = new Response('', 200);

        // Sub-request: should not set any headers
        $subscriber->onKernelResponse($this->makeEvent($request, $response, false));

        $this->assertNull($response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function testExistingHeadersAreNotOverridden(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('/api/test');
        $response = new Response('', 200);
        $response->headers->set('X-Content-Type-Options', 'custom-value');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertSame('custom-value', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function testXForwardedProtoHttpsTriggerHsts(): void
    {
        $subscriber = new SecurityHeadersSubscriber('dev');
        $request = Request::create('http://example.com/api/test');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $response = new Response('', 200);

        $subscriber->onKernelResponse($this->makeEvent($request, $response));

        $this->assertNotNull($response->headers->get('Strict-Transport-Security'));
    }
}
