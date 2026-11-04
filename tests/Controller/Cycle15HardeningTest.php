<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Admin\ProjectMailerSettingsController;
use App\Controller\Admin\ProjectStorageController;
use App\Controller\Api\AssetController;
use App\Controller\InvitationController;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\MediaRepository;
use App\Repository\ProjectMailerSettingsRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\ApiTokenChecker;
use App\Service\ProjectMailerService;
use App\Service\StorageDriverFactory;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle15HardeningTest extends WebTestCase
{
    private function createRejectingLimiterFactory(string $id = 'test'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create('1.2.3.4')->consume();
        return $factory;
    }

    public function testInvitationControllerRateLimitingReturns429(): void
    {
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Trop de tentatives');

        $limiter = $this->createRejectingLimiterFactory('invitation');
        $controller = new InvitationController($memberRepo, $em, $translator, $limiter);

        $request = Request::create('/invitation/accept/some-token', 'GET', [], [], [], [
            'REMOTE_ADDR' => '1.2.3.4',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $controller->accept('some-token', $request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Trop de tentatives', $data['error']);
    }

    public function testMailerTestRateLimitingReturns429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ProjectMailerSettingsRepository::class);
        $mailer = $this->createMock(ProjectMailerService::class);
        $limiter = $this->createRejectingLimiterFactory('mailer');

        $project = new Project();
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = new ProjectMailerSettingsController($em, $repo, $mailer, $limiter);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container = new Container();
        $container->set('security.authorization_checker', $authChecker);
        $controller->setContainer($container);

        $request = Request::create('/api/admin/projects/uuid/mailer/test', 'POST', [], [], [], [
            'REMOTE_ADDR' => '1.2.3.4',
        ]);

        $response = $controller->test($request, 'uuid');
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Too many test requests. Please retry later.', $data['error']);
    }

    public function testStorageTestRateLimitingReturns429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $profileRepo = $this->createMock(ProjectStorageProfileRepository::class);
        $ruleRepo = $this->createMock(StorageRuleRepository::class);
        $driverFactory = $this->createMock(StorageDriverFactory::class);
        $limiter = $this->createRejectingLimiterFactory('storage');

        $project = new Project();
        $projectRepo = $this->createMock(EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $controller = new ProjectStorageController($em, $profileRepo, $ruleRepo, $driverFactory, $limiter);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container = new Container();
        $container->set('security.authorization_checker', $authChecker);
        $controller->setContainer($container);

        $request = Request::create('/api/admin/projects/uuid/storage/profiles/1/test', 'POST', [], [], [], [
            'REMOTE_ADDR' => '1.2.3.4',
        ]);

        $response = $controller->testProfile($request, 'uuid', 1);
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Too many test requests. Please retry later.', $data['error']);
    }

    public function testAppSettingsRejectsOllamaMetadataHost(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $settingsRepo = static::getContainer()->get(\App\Repository\AppSettingsRepository::class);

        $settings = $settingsRepo->getOrCreate();
        $settings->aiProviders = [
            'ollama' => [
                'enabled' => true,
                'url' => 'http://169.254.169.254',
            ],
        ];
        $em->persist($settings);

        $user = new User();
        $user->name = 'Test Super Admin';
        $user->email = 'superadmin_cycle15_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/admin/api/app-settings/models/ollama');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('cloud metadata', $data['error']);
    }

    public function testAssetControllerRejectsXssSvgViaUploadSecurityValidator(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $security = $this->createMock(Security::class);
        $profileRepo = $this->createMock(ProjectStorageProfileRepository::class);
        $ruleRepo = $this->createMock(StorageRuleRepository::class);
        $driverFactory = $this->createMock(StorageDriverFactory::class);
        $validator = new UploadSecurityValidator($mediaRepo);

        $controller = new AssetController(
            $projectRepo,
            $mediaRepo,
            $em,
            $tokenChecker,
            $memberRepo,
            $security,
            $profileRepo,
            $ruleRepo,
            $driverFactory,
            $validator,
        );

        $project = new Project();
        $project->publicApi = true;
        $projectRepo->method('findOneBy')->willReturn($project);

        $user = new User();
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $security->method('getUser')->willReturn($user);

        // Créer un SVG malveillant temporaire
        $tempSvg = tempnam(sys_get_temp_dir(), 'malicious_');
        file_put_contents($tempSvg, '<svg onload="alert(document.cookie)"></svg>');
        $uploadedFile = new UploadedFile($tempSvg, 'exploit.svg', 'image/svg+xml', null, true);

        $request = Request::create('/api/proj-uuid/files', 'POST', [], [], ['file' => $uploadedFile]);

        $response = $controller->upload($request, 'proj-uuid');
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('dangerous inline event handler', $data['error']);

        @unlink($tempSvg);
    }
}
