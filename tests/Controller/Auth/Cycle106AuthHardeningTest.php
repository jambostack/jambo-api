<?php

namespace App\Tests\Controller\Auth;

use App\Controller\Auth\ConfirmablePasswordController;
use App\Controller\Auth\NewPasswordController;
use App\Controller\Auth\PasswordResetLinkController;
use App\Controller\Auth\RegisteredUserController;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\FrontendRouteManifestService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle106AuthHardeningTest extends TestCase
{
    private function createLimiter(int $limit = 50, string $id = 'auth_test'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    private function createRejectingLimiter(string $id = 'auth_reject'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create('127.0.0.1')->consume(1);
        return $factory;
    }

    private function setupController(object $controller, ?User $currentUser = null): void
    {
        $container = new Container();
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 3));
        $container->set('parameter_bag', new ContainerBag($container));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn ($route, $params = []) => '/' . $route . (isset($params['token']) ? '/' . $params['token'] : ''));
        $container->set('router', $router);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($currentUser !== null) {
            $token = new UsernamePasswordToken($currentUser, 'main', $currentUser->getRoles());
            $tokenStorage->method('getToken')->willReturn($token);
        }
        $container->set('security.token_storage', $tokenStorage);

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<html>mocked</html>');
        $container->set('twig', $twig);

        if (method_exists($controller, 'setContainer')) {
            $controller->setContainer($container);
        }

        if (method_exists($controller, 'setRouteManifest')) {
            $routeManifest = $this->createMock(FrontendRouteManifestService::class);
            $routeManifest->method('buildManifest')->willReturn([]);
            $controller->setRouteManifest($routeManifest);
        }

        if (method_exists($controller, 'setCache')) {
            $cache = $this->createMock(CacheInterface::class);
            $cache->method('get')->willReturn([]);
            $controller->setCache($cache);
        }

        if (method_exists($controller, 'setAppSettingsRepository')) {
            $appSettings = $this->createMock(AppSettingsRepository::class);
            $controller->setAppSettingsRepository($appSettings);
        }

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn ($id, $p = []) => strtr($id, $p));
        if (method_exists($controller, 'setTranslator')) {
            $controller->setTranslator($translator);
        }
    }

    // ──────────────────────────────────────────────────────────
    // RegisteredUserController Tests
    // ──────────────────────────────────────────────────────────

    public function testRegisterContentTypeRejection(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $validator = Validation::createValidator();
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('user_register_invalid_content_type');

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiter, $auditService);
        $this->setupController($controller);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'text/xml',
            'REMOTE_ADDR' => '127.0.0.1',
        ], '<xml></xml>');

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(415, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $data = json_decode($response->getContent(), true);
        $this->assertSame('auth.register.invalid_content_type', $data['error']);
    }

    public function testRegisterPayloadTooLarge(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $validator = Validation::createValidator();
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('user_register_payload_too_large');

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiter, $auditService);
        $this->setupController($controller);

        $bigPayload = json_encode(['data' => str_repeat('A', 20000)]);
        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $bigPayload);

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(413, $response->getStatusCode());
    }

    public function testRegisterRateLimited(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $validator = Validation::createValidator();
        $limiter = $this->createRejectingLimiter('reg_limit_test');
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('user_register_rate_limited');

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiter, $auditService);
        $this->setupController($controller);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['email' => 'test@example.com']));

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
    }

    public function testRegisterInvalidControlCharsInName(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $validator = Validation::createValidator();
        $limiter = $this->createLimiter();

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiter);
        $this->setupController($controller);

        $payload = json_encode([
            'name' => "Bad\x00Name",
            'email' => 'valid@example.com',
            'password' => 'Pass!Valid123',
            'password_confirmation' => 'Pass!Valid123',
        ]);
        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $payload);

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('auth.register.name_invalid_chars', $data['errors']['name']);
    }

    public function testRegisterPasswordMismatch(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $validator = Validation::createValidator();
        $limiter = $this->createLimiter();

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiter);
        $this->setupController($controller);

        $payload = json_encode([
            'name' => 'Valid User',
            'email' => 'user@example.com',
            'password' => 'Pass!Valid123',
            'password_confirmation' => 'DifferentPass!123',
        ]);
        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $payload);

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('auth.register.passwords_do_not_match', $data['errors']['password_confirmation']);
    }

    public function testRegisterEmailConflict(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $existingUser = new User();
        $userRepo->method('findOneBy')->willReturn($existingUser);
        $em->method('getRepository')->with(User::class)->willReturn($userRepo);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $validator = Validation::createValidator();
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('user_register_conflict');

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiter, $auditService);
        $this->setupController($controller);

        $payload = json_encode([
            'name' => 'John Doe',
            'email' => 'duplicate@example.com',
            'password' => 'Str0ng!Pass#2026',
            'password_confirmation' => 'Str0ng!Pass#2026',
        ]);
        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $payload);

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('auth.register.email_in_use', $data['errors']['email']);
    }

    public function testRegisterSuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);
        $em->method('getRepository')->with(User::class)->willReturn($userRepo);

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed_secret_password');

        $validator = Validation::createValidator();
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('user_registered');

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiter, $auditService);
        $this->setupController($controller);

        $payload = json_encode([
            'name' => 'Alice Wonder',
            'email' => 'alice@example.com',
            'password' => 'Super!Secure#2026Pass',
            'password_confirmation' => 'Super!Secure#2026Pass',
        ]);
        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $payload);

        $response = $controller->store($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/app_login', $response->getTargetUrl());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    // ──────────────────────────────────────────────────────────
    // PasswordResetLinkController Tests
    // ──────────────────────────────────────────────────────────

    public function testForgotPasswordContentTypeRejection(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_reset_request_invalid_content_type');

        $controller = new PasswordResetLinkController($userRepo, $tokenRepo, $em, $mailer, $limiter, $auditService);
        $this->setupController($controller);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'REMOTE_ADDR' => '127.0.0.1',
        ], 'email=victim@example.com');

        $response = $controller->store($request);
        $this->assertSame(415, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testForgotPasswordRateLimited(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $limiter = $this->createRejectingLimiter('pw_reset_limit_test');
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_reset_request_rate_limited');

        $controller = new PasswordResetLinkController($userRepo, $tokenRepo, $em, $mailer, $limiter, $auditService);
        $this->setupController($controller);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['email' => 'victim@example.com']));

        $response = $controller->store($request);
        $this->assertSame(429, $response->getStatusCode());
    }

    public function testForgotPasswordInvalidEmail(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_reset_request_invalid_email');

        $controller = new PasswordResetLinkController($userRepo, $tokenRepo, $em, $mailer, $limiter, $auditService);
        $this->setupController($controller);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['email' => 'not-an-email']));

        $response = $controller->store($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testForgotPasswordSuccessAndNonExistingUserUniformity(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        // Non-existing user should still return generic status
        $userRepo->method('findByEmail')->willReturn(null);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_reset_requested');

        $controller = new PasswordResetLinkController($userRepo, $tokenRepo, $em, $mailer, $limiter, $auditService);
        $this->setupController($controller);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['email' => 'unknown@example.com']));

        $response = $controller->store($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ──────────────────────────────────────────────────────────
    // NewPasswordController Tests
    // ──────────────────────────────────────────────────────────

    public function testResetPasswordInvalidTokenFormat(): void
    {
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_reset_token_format_invalid');

        $controller = new NewPasswordController($tokenRepo, $userRepo, $em, $hasher, $limiter, $auditService);
        $this->setupController($controller);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['password' => 'Valid#Pass123', 'password_confirmation' => 'Valid#Pass123']));

        $response = $controller->store($request, 'short');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testResetPasswordExpiredToken(): void
    {
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $tokenRepo->method('findValidByToken')->willReturn(null);
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_reset_invalid_or_expired_token');

        $controller = new NewPasswordController($tokenRepo, $userRepo, $em, $hasher, $limiter, $auditService);
        $this->setupController($controller);

        $validTokenStr = str_repeat('a', 64);
        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['password' => 'Valid#Pass123', 'password_confirmation' => 'Valid#Pass123']));

        $response = $controller->store($request, $validTokenStr);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testResetPasswordSuccess(): void
    {
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $resetToken = new PasswordResetToken(null, 'user@example.com');
        $tokenRepo->method('findValidByToken')->willReturn($resetToken);
        $tokenRepo->method('findBy')->willReturn([$resetToken]);

        $user = new User();
        $user->name = 'Valid User';
        $user->email = 'user@example.com';
        $user->password = 'old_hash';

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($resetToken);
        $em->expects($this->once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false); // different from current
        $hasher->method('hashPassword')->willReturn('new_hash_secret');

        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_reset_success');

        $controller = new NewPasswordController($tokenRepo, $userRepo, $em, $hasher, $limiter, $auditService);
        $this->setupController($controller);

        $validTokenStr = str_repeat('b', 64);
        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode([
            'password' => 'Super!NewSecret#2026',
            'password_confirmation' => 'Super!NewSecret#2026',
        ]));

        $response = $controller->store($request, $validTokenStr);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/app_login', $response->getTargetUrl());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ──────────────────────────────────────────────────────────
    // ConfirmablePasswordController Tests
    // ──────────────────────────────────────────────────────────

    public function testConfirmPasswordAuthRequired(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $limiter = $this->createLimiter();
        $controller = new ConfirmablePasswordController($hasher, $limiter);
        $this->setupController($controller, null); // unauthenticated

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['password' => 'secret']));

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testConfirmPasswordInvalidPassword(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_confirm_failed');

        $user = new User();
        $user->id = 42;
        $user->email = 'confirmed@example.com';

        $controller = new ConfirmablePasswordController($hasher, $limiter, $auditService);
        $this->setupController($controller, $user);

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['password' => 'wrong_pass']));
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $controller->store($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testConfirmPasswordSuccess(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(true);
        $limiter = $this->createLimiter();
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('password_confirm_success');

        $user = new User();
        $user->id = 42;
        $user->email = 'confirmed@example.com';

        $controller = new ConfirmablePasswordController($hasher, $limiter, $auditService);
        $this->setupController($controller, $user);

        $session = new Session(new MockArraySessionStorage());
        $session->set('url.intended', '/settings/profile');

        $request = new Request([], [], [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['password' => 'correct_secret']));
        $request->setSession($session);

        $response = $controller->store($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings/profile', $response->getTargetUrl());
        $this->assertNotNull($session->get('auth.password_confirmed_at'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
