<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EndUser;
use App\Entity\PasswordResetToken;
use App\Entity\Project;
use App\Entity\ProjectAuthSession;
use App\Service\ProjectAuthLockoutService;
use App\Service\Security\PasswordPolicyValidator;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle57EndUserAuthHardeningTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Project $project;
    private string $projectUuid;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Cycle57 Auth Project ' . bin2hex(random_bytes(4));
        $project->publicApi = true;
        $project->settings = [
            'security' => [
                'endUserTwoFactor' => true,
                'requirePasswordComplexity' => false,
            ],
        ];
        $this->em->persist($project);
        $this->em->flush();

        $this->project = $project;
        $this->projectUuid = $project->uuid->toString();

        self::ensureKernelShutdown();
    }

    private function baseUrl(): string
    {
        return '/api/' . $this->projectUuid . '/auth';
    }

    /**
     * Test 1: I18N key parity across all 4 locales (EN, FR, ES, AR)
     */
    public function testTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertSame(count($en), count($fr), 'FR keys count mismatch');
        self::assertSame(count($en), count($es), 'ES keys count mismatch');
        self::assertSame(count($en), count($ar), 'AR keys count mismatch');

        $requiredKeys = [
            'enduser.auth.account_locked_out',
            'enduser.auth.rate_limit_2fa_lockout',
            'enduser.auth.reset_cooldown',
            'enduser.auth.sessions_revoked',
            'enduser.auth.payload_too_large',
            'password.policy.require_special',
            'password.policy.repetitive_chars',
        ];

        foreach ($requiredKeys as $key) {
            self::assertArrayHasKey($key, $en, "Missing {$key} in EN");
            self::assertArrayHasKey($key, $fr, "Missing {$key} in FR");
            self::assertArrayHasKey($key, $es, "Missing {$key} in ES");
            self::assertArrayHasKey($key, $ar, "Missing {$key} in AR");
        }
    }

    /**
     * Test 2: Oversized JSON payload (> 64KB) returns 413 Payload Too Large
     */
    public function testPayloadLimitOnAuthEndpoints(): void
    {
        $client = static::createClient();
        $hugePayload = ['email' => 'test@example.com', 'padding' => str_repeat('A', 66000)];

        $client->jsonRequest('POST', $this->baseUrl() . '/login', $hugePayload);
        self::assertSame(413, $client->getResponse()->getStatusCode());

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $body);
    }

    /**
     * Test 3: Anti-enumeration on login: non-existent email vs wrong password
     */
    public function testLoginAntiEnumerationUniformResponses(): void
    {
        $client = static::createClient();

        // 1. Non-existent email
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => 'nonexistent-' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'WrongPassword123!',
        ]);
        self::assertSame(401, $client->getResponse()->getStatusCode());
        $bodyNonExistent = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Invalid credentials.', $bodyNonExistent['error']);

        // 2. Existing user with wrong password
        $email = 'existing-' . bin2hex(random_bytes(4)) . '@example.com';
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'CorrectPassword123!',
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $email,
            'password' => 'WrongPassword123!',
        ]);
        self::assertSame(401, $client->getResponse()->getStatusCode());
        $bodyWrongPassword = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($bodyNonExistent['error'], $bodyWrongPassword['error']);
    }

    /**
     * Test 4: Inactive account with wrong password does not reveal inactive state
     */
    public function testInactiveAccountDoesNotLeakStatusOnWrongPassword(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);

        $email = 'banned-' . bin2hex(random_bytes(4)) . '@example.com';
        $endUser = new EndUser($project, $email);
        $endUser->password = $hasher->hashPassword($endUser, 'RealPassword123!');
        $endUser->status = 'banned';
        $em->persist($endUser);
        $em->flush();

        // Wrong password on banned account -> returns 401 Invalid credentials (NOT 403 Account inactive)
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $email,
            'password' => 'IncorrectPassword999',
        ]);
        self::assertSame(401, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Invalid credentials.', $body['error']);

        // Correct password on banned account -> returns 403 Account inactive
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $email,
            'password' => 'RealPassword123!',
        ]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * Test 5: Brute-force account lockout tier
     */
    public function testAccountLockoutAfterFailedAttempts(): void
    {
        $client = static::createClient();
        $lockoutService = self::getContainer()->get(ProjectAuthLockoutService::class);

        $targetEmail = 'lockout-target-' . bin2hex(random_bytes(4)) . '@example.com';
        $lockoutKey = 'enduser_login:' . $this->projectUuid . ':' . strtolower($targetEmail);

        // Clear any previous attempts
        $lockoutService->clearAttempts($lockoutKey);

        // Record 5 failed attempts
        for ($i = 1; $i <= 5; $i++) {
            $lockoutService->recordFailedAttempt($lockoutKey);
        }

        self::assertTrue($lockoutService->isLockedOut($lockoutKey));

        // Attempt login -> should return 429 Too Many Requests with Retry-After
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => $targetEmail,
            'password' => 'SomeAttempt123',
        ]);

        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('locked', strtolower($body['error']));

        // Clean up
        $lockoutService->clearAttempts($lockoutKey);
    }

    /**
     * Test 6: Password policy validator: repetitive, sequential, and special characters
     */
    public function testPasswordPolicyHardening(): void
    {
        $validator = new PasswordPolicyValidator();
        $project = new Project();
        $project->settings = [
            'security' => [
                'requirePasswordComplexity' => true,
                'requireSpecialChar' => true,
            ],
        ];

        // 1. Repetitive characters: aaaa, 1111
        $resRepetitive = $validator->validate('Aaaaaa123!', null, null, $project);
        self::assertFalse($resRepetitive['valid']);
        self::assertContains('Password cannot contain 4 or more repetitive or sequential characters.', $resRepetitive['errors']);

        // 2. Sequential pattern: 1234, abcd
        $resSequential = $validator->validate('SecretAbcd12!', null, null, $project);
        self::assertFalse($resSequential['valid']);
        self::assertContains('Password cannot contain 4 or more repetitive or sequential characters.', $resSequential['errors']);

        // 3. Missing special character
        $resNoSpecial = $validator->validate('AlphaBetaDigit99', null, null, $project);
        self::assertFalse($resNoSpecial['valid']);
        self::assertContains('Password must include at least one special character.', $resNoSpecial['errors']);

        // 4. Common password rejection
        $resCommon = $validator->validate('Dragon123!', null, null, $project);
        self::assertFalse($resCommon['valid']);
        self::assertContains('Password is too common or easily guessable.', $resCommon['errors']);

        // 5. Compliant password passes
        $resValid = $validator->validate('StR0ng#Safe!987b', 'alice@example.com', 'alice', $project);
        self::assertTrue($resValid['valid']);
        self::assertEmpty($resValid['errors']);
    }

    /**
     * Test 7: Timing-safe backup code consumption
     */
    public function testBackupCodeTimingSafeConsumption(): void
    {
        $service = new TwoFactorService();
        $result = $service->generateBackupCodes();
        $hashes = $result['hashes'];
        $plaintexts = $result['plaintext'];

        // Consuming first code
        self::assertTrue($service->verifyAndConsumeBackupCode($hashes, $plaintexts[0]));
        // Re-consuming same code must fail
        self::assertFalse($service->verifyAndConsumeBackupCode($hashes, $plaintexts[0]));

        // Consuming last code (index 7)
        self::assertTrue($service->verifyAndConsumeBackupCode($hashes, $plaintexts[7]));
        self::assertFalse($service->verifyAndConsumeBackupCode($hashes, $plaintexts[7]));

        // Invalid code must fail
        self::assertFalse($service->verifyAndConsumeBackupCode($hashes, 'ZZZZ-9999'));
    }

    /**
     * Test 8: Forgot-password cooldown & uniform responses
     */
    public function testForgotPasswordCooldownAndUniformity(): void
    {
        $client = static::createClient();

        // 1. Non-existent email -> 200 with generic message
        $client->jsonRequest('POST', $this->baseUrl() . '/forgot-password', [
            'email' => 'unknown-' . bin2hex(random_bytes(4)) . '@example.com',
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $bodyUnknown = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('reset link has been sent', $bodyUnknown['message']);

        // 2. Register user & request forgot-password
        $email = 'forgot-' . bin2hex(random_bytes(4)) . '@example.com';
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'StrongP@ssw0rd123!',
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->jsonRequest('POST', $this->baseUrl() . '/forgot-password', [
            'email' => $email,
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $bodyKnown = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($bodyUnknown['message'], $bodyKnown['message']);

        // 3. Immediate repeated request -> cooldown prevents duplicate email, returns same 200
        $client->jsonRequest('POST', $this->baseUrl() . '/forgot-password', [
            'email' => $email,
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Test 9: Reset-password token validation and anti-enumeration
     */
    public function testResetPasswordTokenValidationAndAntiEnumeration(): void
    {
        $client = static::createClient();

        // 1. Malformed token (not 64-char hex)
        $client->jsonRequest('POST', $this->baseUrl() . '/reset-password', [
            'token'    => 'invalid-short-token',
            'password' => 'NewStrongPass!123',
        ]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $bodyMalformed = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Invalid or expired reset token.', $bodyMalformed['error']);

        // 2. Non-existent 64-char hex token
        $fakeHexToken = str_repeat('a', 64);
        $client->jsonRequest('POST', $this->baseUrl() . '/reset-password', [
            'token'    => $fakeHexToken,
            'password' => 'NewStrongPass!123',
        ]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $bodyNonExistent = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($bodyMalformed['error'], $bodyNonExistent['error']);
    }

    /**
     * Test 10: Revoke all sessions for authenticated end user
     */
    public function testRevokeAllSessionsEndpoint(): void
    {
        $client = static::createClient();
        $email = 'sessions-user-' . bin2hex(random_bytes(4)) . '@example.com';

        // Register
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'ValidPass123!#',
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $regData = json_decode((string) $client->getResponse()->getContent(), true);
        $token = $regData['data']['access_token'];

        // Revoke all sessions
        $client->jsonRequest('DELETE', $this->baseUrl() . '/sessions', [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $revokeBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('All sessions have been revoked successfully.', $revokeBody['message']);
    }

    /**
     * Test 11: Multi-tier lockout calculation in ProjectAuthLockoutService
     */
    public function testMultiTierLockoutService(): void
    {
        $lockoutService = self::getContainer()->get(ProjectAuthLockoutService::class);
        $testKey = 'test_tier_' . bin2hex(random_bytes(6));

        self::assertSame(0, $lockoutService->getLockoutTier($testKey));
        self::assertFalse($lockoutService->isLockedOut($testKey));

        // 4 attempts: below Tier 1
        for ($i = 1; $i <= 4; $i++) {
            $lockoutService->recordTieredFailedAttempt($testKey);
        }
        self::assertSame(0, $lockoutService->getLockoutTier($testKey));
        self::assertFalse($lockoutService->isLockedOut($testKey));

        // 5th attempt: triggers Tier 1 (300s)
        $lockoutService->recordTieredFailedAttempt($testKey);
        self::assertSame(1, $lockoutService->getLockoutTier($testKey));
        self::assertTrue($lockoutService->isLockedOut($testKey));
        self::assertGreaterThanOrEqual(290, $lockoutService->getRemainingLockoutSeconds($testKey));

        // 10th attempt: triggers Tier 2 (900s)
        for ($i = 6; $i <= 10; $i++) {
            $lockoutService->recordTieredFailedAttempt($testKey);
        }
        self::assertSame(2, $lockoutService->getLockoutTier($testKey));
        self::assertGreaterThanOrEqual(890, $lockoutService->getRemainingLockoutSeconds($testKey));

        // Clean up
        $lockoutService->clearAttempts($testKey);
        self::assertFalse($lockoutService->isLockedOut($testKey));
    }

    /**
     * Test 12: IP brute force lockout defense on login
     */
    public function testIpLevelBruteForceLockoutOnLogin(): void
    {
        $client = static::createClient();
        $lockoutService = self::getContainer()->get(ProjectAuthLockoutService::class);

        $ipLockoutKey = 'enduser_login_ip:' . $this->projectUuid . ':127.0.0.1';
        $lockoutService->clearAttempts($ipLockoutKey);

        // Simulate IP hitting tier threshold (10 failed attempts)
        for ($i = 1; $i <= 10; $i++) {
            $lockoutService->recordTieredFailedAttempt($ipLockoutKey, [10 => 300, 20 => 900, 30 => 3600]);
        }
        self::assertTrue($lockoutService->isLockedOut($ipLockoutKey));

        // Next login attempt should be rejected with 429
        $client->jsonRequest('POST', $this->baseUrl() . '/login', [
            'email'    => 'any-user@example.com',
            'password' => 'AnyPassword123!',
        ]);

        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('ip', strtolower($body['error']));

        // Clean up
        $lockoutService->clearAttempts($ipLockoutKey);
    }

    /**
     * Test 13: Password change current-password lockout on PATCH /me
     */
    public function testPasswordChangeLockoutOnMeEndpoint(): void
    {
        $client = static::createClient();
        $email = 'pw-change-user-' . bin2hex(random_bytes(4)) . '@example.com';

        // Register
        $client->jsonRequest('POST', $this->baseUrl() . '/register', [
            'email'    => $email,
            'password' => 'InitialPassword123!',
        ]);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $regData = json_decode((string) $client->getResponse()->getContent(), true);
        $token = $regData['data']['access_token'];
        $userUuid = $regData['data']['user']['id'] ?? $regData['data']['user']['uuid'];

        $lockoutService = self::getContainer()->get(ProjectAuthLockoutService::class);
        $pwChangeKey = 'pw_change:' . $userUuid;
        $lockoutService->clearAttempts($pwChangeKey);

        // Pre-fill 5 failed attempts to lock out password change
        for ($i = 1; $i <= 5; $i++) {
            $lockoutService->recordTieredFailedAttempt($pwChangeKey, [5 => 300, 10 => 900, 15 => 3600]);
        }
        self::assertTrue($lockoutService->isLockedOut($pwChangeKey));

        // Attempting to change password should return 429
        $client->jsonRequest('PATCH', $this->baseUrl() . '/me', [
            'current_password' => 'WrongPass123!',
            'password'         => 'BrandNewPassword123!',
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('locked', strtolower($body['error']));

        // Clean up
        $lockoutService->clearAttempts($pwChangeKey);
    }

    /**
     * Test 14: Token brute force lockout on reset-password
     */
    public function testResetPasswordTokenBruteForceLockout(): void
    {
        $client = static::createClient();
        $lockoutService = self::getContainer()->get(ProjectAuthLockoutService::class);

        $ipTokenKey = 'pw_reset_token_ip:' . $this->projectUuid . ':127.0.0.1';
        $lockoutService->clearAttempts($ipTokenKey);

        // Pre-fill 5 invalid token attempts
        for ($i = 1; $i <= 5; $i++) {
            $lockoutService->recordTieredFailedAttempt($ipTokenKey, [5 => 300, 10 => 900, 15 => 3600]);
        }
        self::assertTrue($lockoutService->isLockedOut($ipTokenKey));

        // Next reset password attempt returns 429
        $client->jsonRequest('POST', $this->baseUrl() . '/reset-password', [
            'token'    => str_repeat('f', 64),
            'password' => 'BrandNewPassword123!',
        ]);

        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('token', strtolower($body['error']));

        // Clean up
        $lockoutService->clearAttempts($ipTokenKey);
    }

    /**
     * Test 15: Password policy rejection on project name and ASCII control characters
     */
    public function testPasswordPolicyProjectNameAndControlChars(): void
    {
        $validator = new PasswordPolicyValidator();
        $project = new Project();
        $project->name = 'SuperSecProject';

        // 1. Password contains project name
        $resProj = $validator->validate('MySuperSecProjectPass123!', null, null, $project);
        self::assertFalse($resProj['valid']);
        self::assertContains('Password cannot contain the project name.', $resProj['errors']);

        // 2. Password contains null byte or control char
        $resControl = $validator->validate("StrongPassword\x00123!", null, null, $project);
        self::assertFalse($resControl['valid']);
        self::assertContains('Password cannot contain control characters.', $resControl['errors']);
    }
}
