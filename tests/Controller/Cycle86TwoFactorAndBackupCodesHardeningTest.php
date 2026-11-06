<?php

namespace App\Tests\Controller;

use App\Controller\Api\EndUserAdminController;
use App\Controller\UserManagement\UserController;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\EndUserRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\ProjectAuthLockoutService;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle86TwoFactorAndBackupCodesHardeningTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            $msg = 'trans:' . $id;
            foreach ($params as $k => $v) {
                $msg .= "[$k=$v]";
            }
            return $msg;
        });
        return $translator;
    }

    public function testTwoFactorServiceBackupCodeHelpers(): void
    {
        $service = new TwoFactorService();

        $this->assertTrue($service->isBackupCodeFormat('1234-5678'));
        $this->assertTrue($service->isBackupCodeFormat('12345678'));
        $this->assertTrue($service->isBackupCodeFormat('abcd-ef01'));
        $this->assertFalse($service->isBackupCodeFormat('123456')); // 6-digit TOTP
        $this->assertFalse($service->isBackupCodeFormat('1234-5678-90'));
        $this->assertFalse($service->isBackupCodeFormat(''));

        $generated = $service->generateBackupCodes();
        $this->assertSame(8, $service->getRemainingBackupCodesCount($generated['hashes']));
        $this->assertTrue($service->hasRemainingBackupCodes($generated['hashes']));

        // Consume one code
        $codes = $generated['hashes'];
        $consumed = $service->verifyAndConsumeBackupCode($codes, $generated['plaintext'][0]);
        $this->assertTrue($consumed);
        $this->assertSame(7, $service->getRemainingBackupCodesCount($codes));
        $this->assertTrue($service->hasRemainingBackupCodes($codes));
    }

    public function testAdminResetTwoFactorForUser(): void
    {
        $superAdmin = new User();
        $superAdmin->email = 'admin@jambo.local';
        $superAdmin->roles = ['ROLE_SUPER_ADMIN'];

        $targetUser = new User();
        $targetUser->email = 'locked@jambo.local';
        $targetUser->twoFactorEnabled = true;
        $targetUser->twoFactorMethod = 'totp';
        $targetUser->twoFactorSecret = 'SECRET123';
        $targetUser->twoFactorBackupCodes = [['hash' => 'hash1', 'used' => false]];
        $targetUser->twoFactorConfirmedAt = new \DateTimeImmutable();

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())->method('find')->with(12)->willReturn($targetUser);

        $roleRepo = $this->createMock(RoleRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createTranslator();
        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())->method('log')->with(
            $this->equalTo('admin_user_2fa_reset'),
            $this->isNull(),
            $this->callback(fn ($in) => $in['email'] === 'locked@jambo.local'),
            $this->callback(fn ($out) => $out['success'] === true),
            $this->equalTo('success')
        );
        $auditService->expects($this->once())->method('flush');

        $lockoutService = $this->createMock(ProjectAuthLockoutService::class);
        $lockoutService->expects($this->atLeastOnce())->method('clearAttempts');

        $controller = new UserController(
            $userRepo,
            $roleRepo,
            $em,
            $hasher,
            $translator,
            $auditService,
            $lockoutService
        );

        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($superAdmin);
        $tokenStorage->method('getToken')->willReturn($token);
        $container->set('security.token_storage', $tokenStorage);
        $controller->setContainer($container);

        $response = $controller->resetTwoFactor(12);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);

        $this->assertFalse($targetUser->twoFactorEnabled);
        $this->assertNull($targetUser->twoFactorMethod);
        $this->assertNull($targetUser->twoFactorSecret);
        $this->assertNull($targetUser->twoFactorBackupCodes);
        $this->assertNull($targetUser->twoFactorConfirmedAt);
        $this->assertFalse($data['two_factor_enabled']);
        $this->assertStringContainsString('locked@jambo.local', $data['message']);
    }

    public function testAdminResetTwoFactorRejectsWhenNotEnabled(): void
    {
        $superAdmin = new User();
        $superAdmin->roles = ['ROLE_SUPER_ADMIN'];

        $targetUser = new User();
        $targetUser->email = 'not2fa@jambo.local';
        $targetUser->twoFactorEnabled = false;

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())->method('find')->with(15)->willReturn($targetUser);

        $roleRepo = $this->createMock(RoleRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createTranslator();
        $auditService = $this->createMock(AuditService::class);

        $controller = new UserController(
            $userRepo,
            $roleRepo,
            $em,
            $hasher,
            $translator,
            $auditService
        );

        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($superAdmin);
        $tokenStorage->method('getToken')->willReturn($token);
        $container->set('security.token_storage', $tokenStorage);
        $controller->setContainer($container);

        $response = $controller->resetTwoFactor(15);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('trans:user_management.2fa_not_enabled', $data['error']);
    }

    public function testAdminResetTwoFactorRejectsSelfReset(): void
    {
        $superAdmin = new User();
        $superAdmin->roles = ['ROLE_SUPER_ADMIN'];

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())->method('find')->with(1)->willReturn($superAdmin);

        $controller = new UserController(
            $userRepo,
            $this->createMock(RoleRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createTranslator(),
            $this->createMock(AuditService::class)
        );

        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($superAdmin);
        $tokenStorage->method('getToken')->willReturn($token);
        $container->set('security.token_storage', $tokenStorage);
        $controller->setContainer($container);

        $response = $controller->resetTwoFactor(1);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('trans:user_management.cannot_reset_self_2fa_via_admin', $data['error']);
    }

    public function testProjectAdminResetTwoFactorForEndUser(): void
    {
        $projectUuid = '00000000-0000-4000-8000-000000000001';
        $endUserUuid = '00000000-0000-4000-8000-000000000002';

        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $endUser = new EndUser($project, 'enduser@portal.local');
        $endUser->uuid = Uuid::fromString($endUserUuid);
        $endUser->twoFactorEnabled = true;
        $endUser->twoFactorMethod = 'totp';
        $endUser->twoFactorSecret = 'EUSECRET';
        $endUser->twoFactorBackupCodes = [['hash' => 'hash99', 'used' => false]];

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->expects($this->once())->method('findOneBy')->with(['uuid' => $projectUuid])->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->expects($this->once())->method('findOneBy')->with(['uuid' => $endUserUuid, 'project' => $project])->willReturn($endUser);

        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $currentUser = new User();
        $currentUser->email = 'owner@jambo.local';
        $currentUser->roles = ['ROLE_SUPER_ADMIN'];
        $project->owner = $currentUser;

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($currentUser);

        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createTranslator();

        $lockoutService = $this->createMock(ProjectAuthLockoutService::class);
        $lockoutService->expects($this->atLeastOnce())->method('clearAttempts');

        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())->method('log')->with(
            $this->equalTo('admin_enduser_2fa_reset'),
            $this->equalTo($project),
            $this->callback(fn ($in) => $in['email'] === 'enduser@portal.local'),
            $this->callback(fn ($out) => $out['success'] === true),
            $this->equalTo('success')
        );

        $controller = new EndUserAdminController(
            $projectRepo,
            $endUserRepo,
            $memberRepo,
            $em,
            $security,
            $tokenChecker,
            $hasher,
            null,
            null,
            $translator,
            null,
            $lockoutService,
            $auditService
        );

        $container = new Container();
        $controller->setContainer($container);

        $request = new Request();
        $response = $controller->resetTwoFactor($projectUuid, $endUserUuid, $request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);

        $this->assertFalse($endUser->twoFactorEnabled);
        $this->assertNull($endUser->twoFactorMethod);
        $this->assertNull($endUser->twoFactorSecret);
        $this->assertNull($endUser->twoFactorBackupCodes);
        $this->assertFalse($data['two_factor_enabled']);
        $this->assertStringContainsString('enduser@portal.local', $data['message']);
    }
}
