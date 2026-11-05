<?php

namespace App\Tests\Security;

use App\Controller\Api\EndUserAuthController;
use App\Controller\ProjectOAuthController;
use App\Entity\EndUser;
use App\Entity\PasswordResetToken;
use App\Entity\Project;
use App\Entity\ProjectAuthClient;
use App\Repository\EndUserRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\ProjectRepository;
use App\Service\EndUserJwtService;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectOAuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle85OAuthAndPasswordResetHardeningTest extends TestCase
{
    private ProjectRepository $projectRepo;
    private EndUserRepository $endUserRepo;
    private ProjectOAuthService $oauthService;
    private EndUserJwtService $jwtService;
    private ProjectAuthLockoutService $lockoutService;
    private UserPasswordHasherInterface $passwordHasher;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $this->projectRepo = $this->createMock(ProjectRepository::class);
        $this->endUserRepo = $this->createMock(EndUserRepository::class);
        $this->oauthService = $this->createMock(ProjectOAuthService::class);
        $this->jwtService = $this->createMock(EndUserJwtService::class);
        $this->lockoutService = $this->createMock(ProjectAuthLockoutService::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(fn ($id, $params = []) => strtr($id, $params));
    }

    private function createRejectingLimiter(string $id = 'test_reject'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
        $factory->create('127.0.0.1')->consume(1);
        return $factory;
    }

    private function createAcceptingLimiter(string $id = 'test_accept'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
    }

    private function createController(?RateLimiterFactory $limiter = null): ProjectOAuthController
    {
        return new ProjectOAuthController(
            $this->projectRepo,
            $this->endUserRepo,
            $this->oauthService,
            $this->jwtService,
            $this->lockoutService,
            $this->passwordHasher,
            $this->translator,
            $limiter
        );
    }

    public function testAuthorizePayloadTooLargeReturns413(): void
    {
        $controller = $this->createController();
        $hugeContent = str_repeat('A', 66000);
        $request = new Request([], [], [], [], [], [], $hugeContent);

        $response = $controller->authorize('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('invalid_request', $data['error']);
        $this->assertSame('project_oauth.error.payload_too_large', $data['error_description']);
    }

    public function testAuthorizeRateLimitedLoginReturns429(): void
    {
        $rejectingLimiter = $this->createRejectingLimiter('login_rate_test');

        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->redirectUris = ['https://app.test/cb'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $controller = $this->createController($rejectingLimiter);

        $request = new Request(
            query: ['client_id' => 'cli_1', 'redirect_uri' => 'https://app.test/cb', 'response_type' => 'code', 'format' => 'json'],
            request: ['email' => 'victim@test.org', 'password' => 'secret', 'action' => 'authorize']
        );
        $request->setMethod('POST');

        $response = $controller->authorize('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('slow_down', $data['error']);
        $this->assertSame('project_oauth.error.rate_limited_login', $data['error_description']);
    }

    public function testAuthorizeIpLockedOutReturns429WithRetryAfterHeader(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->redirectUris = ['https://app.test/cb'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $this->lockoutService->method('isLockedOut')
            ->willReturnCallback(fn ($key) => str_contains($key, 'enduser_login_ip:'));
        $this->lockoutService->method('getRemainingLockoutSeconds')->willReturn(450);

        $controller = $this->createController();

        $request = new Request(
            query: ['client_id' => 'cli_1', 'redirect_uri' => 'https://app.test/cb', 'response_type' => 'code', 'format' => 'json'],
            request: ['email' => 'victim@test.org', 'password' => 'secret', 'action' => 'authorize']
        );
        $request->setMethod('POST');

        $response = $controller->authorize('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('450', $response->headers->get('Retry-After'));
        $data = json_decode($response->getContent(), true);
        $this->assertSame('slow_down', $data['error']);
        $this->assertStringContainsString('project_oauth.error.ip_locked_out', $data['error_description']);
    }

    public function testAuthorizeAccountLockedOutReturns429(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->redirectUris = ['https://app.test/cb'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $this->lockoutService->method('isLockedOut')
            ->willReturnCallback(fn ($key) => str_starts_with($key, 'enduser_login:') && !str_starts_with($key, 'enduser_login_ip:'));
        $this->lockoutService->method('getRemainingLockoutSeconds')->willReturn(300);

        $controller = $this->createController();

        $request = new Request(
            query: ['client_id' => 'cli_1', 'redirect_uri' => 'https://app.test/cb', 'response_type' => 'code', 'format' => 'json'],
            request: ['email' => 'locked@test.org', 'password' => 'secret', 'action' => 'authorize']
        );
        $request->setMethod('POST');

        $response = $controller->authorize('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('300', $response->headers->get('Retry-After'));
        $data = json_decode($response->getContent(), true);
        $this->assertSame('slow_down', $data['error']);
        $this->assertStringContainsString('project_oauth.error.account_locked_out', $data['error_description']);
    }

    public function testAuthorizeNonExistentUserTriggersTimingDefenseAndRecordsFailedAttempt(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->name = 'OAuth Client';
        $client->redirectUris = ['https://app.test/cb'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $this->endUserRepo->method('findOneByProjectAndEmail')->willReturn(null);

        // Expect timing defense dummy hasher verification
        $this->passwordHasher->expects($this->once())
            ->method('isPasswordValid')
            ->with($this->callback(fn ($user) => $user instanceof EndUser && $user->email === 'timing-defense@local.internal'), 'some-pass');

        // Expect recording failed attempts on both account and IP keys
        $recordedKeys = [];
        $this->lockoutService->expects($this->exactly(2))
            ->method('recordTieredFailedAttempt')
            ->willReturnCallback(function ($key) use (&$recordedKeys) {
                $recordedKeys[] = $key;
                return 1;
            });

        $controller = $this->createController();

        $request = new Request(
            query: ['client_id' => 'cli_1', 'redirect_uri' => 'https://app.test/cb', 'response_type' => 'code', 'format' => 'json'],
            request: ['email' => 'ghost@unknown.org', 'password' => 'some-pass', 'action' => 'authorize']
        );
        $request->setMethod('POST');

        $response = $controller->authorize('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('login_required', $data['error']);
        $this->assertSame('project_oauth.error.invalid_credentials', $data['error_description']);

        $this->assertCount(2, $recordedKeys);
        $this->assertTrue(str_contains($recordedKeys[0], 'ghost@unknown.org'));
        $this->assertTrue(str_contains($recordedKeys[1], 'enduser_login_ip:'));
    }

    public function testAuthorizeInactiveUserReturnsUniformAccountInactiveError(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->name = 'OAuth Client';
        $client->redirectUris = ['https://app.test/cb'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $inactiveUser = new EndUser($project, 'banned@example.org');
        $inactiveUser->id = 99;
        $inactiveUser->status = 'banned';

        $this->endUserRepo->method('findOneByProjectAndEmail')->willReturn($inactiveUser);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);

        $controller = $this->createController();

        $request = new Request(
            query: ['client_id' => 'cli_1', 'redirect_uri' => 'https://app.test/cb', 'response_type' => 'code', 'format' => 'json'],
            request: ['email' => 'banned@example.org', 'password' => 'valid-pass', 'action' => 'authorize']
        );
        $request->setMethod('POST');

        $response = $controller->authorize('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('login_required', $data['error']);
        $this->assertSame('project_oauth.error.account_inactive', $data['error_description']);
    }

    public function testTokenPayloadTooLargeReturns413(): void
    {
        $controller = $this->createController();
        $hugeContent = str_repeat('Z', 66000);
        $request = new Request([], [], [], [], [], [], $hugeContent);

        $response = $controller->token('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('invalid_request', $data['error']);
        $this->assertSame('project_oauth.error.payload_too_large', $data['error_description']);
    }

    public function testTokenExchangeRateLimitedReturns429(): void
    {
        $rejectingLimiter = $this->createRejectingLimiter('token_rate_test');

        $project = new Project();
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $controller = $this->createController($rejectingLimiter);

        $request = new Request([], ['grant_type' => 'authorization_code']);
        $response = $controller->token('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('slow_down', $data['error']);
        $this->assertSame('project_oauth.error.rate_limited_token', $data['error_description']);
    }

    public function testTokenLockedOutIpReturns429WithRetryAfter(): void
    {
        $project = new Project();
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $this->lockoutService->method('isLockedOut')
            ->willReturnCallback(fn ($key) => str_starts_with($key, 'oauth_token_ip:'));
        $this->lockoutService->method('getRemainingLockoutSeconds')->willReturn(180);

        $controller = $this->createController();

        $request = new Request([], ['grant_type' => 'authorization_code']);
        $response = $controller->token('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('180', $response->headers->get('Retry-After'));
        $data = json_decode($response->getContent(), true);
        $this->assertSame('slow_down', $data['error']);
        $this->assertStringContainsString('project_oauth.error.rate_limited', $data['error_description']);
    }

    public function testTokenSuccessfulExchangeClearsBothLockoutKeys(): void
    {
        $project = new Project();
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $this->oauthService->method('validateClient')->willReturn($client);
        $this->oauthService->method('exchangeAuthorizationCode')->willReturn([
            'access_token' => 'jwt_token_123',
            'refresh_token' => 'plain_refresh_token',
        ]);

        $clearedKeys = [];
        $this->lockoutService->expects($this->exactly(2))
            ->method('clearAttempts')
            ->willReturnCallback(function ($key) use (&$clearedKeys) {
                $clearedKeys[] = $key;
            });

        $controller = $this->createController();

        $request = new Request([], [
            'client_id' => 'cli_1',
            'grant_type' => 'authorization_code',
            'code' => 'auth_code_xyz',
            'redirect_uri' => 'https://app.test/cb',
        ]);

        $response = $controller->token('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('jwt_token_123', $data['access_token']);

        $this->assertCount(2, $clearedKeys);
        $this->assertTrue(str_contains($clearedKeys[1], 'oauth_token_ip:'));
    }

    public function testRevokeRateLimitedIpReturns429WithRetryAfter(): void
    {
        $project = new Project();
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $this->lockoutService->method('isLockedOut')
            ->willReturnCallback(fn ($key) => str_starts_with($key, 'oauth_revoke_ip:'));
        $this->lockoutService->method('getRemainingLockoutSeconds')->willReturn(600);

        $controller = $this->createController();

        $request = new Request([], ['client_id' => 'cli_1', 'token' => 'token_to_revoke']);
        $response = $controller->revoke('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('600', $response->headers->get('Retry-After'));
        $data = json_decode($response->getContent(), true);
        $this->assertSame('slow_down', $data['error']);
        $this->assertStringContainsString('project_oauth.error.revoke_rate_limited', $data['error_description']);
    }

    public function testRevokeSuccessClearsLockoutAttempts(): void
    {
        $project = new Project();
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $this->oauthService->method('validateClient')->willReturn($client);
        $this->oauthService->expects($this->once())->method('revokeToken')->with($client, 'valid_tok');

        $this->lockoutService->expects($this->once())
            ->method('clearAttempts')
            ->with($this->stringContains('oauth_revoke_ip:'));

        $controller = $this->createController();

        $request = new Request([], ['client_id' => 'cli_1', 'token' => 'valid_tok']);
        $response = $controller->revoke('00000000-0000-0000-0000-000000000001', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('revoked', $data['status']);
    }

    public function testEndUserForgotPasswordInvalidEmailFormatReturns422(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(\App\Service\TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $lockout = $this->createMock(ProjectAuthLockoutService::class);

        $project = new Project();
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            lockoutService: $lockout,
            translator: $this->translator
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'invalid-email-format-without-at']));
        $response = $controller->forgotPassword($request, '00000000-0000-0000-0000-000000000001');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('enduser.auth.invalid_email_format', $data['error']);
    }

    public function testEndUserForgotPasswordSprayLockoutReturns429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(\App\Service\TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $lockout = $this->createMock(ProjectAuthLockoutService::class);

        $project = new Project();
        $projectRepo->method('findOneBy')->willReturn($project);

        $lockout->method('isLockedOut')
            ->willReturnCallback(fn ($key) => str_starts_with($key, 'pw_reset_spray_ip:'));
        $lockout->method('getRemainingLockoutSeconds')->willReturn(900);

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            lockoutService: $lockout,
            translator: $this->translator
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'victim@example.org']));
        $response = $controller->forgotPassword($request, '00000000-0000-0000-0000-000000000001');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('900', $response->headers->get('Retry-After'));
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('enduser.auth.reset_ip_locked_out', $data['error']);
    }

    public function testEndUserResetPasswordPasswordConfirmationMismatchReturns422(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactor = $this->createMock(\App\Service\TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $lockout = $this->createMock(ProjectAuthLockoutService::class);

        $project = new Project();
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactor,
            $hasher,
            $em,
            lockoutService: $lockout,
            translator: $this->translator
        );

        $tokenStr = str_repeat('a', 64);
        $payload = [
            'token' => $tokenStr,
            'password' => 'NewPassword!123',
            'password_confirmation' => 'DifferentPassword!123',
        ];
        $request = new Request([], [], [], [], [], [], json_encode($payload));
        $response = $controller->resetPassword($request, '00000000-0000-0000-0000-000000000001');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('enduser.auth.password_confirmation_mismatch', $data['error']);
    }

    public function testPasswordResetTokenRepositorySignatures(): void
    {
        $registry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $classMetadata = new \Doctrine\ORM\Mapping\ClassMetadata(PasswordResetToken::class);
        $em->method('getClassMetadata')->willReturn($classMetadata);
        $registry->method('getManagerForClass')->willReturn($em);

        $repo = new PasswordResetTokenRepository($registry);

        $reflection = new \ReflectionClass($repo);
        $findMethod = $reflection->getMethod('findValidByToken');
        $this->assertSame(2, $findMethod->getNumberOfParameters());
        $this->assertTrue($findMethod->getParameters()[1]->isOptional());

        $countMethod = $reflection->getMethod('countExpired');
        $this->assertSame(1, $countMethod->getNumberOfParameters());
        $this->assertTrue($countMethod->getParameters()[0]->isOptional());

        $deleteMethod = $reflection->getMethod('deleteExpired');
        $this->assertSame(1, $deleteMethod->getNumberOfParameters());
        $this->assertTrue($deleteMethod->getParameters()[0]->isOptional());
    }
}
