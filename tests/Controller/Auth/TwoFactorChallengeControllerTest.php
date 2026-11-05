<?php

namespace App\Tests\Controller\Auth;

use App\Controller\Auth\TwoFactorChallengeController;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Service\FrontendRouteManifestService;
use App\Service\TwoFactorMailer;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Cache\CacheInterface;

class TwoFactorChallengeControllerTest extends TestCase
{
    private function createLimiterFactory(int $limit = 50, string $id = 'two_factor_test'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    private function createController(
        ?TwoFactorService $twoFactor = null,
        ?TwoFactorMailer $mailer = null,
        ?EntityManagerInterface $em = null,
        ?RateLimiterFactory $limiterFactory = null
    ): TwoFactorChallengeController {
        $twoFactor = $twoFactor ?? new TwoFactorService();
        $mailer = $mailer ?? $this->createMock(TwoFactorMailer::class);
        $requestStack = new RequestStack();
        $em = $em ?? $this->createMock(EntityManagerInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $limiterFactory = $limiterFactory ?? $this->createLimiterFactory();

        $controller = new TwoFactorChallengeController(
            $twoFactor,
            $mailer,
            $requestStack,
            $em,
            $tokenStorage,
            $limiterFactory
        );

        $container = new Container();
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 3));
        $container->set('parameter_bag', new ContainerBag($container));
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn ($route) => '/' . $route);
        $container->set('router', $router);
        $container->set('security.token_storage', $tokenStorage);

        $controller->setContainer($container);

        $routeManifest = $this->createMock(FrontendRouteManifestService::class);
        $routeManifest->method('buildManifest')->willReturn([]);
        $controller->setRouteManifest($routeManifest);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn([]);
        $controller->setCache($cache);

        $appSettings = $this->createMock(AppSettingsRepository::class);
        $controller->setAppSettingsRepository($appSettings);

        return $controller;
    }

    public function testShowRedirectsWhenSessionMissing(): void
    {
        $controller = $this->createController();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $response = $controller->show($request);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testVerifyBruteforceProtectionClearsSessionAfterFiveFailures(): void
    {
        $user = new User();
        $user->twoFactorMethod = 'totp';
        $user->twoFactorSecret = 'JBSWY3DPEHPK3PXP';

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with(42)->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($repo);

        $controller = $this->createController(em: $em);

        $session = new Session(new MockArraySessionStorage());
        $session->set('two_factor_user_id', 42);
        $session->set('two_factor_expires', time() + 300);

        // Attempts 1 to 4 should increment failed attempts
        for ($i = 1; $i <= 4; $i++) {
            $request = new Request([], ['code' => '000000', 'use_backup' => false], [], [], [], ['HTTP_X-Inertia' => 'true']);
            $request->setSession($session);

            $response = $controller->verify($request);
            $this->assertSame($i, $session->get('two_factor_failed_attempts'));
            $this->assertTrue($session->has('two_factor_user_id'));
        }

        // 5th attempt must trigger lockout and clear session
        $request = new Request([], ['code' => '000000', 'use_backup' => false], [], [], [], ['HTTP_X-Inertia' => 'true']);
        $request->setSession($session);

        $response = $controller->verify($request);
        $this->assertFalse($session->has('two_factor_user_id'), 'Session must be purged on 5th failed attempt');
        $this->assertFalse($session->has('two_factor_expires'));
    }

    public function testSendEmailCappedAtThreeResends(): void
    {
        $user = new User();
        $user->email = '2fa@jambo.local';
        $user->twoFactorMethod = 'email';

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with(10)->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($repo);

        $mailer = $this->createMock(TwoFactorMailer::class);
        $mailer->expects($this->exactly(3))->method('sendCode');

        $controller = $this->createController(mailer: $mailer, em: $em);

        $session = new Session(new MockArraySessionStorage());
        $session->set('two_factor_user_id', 10);
        $session->set('two_factor_expires', time() + 300);

        // 3 allowed resends
        for ($i = 1; $i <= 3; $i++) {
            $request = new Request();
            $request->setSession($session);
            $res = $controller->sendEmail($request);
            $this->assertSame(200, $res->getStatusCode());
            $this->assertSame($i, $session->get('two_factor_email_resends'));
        }

        // 4th resend must be rejected with 429
        $request = new Request();
        $request->setSession($session);
        $res4 = $controller->sendEmail($request);
        $this->assertSame(429, $res4->getStatusCode());
        $data = json_decode((string) $res4->getContent(), true);
        $this->assertStringContainsString('limit reached', $data['error']);
    }
}
