<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\EndUserAuthController;
use App\Controller\Auth\NewPasswordController;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\EndUserRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\EndUserJwtService;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class Cycle13HardeningTest extends WebTestCase
{
    private function createRejectingLimiterFactory(string $id = 'test'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        // Consume the only token so subsequent calls from 1.2.3.4 are rejected
        $factory->create('1.2.3.4')->consume();

        return $factory;
    }

    public function testTranslationParityAcrossAllLocales(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $en = require $projectDir . '/translations/messages.en.php';
        $fr = require $projectDir . '/translations/messages.fr.php';
        $es = require $projectDir . '/translations/messages.es.php';
        $ar = require $projectDir . '/translations/messages.ar.php';

        $this->assertIsArray($en);
        $this->assertIsArray($fr);
        $this->assertIsArray($es);
        $this->assertIsArray($ar);

        $this->assertGreaterThan(1700, count($en));
        $this->assertSame(count($fr), count($en), 'FR and EN must have identical key count');
        $this->assertSame(count($fr), count($es), 'FR and ES must have identical key count');
        $this->assertSame(count($fr), count($ar), 'FR and AR must have identical key count');

        $this->assertEmpty(array_diff_key($fr, $en), 'No keys missing in EN compared to FR');
        $this->assertEmpty(array_diff_key($fr, $es), 'No keys missing in ES compared to FR');
        $this->assertEmpty(array_diff_key($fr, $ar), 'No keys missing in AR compared to FR');
    }

    public function testEndUserLoginRateLimiterReturns429WhenRejected(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $rejectingLoginLimiter = $this->createRejectingLimiterFactory();

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $rejectingLoginLimiter,
            null,
            null
        );

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '1.2.3.4'], json_encode([
            'email' => 'victim@test.com',
            'password' => 'secret123',
        ]));

        $response = $controller->login($request, '00000000-0000-0000-0000-000000000001');

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Too many login attempts', $payload['error']);
    }

    public function testEndUserRegisterRateLimiterReturns429WhenRejected(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $rejectingRegisterLimiter = $this->createRejectingLimiterFactory();

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $rejectingRegisterLimiter,
            null
        );

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '1.2.3.4'], json_encode([
            'email' => 'new@test.com',
            'password' => 'Password123!',
        ]));

        $response = $controller->register($request, '00000000-0000-0000-0000-000000000001');

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Too many registration requests', $payload['error']);
    }

    public function testEndUserVerify2FaRateLimiterReturns429WhenRejected(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $rejecting2FaLimiter = $this->createRejectingLimiterFactory();

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            null,
            null,
            null,
            null,
            null,
            null,
            $rejecting2FaLimiter,
            null,
            null,
            null
        );

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '1.2.3.4'], json_encode([
            'two_factor_token' => 'sample-token',
            'code' => '123456',
        ]));

        $response = $controller->verifyTwoFactor($request, '00000000-0000-0000-0000-000000000001');

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Too many 2FA verification attempts', $payload['error']);
    }

    public function testEndUserForgotPasswordRateLimiterReturns429WhenRejected(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $rejectingResetLimiter = $this->createRejectingLimiterFactory();

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $rejectingResetLimiter
        );

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '1.2.3.4'], json_encode([
            'email' => 'victim@test.com',
        ]));

        $response = $controller->forgotPassword($request, '00000000-0000-0000-0000-000000000001');

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Too many password reset requests', $payload['error']);
    }

    public function testEndUserResetPasswordRateLimiterReturns429WhenRejected(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $rejectingResetLimiter = $this->createRejectingLimiterFactory();

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $rejectingResetLimiter
        );

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '1.2.3.4'], json_encode([
            'token' => 'some-reset-token',
            'password' => 'NewPassword123!',
        ]));

        $response = $controller->resetPassword($request, '00000000-0000-0000-0000-000000000001');

        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Too many password reset attempts', $payload['error']);
    }

    public function testNewPasswordStoreRateLimiterReturnsErrorWhenRejected(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $rejectingResetLimiter = $this->createRejectingLimiterFactory('password_reset_test');

        $controller = $container->get(NewPasswordController::class);
        $ref = new \ReflectionClass(NewPasswordController::class);
        $prop = $ref->getProperty('passwordResetLimiter');
        $prop->setValue($controller, $rejectingResetLimiter);

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_INERTIA' => 'true'], json_encode([
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]));

        $response = $controller->store($request, 'valid-token-str');

        $this->assertInstanceOf(Response::class, $response);
        $content = (string) $response->getContent();
        $this->assertStringContainsString('Too many requests', $content);
    }
}
