<?php

namespace App\Tests\Security;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Repository\EndUserRepository;
use App\Security\EndUserJwtAuthenticator;
use App\Service\EndUserJwtService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Uid\Uuid;

class EndUserJwtAuthenticatorTest extends TestCase
{
    private EndUserJwtService $jwtService;
    private EndUserRepository $endUserRepo;
    private EndUserJwtAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->jwtService = $this->createMock(EndUserJwtService::class);
        $this->endUserRepo = $this->createMock(EndUserRepository::class);
        $this->authenticator = new EndUserJwtAuthenticator($this->jwtService, $this->endUserRepo);
    }

    public function testSupportsBearerToken(): void
    {
        $req1 = new Request();
        $this->assertFalse($this->authenticator->supports($req1));

        $req2 = new Request();
        $req2->headers->set('Authorization', 'Bearer some.jwt.token');
        $this->assertTrue($this->authenticator->supports($req2));
    }

    public function testRejectsInvalidJwt(): void
    {
        $this->jwtService->method('validateToken')->willReturn(null);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer invalid_jwt');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired token.');

        $this->authenticator->authenticate($req);
    }

    public function testRejectsRefreshTokenUsedAsAccessToken(): void
    {
        $this->jwtService->method('validateToken')->willReturn(['ref' => true]);
        $this->jwtService->method('isRefreshToken')->willReturn(true);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer refresh_jwt');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Refresh tokens cannot be used for API access.');

        $this->authenticator->authenticate($req);
    }

    public function testRejectsTwoFactorTokenUsedAsAccessToken(): void
    {
        $this->jwtService->method('validateToken')->willReturn(['tfa' => true, 'ref' => false]);
        $this->jwtService->method('isRefreshToken')->willReturn(false);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer 2fa_challenge_jwt');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('2FA challenge tokens cannot be used for API access.');

        $this->authenticator->authenticate($req);
    }

    public function testRejectsRevokedTokenVersionMismatch(): void
    {
        $userUuid = Uuid::v4()->toString();
        $projUuid = Uuid::v4();

        $project = new Project();
        $project->uuid = $projUuid;

        $endUser = new EndUser($project, 'user@example.com');
        $endUser->tokenVersion = 2; // user tokenVersion has incremented to 2

        $this->jwtService->method('validateToken')->willReturn([
            'euid' => $userUuid,
            'tkn'  => 1, // token has older version 1
            'pid'  => $projUuid->toString(),
            'ref'  => false,
        ]);
        $this->jwtService->method('isRefreshToken')->willReturn(false);
        $this->endUserRepo->expects($this->once())->method('findOneBy')->with(['uuid' => $userUuid])->willReturn($endUser);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer valid_jwt_old_version');

        $passport = $this->authenticator->authenticate($req);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Token version mismatch - please re-login.');

        $userBadge->getUser();
    }

    public function testRejectsCrossTenantProjectMismatch(): void
    {
        $userUuid = Uuid::v4()->toString();
        $projectA = new Project();
        $projectA->uuid = Uuid::v4();

        $endUser = new EndUser($projectA, 'user@example.com');
        $endUser->tokenVersion = 1;

        $foreignProjectUuid = Uuid::v4()->toString();

        $this->jwtService->method('validateToken')->willReturn([
            'euid' => $userUuid,
            'tkn'  => 1,
            'pid'  => $projectA->uuid->toString(),
            'ref'  => false,
        ]);
        $this->jwtService->method('isRefreshToken')->willReturn(false);
        $this->endUserRepo->expects($this->once())->method('findOneBy')->with(['uuid' => $userUuid])->willReturn($endUser);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer valid_jwt');
        $req->attributes->set('projectId', $foreignProjectUuid); // Route belongs to Project B

        $passport = $this->authenticator->authenticate($req);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Token does not belong to this project.');

        $userBadge->getUser();
    }

    public function testSuccessfulAuthentication(): void
    {
        $userUuid = Uuid::v4()->toString();
        $project = new Project();
        $project->uuid = Uuid::v4();

        $endUser = new EndUser($project, 'user@example.com');
        $endUser->tokenVersion = 1;
        $endUser->status = 'active';

        $this->jwtService->method('validateToken')->willReturn([
            'euid' => $userUuid,
            'tkn'  => 1,
            'pid'  => $project->uuid->toString(),
            'ref'  => false,
        ]);
        $this->jwtService->method('isRefreshToken')->willReturn(false);
        $this->endUserRepo->expects($this->once())->method('findOneBy')->with(['uuid' => $userUuid])->willReturn($endUser);

        $req = new Request();
        $req->headers->set('Authorization', 'Bearer valid_jwt');
        $req->attributes->set('projectId', $project->uuid->toString());

        $passport = $this->authenticator->authenticate($req);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);

        $authenticatedUser = $userBadge->getUser();
        $this->assertSame($endUser, $authenticatedUser);
    }
}
