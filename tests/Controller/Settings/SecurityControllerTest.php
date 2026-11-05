<?php

namespace App\Tests\Controller\Settings;

use App\Controller\Settings\SecurityController;
use App\Entity\User;
use App\Service\SocialLoginService;
use App\Service\TwoFactorMailer;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SecurityControllerTest extends TestCase
{
    private function createLimiterFactory(int $limit = 50, string $id = 'security_test'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    private function createController(
        User $user,
        ?TwoFactorService $twoFactor = null,
        ?UserPasswordHasherInterface $hasher = null,
        ?RateLimiterFactory $limiterFactory = null,
        ?TranslatorInterface $translator = null
    ): SecurityController {
        $em = $this->createMock(EntityManagerInterface::class);
        $twoFactor = $twoFactor ?? new TwoFactorService();
        $twoFactorMailer = $this->createMock(TwoFactorMailer::class);
        $hasher = $hasher ?? $this->createMock(UserPasswordHasherInterface::class);
        $requestStack = new RequestStack();
        $socialLogin = $this->createMock(SocialLoginService::class);
        $limiterFactory = $limiterFactory ?? $this->createLimiterFactory();

        if ($translator === null) {
            $translator = $this->createMock(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(fn ($id) => 'trans:' . $id);
        }

        $controller = new SecurityController(
            $em,
            $twoFactor,
            $twoFactorMailer,
            $hasher,
            $requestStack,
            $socialLogin,
            $limiterFactory,
            $translator
        );

        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage->method('getToken')->willReturn($token);
        $container->set('security.token_storage', $tokenStorage);

        $controller->setContainer($container);

        return $controller;
    }

    public function testTotpSetupRejectsInvalidPassword(): void
    {
        $user = new User();
        $user->email = 'user1@example.com';
        $user->twoFactorEnabled = false;

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);

        $controller = $this->createController($user, hasher: $hasher);

        $request = Request::create('/api/settings/security/totp/setup', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['password' => 'wrong-pass']));

        $response = $controller->totpSetup($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('trans:security.2fa.invalid_password', $data['error']);
    }

    public function testTotpSetupRejectsWhenAlreadyEnabled(): void
    {
        $user = new User();
        $user->email = 'user2@example.com';
        $user->twoFactorEnabled = true;

        $controller = $this->createController($user);
        $request = Request::create('/api/settings/security/totp/setup', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['password' => 'any']));

        $response = $controller->totpSetup($request);
        $this->assertSame(409, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('trans:security.2fa.already_enabled', $data['error']);
    }

    public function testTotpConfirmRejectsInvalidCode(): void
    {
        $user = new User();
        $user->email = 'user3@example.com';
        $user->twoFactorEnabled = false;
        $user->twoFactorMethod = 'totp';
        $user->twoFactorSecret = 'JBSWY3DPEHPK3PXP';

        $controller = $this->createController($user);
        $request = Request::create('/api/settings/security/totp/confirm', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '000000']));

        $response = $controller->totpConfirm($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('trans:security.2fa.invalid_code', $data['error']);
    }

    public function testRateLimitingBlocksExcessiveRequests(): void
    {
        $user = new User();
        $user->email = 'user4@example.com';
        $user->twoFactorEnabled = false;

        $limiterFactory = $this->createLimiterFactory(limit: 1, id: 'test_strict_limit');
        $controller = $this->createController($user, limiterFactory: $limiterFactory);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);

        $request = Request::create('/api/settings/security/totp/setup', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['password' => 'pass']));

        // First attempt consumed the 1 permitted slot
        $res1 = $controller->totpSetup($request);
        $this->assertSame(422, $res1->getStatusCode());

        // Second attempt must hit rate limiter
        $res2 = $controller->totpSetup($request);
        $this->assertSame(429, $res2->getStatusCode());
        $data = json_decode((string) $res2->getContent(), true);
        $this->assertSame('trans:security.2fa.too_many_attempts', $data['error']);
    }

    public function testDisableRejectsWhenNotEnabled(): void
    {
        $user = new User();
        $user->email = 'user5@example.com';
        $user->twoFactorEnabled = false;

        $controller = $this->createController($user);
        $request = Request::create('/api/settings/security/disable', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['password' => 'secret']));

        $response = $controller->disable($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('trans:security.2fa.not_enabled', $data['error']);
    }
}
