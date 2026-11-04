<?php

namespace App\Tests\Controller\Api;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthSession;
use App\Repository\EndUserRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use App\Repository\ProjectRepository;
use App\Service\EndUserJwtService;
use App\Service\TwoFactorService;
use App\Controller\Api\EndUserAuthController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EndUserSessionManagementTest extends TestCase
{
    private ProjectRepository $projectRepo;
    private EndUserRepository $endUserRepo;
    private EndUserJwtService $jwtService;
    private TwoFactorService $twoFactorService;
    private UserPasswordHasherInterface $hasher;
    private EntityManagerInterface $em;
    private ProjectAuthRefreshTokenRepository $refreshTokenRepo;
    private ProjectAuthSessionRepository $sessionRepo;
    private EndUserAuthController $controller;

    protected function setUp(): void
    {
        $this->projectRepo = $this->createMock(ProjectRepository::class);
        $this->endUserRepo = $this->createMock(EndUserRepository::class);
        $this->jwtService = $this->createMock(EndUserJwtService::class);
        $this->twoFactorService = $this->createMock(TwoFactorService::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->refreshTokenRepo = $this->createMock(ProjectAuthRefreshTokenRepository::class);
        $this->sessionRepo = $this->createMock(ProjectAuthSessionRepository::class);

        $this->controller = new EndUserAuthController(
            $this->projectRepo,
            $this->endUserRepo,
            $this->jwtService,
            $this->twoFactorService,
            $this->hasher,
            $this->em,
            null,
            null,
            null,
            $this->refreshTokenRepo,
            $this->sessionRepo
        );
        $this->controller->setContainer(new \Symfony\Component\DependencyInjection\Container());
    }

    public function testListSessionsReturnsUserSessions(): void
    {
        $project = new Project();
        $user = new EndUser($project, 'user@example.com');

        $session1 = new ProjectAuthSession();
        $session1->endUser = $user;
        $session1->ipAddress = '198.51.100.1';
        $session1->userAgent = 'Mozilla/5.0';

        $this->sessionRepo->expects($this->once())
            ->method('findByEndUser')
            ->with($user)
            ->willReturn([$session1]);

        $request = new Request();
        $request->attributes->set('_end_user', $user);

        $response = $this->controller->listSessions($request);
        $this->assertSame(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $content);
        $this->assertCount(1, $content['data']);
        $this->assertSame($session1->sessionId, $content['data'][0]['session_id']);
        $this->assertSame('198.51.100.1', $content['data'][0]['ip_address']);
    }

    public function testRevokeSessionRemovesSpecifiedSession(): void
    {
        $project = new Project();
        $user = new EndUser($project, 'user@example.com');

        $session = new ProjectAuthSession();
        $session->endUser = $user;

        $this->sessionRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['sessionId' => $session->sessionId, 'endUser' => $user])
            ->willReturn($session);

        $this->sessionRepo->expects($this->once())
            ->method('revokeSession')
            ->with($session->sessionId);

        $request = new Request();
        $request->attributes->set('_end_user', $user);

        $response = $this->controller->revokeSession($request, $session->sessionId);
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testLogoutRevokesAllSessionsAndRefreshTokens(): void
    {
        $project = new Project();
        $user = new EndUser($project, 'user@example.com');
        $initialTokenVersion = $user->tokenVersion;

        $this->sessionRepo->expects($this->once())
            ->method('revokeAllForEndUser')
            ->with($user);

        $this->refreshTokenRepo->expects($this->once())
            ->method('revokeAllForEndUser')
            ->with($user);

        $this->em->expects($this->once())->method('flush');

        $request = new Request();
        $request->attributes->set('_end_user', $user);

        $response = $this->controller->logout($request);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame($initialTokenVersion + 1, $user->tokenVersion);
    }
}
