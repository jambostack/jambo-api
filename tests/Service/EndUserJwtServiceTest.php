<?php

namespace App\Tests\Service;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Service\EndUserJwtService;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

class EndUserJwtServiceTest extends TestCase
{
    private function createEndUser(): EndUser
    {
        $project = new Project();
        $project->uuid = Uuid::v4();

        $endUser = new EndUser($project, 'user@test.com');
        $r = new \ReflectionClass($endUser);
        $p = $r->getProperty('uuid');
        $p->setValue($endUser, Uuid::v4());

        return $endUser;
    }

    public function testCreateAndValidateAccessToken(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('test-secret-key-that-is-at-least-256-bits-long', $clock);
        $endUser = $this->createEndUser();

        $token = $service->createAccessToken($endUser);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $claims = $service->validateToken($token);
        $this->assertNotNull($claims);
        $this->assertSame($endUser->uuid->toString(), $claims['euid']);
        $this->assertSame($endUser->uuid->toString(), $claims['sub']);
        $this->assertSame($endUser->project->uuid->toString(), $claims['pid']);
        $this->assertFalse($service->isRefreshToken($claims));
    }

    public function testRefreshTokenHasRefClaim(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('test-secret-at-least-256-bits-long!!', $clock);
        $endUser = $this->createEndUser();

        $token = $service->createRefreshToken($endUser);
        $claims = $service->validateToken($token);
        $this->assertTrue($service->isRefreshToken($claims));
    }

    public function testInvalidTokenReturnsNull(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());
        $service = new EndUserJwtService('test-secret-at-least-256-bits-long!!', $clock);

        $this->assertNull($service->validateToken('invalid.jwt.token'));
    }

    public function testTokenWithWrongSecretReturnsNull(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $serviceA = new EndUserJwtService('secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $clock);
        $serviceB = new EndUserJwtService('secret-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $clock);

        $token = $serviceA->createAccessToken($this->createEndUser());
        $this->assertNull($serviceB->validateToken($token));
    }

    public function testExpiredTokenIsRejected(): void
    {
        // Horloge figée dans le passé pour créer le token, puis avancée pour la validation
        $past = new \DateTimeImmutable('-1 hour');
        $now  = new \DateTimeImmutable();

        $clockAtCreation = $this->createStub(ClockInterface::class);
        $clockAtCreation->method('now')->willReturn($past);

        $clockNow = $this->createStub(ClockInterface::class);
        $clockNow->method('now')->willReturn($now);

        $secret = 'secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $creator   = new EndUserJwtService($secret, $clockAtCreation);
        $validator = new EndUserJwtService($secret, $clockNow);

        // Le token expire dans 15 min à partir du passé, donc il est expiré maintenant
        $token = $creator->createAccessToken($this->createEndUser());
        $this->assertNull($validator->validateToken($token), 'Un token expiré doit être rejeté');
    }

    public function testTokenIssuedInTheFutureIsRejected(): void
    {
        // Token créé avec une horloge dans le futur (iat > now) — StrictValidAt rejette, LooseValidAt accepte
        $future = new \DateTimeImmutable('+2 hours');
        $now    = new \DateTimeImmutable();

        $clockFuture = $this->createStub(ClockInterface::class);
        $clockFuture->method('now')->willReturn($future);

        $clockNow = $this->createStub(ClockInterface::class);
        $clockNow->method('now')->willReturn($now);

        $secret = 'secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $creator   = new EndUserJwtService($secret, $clockFuture);
        $validator = new EndUserJwtService($secret, $clockNow);

        $token = $creator->createAccessToken($this->createEndUser());
        $this->assertNull($validator->validateToken($token), 'Un token avec iat dans le futur doit être rejeté');
    }

    public function testAlgorithmConfusionNoneIsRejected(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $clock);

        // JWT avec header alg: "none"
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['sub' => '123', 'pid' => 'abc', 'exp' => time() + 3600, 'iat' => time(), 'nbf' => time()])), '+/', '-_'), '=');
        $token = $header . '.' . $payload . '.';

        $this->assertNull($service->validateToken($token), 'Token avec alg: none doit être rejeté');
    }

    public function testAlgorithmConfusionAsymmetricIsRejected(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $clock);

        // JWT avec header alg: "RS256"
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'RS256'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['sub' => '123', 'pid' => 'abc', 'exp' => time() + 3600, 'iat' => time(), 'nbf' => time()])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake-rsa-signature'), '+/', '-_'), '=');
        $token = $header . '.' . $payload . '.' . $signature;

        $this->assertNull($service->validateToken($token), 'Token avec alg: RS256 doit être rejeté');
    }

    public function testTwoFactorTokenCanBeCreatedAndValidated(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $clock);
        $endUser = $this->createEndUser();

        $emailCodeHash = hash('sha256', '123456');
        $tfaToken = $service->createTwoFactorToken($endUser, $emailCodeHash);

        $this->assertNotEmpty($tfaToken);
        $claims = $service->validateTwoFactorToken($tfaToken);

        $this->assertNotNull($claims);
        $this->assertTrue($claims['tfa']);
        $this->assertSame($emailCodeHash, $claims['ech']);
        $this->assertSame($endUser->uuid->toRfc4122(), $claims['euid']);
        $this->assertFalse($service->isAccessToken($claims));
    }

    public function testAbnormalTtlExceedingMaxIsRejected(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $now = new \DateTimeImmutable();
        $clock->method('now')->willReturn($now);

        $service = new EndUserJwtService('secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $clock);
        $endUser = $this->createEndUser();
        // Définir un TTL excessif de 10 ans
        $endUser->project->jwtAccessTtl = 315360000;

        $token = $service->createAccessToken($endUser);
        $this->assertNull($service->validateToken($token), 'Un token avec un TTL anormal dépassant le plafond MAX_TTL doit être rejeté');
    }

    public function testIsAccessTokenHelper(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $service = new EndUserJwtService('secret-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $clock);
        $endUser = $this->createEndUser();

        $accessToken = $service->createAccessToken($endUser);
        $accessClaims = $service->validateToken($accessToken);
        $this->assertTrue($service->isAccessToken($accessClaims));

        $refreshToken = $service->createRefreshToken($endUser);
        $refreshClaims = $service->validateToken($refreshToken);
        $this->assertFalse($service->isAccessToken($refreshClaims));

        $tfaToken = $service->createTwoFactorToken($endUser);
        $tfaClaims = $service->validateTwoFactorToken($tfaToken);
        $this->assertFalse($service->isAccessToken($tfaClaims));
    }
}
