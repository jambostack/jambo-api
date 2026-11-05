<?php

declare(strict_types=1);

namespace App\Tests\Controller\Auth;

use App\Controller\Auth\SocialLoginController;
use App\Service\SocialLoginService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialLoginRateLimitTest extends TestCase
{
    private function createRejectingLimiterFactory(string $id = 'social_test'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create('127.0.0.1')->consume();
        return $factory;
    }

    private function createAcceptingLimiterFactory(string $id = 'social_accept'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    public function testConnectReturns429WhenRateLimitExceeded(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Trop de tentatives de connexion sociale. Veuillez réessayer plus tard.');

        $limiterFactory = $this->createRejectingLimiterFactory();

        $controller = new SocialLoginController($socialLogin, $limiterFactory, $translator);

        $request = Request::create('/connect/google', 'GET');
        $response = $controller->connect('google', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('Trop de tentatives', (string) $response->getContent());
    }

    public function testConnectThrows404ForUnknownProvider(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $limiterFactory = $this->createAcceptingLimiterFactory();

        $controller = new SocialLoginController($socialLogin, $limiterFactory);

        $request = Request::create('/connect/unsupported_provider', 'GET');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->connect('unsupported_provider', $request);
    }
}
