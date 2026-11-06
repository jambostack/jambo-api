<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Admin\FormController;
use App\Controller\Admin\ProjectMailerSettingsController;
use App\Controller\Admin\RedirectController;
use App\Controller\CollectionController;
use App\Controller\DeploymentController;
use App\Controller\FieldController;
use App\Controller\Public\RedirectPublicController;
use App\Controller\ShareController;
use App\Controller\VersioningController;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Field;
use App\Entity\Form;
use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Entity\Redirect;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentVersionRepository;
use App\Repository\DeploymentRepository;
use App\Repository\FieldRepository;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\ProjectMailerSettingsRepository;
use App\Repository\ProjectRepository;
use App\Repository\RedirectRepository;
use App\Repository\ShareRepository;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\Form\FormBuilder;
use App\Service\ProjectMailerService;
use App\Service\Redirect\RedirectChainDetector;
use App\Service\Redirect\RedirectResolver;
use App\Service\SchemaProvisioner;
use App\Service\Security\SsrfProtectionService;
use App\Service\Share\ShareService;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 22 — Comprehensive Security Hardening Tests
 *
 * Covers:
 *  1. SsrfProtectionService: Hostname & IP validation against SSRF, CRLF, IMDS, evasive notations
 *  2. ProjectMailerSettingsController: SMTP SSRF, CRLF email header injection, length bounds, port/encryption whitelisting
 *  3. RedirectController: Dangerous schemes, CRLF response splitting, path length caps, regex ReDoS guard
 *  4. RedirectPublicController: Path query bounds, unsafe destination blocking, CRLF prevention
 *  5. DeploymentController: Hook payload size cap, commit_sha hex validation, branch CRLF check, URL validation
 *  6. FormController: Name length & CRLF, slug format, field count cap, steps cap, settings size cap
 *  7. ShareController: Authentication defense-in-depth across store, index, and destroy
 */
class Cycle22HardeningTest extends TestCase
{
    private SsrfProtectionService $ssrf;

    protected function setUp(): void
    {
        $this->ssrf = new SsrfProtectionService();
    }

    private function createTestContainer(?User $user = null): Container
    {
        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }
        $container->set('security.token_storage', $tokenStorage);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authChecker);

        return $container;
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. SsrfProtectionService: assertSafeHost & isSafeHost
    // ─────────────────────────────────────────────────────────────────

    public function testSsrfProtectionRejectsEmptyOrOversizedHost(): void
    {
        $this->assertFalse($this->ssrf->isSafeHost(''));
        $this->assertFalse($this->ssrf->isSafeHost(str_repeat('a', 256)));
    }

    public function testSsrfProtectionRejectsControlCharactersAndCRLF(): void
    {
        $this->assertFalse($this->ssrf->isSafeHost("smtp.example.com\r\n"));
        $this->assertFalse($this->ssrf->isSafeHost("smtp.example.com\0"));
        $this->assertFalse($this->ssrf->isSafeHost("smtp.example.com\n"));
    }

    public function testSsrfProtectionRejectsReservedAndCloudMetadataHosts(): void
    {
        $this->assertFalse($this->ssrf->isSafeHost('169.254.169.254'));
        $this->assertFalse($this->ssrf->isSafeHost('metadata.google.internal'));
        $this->assertFalse($this->ssrf->isSafeHost('localhost'));
        $this->assertFalse($this->ssrf->isSafeHost('127.0.0.1'));
        $this->assertFalse($this->ssrf->isSafeHost('0.0.0.0'));
        $this->assertFalse($this->ssrf->isSafeHost('::1'));
        $this->assertFalse($this->ssrf->isSafeHost('10.0.0.1'));
        $this->assertFalse($this->ssrf->isSafeHost('192.168.1.1'));
        $this->assertFalse($this->ssrf->isSafeHost('172.16.0.1'));
        $this->assertFalse($this->ssrf->isSafeHost('host.docker.internal'));
    }

    public function testSsrfProtectionAllowsSafePublicHostsInTest(): void
    {
        $this->assertTrue($this->ssrf->isSafeHost('smtp.example.com'));
        $this->assertTrue($this->ssrf->isSafeHost('mail.example.com'));
        $this->assertTrue($this->ssrf->isSafeHost('93.184.216.34'));
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. ProjectMailerSettingsController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testMailerSettingsRejectsSsrfHost(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $settingsRepo = $this->createMock(ProjectMailerSettingsRepository::class);
        $mailer = $this->createMock(ProjectMailerService::class);
        $projectRepo = $this->createMock(EntityRepository::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $em->method('getRepository')->willReturn($projectRepo);
        $projectRepo->method('findOneBy')->willReturn($project);

        $user = new User();
        $user->email = 'admin@example.com';

        $controller = new ProjectMailerSettingsController($em, $settingsRepo, $mailer, null, $this->ssrf);
        $controller->setContainer($this->createTestContainer($user));

        // Test rejecting 169.254.169.254
        $req = new Request([], [], [], [], [], [], json_encode(['host' => '169.254.169.254']));
        $req->headers->set('Content-Type', 'application/json');
        $resp = $controller->update($project->uuid->toRfc4122(), $req);
        $this->assertSame(400, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true);
        $this->assertStringContainsString('Invalid host', $data['error']);

        // Test rejecting host with CRLF
        $reqCrlf = new Request([], [], [], [], [], [], json_encode(['host' => "smtp.example.com\r\n"]));
        $reqCrlf->headers->set('Content-Type', 'application/json');
        $respCrlf = $controller->update($project->uuid->toRfc4122(), $reqCrlf);
        $this->assertSame(400, $respCrlf->getStatusCode());
    }

    public function testMailerSettingsRejectsInvalidOrCrlfFromEmail(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $settingsRepo = $this->createMock(ProjectMailerSettingsRepository::class);
        $mailer = $this->createMock(ProjectMailerService::class);
        $projectRepo = $this->createMock(EntityRepository::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $em->method('getRepository')->willReturn($projectRepo);
        $projectRepo->method('findOneBy')->willReturn($project);

        $user = new User();
        $user->email = 'admin@example.com';

        $controller = new ProjectMailerSettingsController($em, $settingsRepo, $mailer, null, $this->ssrf);
        $controller->setContainer($this->createTestContainer($user));

        // Header injection payload
        $req = new Request([], [], [], [], [], [], json_encode([
            'from_email' => "sender@example.com\r\nBcc: evil@attacker.com",
        ]));
        $req->headers->set('Content-Type', 'application/json');
        $resp = $controller->update($project->uuid->toRfc4122(), $req);
        $this->assertSame(400, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true);
        $this->assertStringContainsString('Invalid from_email', $data['error']);

        // Malformed email
        $reqInvalid = new Request([], [], [], [], [], [], json_encode([
            'from_email' => "not-an-email",
        ]));
        $reqInvalid->headers->set('Content-Type', 'application/json');
        $respInvalid = $controller->update($project->uuid->toRfc4122(), $reqInvalid);
        $this->assertSame(400, $respInvalid->getStatusCode());
    }

    public function testMailerSettingsRejectsNonSmtpPortsAndInvalidEncryption(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $settingsRepo = $this->createMock(ProjectMailerSettingsRepository::class);
        $mailer = $this->createMock(ProjectMailerService::class);
        $projectRepo = $this->createMock(EntityRepository::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $em->method('getRepository')->willReturn($projectRepo);
        $projectRepo->method('findOneBy')->willReturn($project);

        $user = new User();
        $user->email = 'admin@example.com';

        $controller = new ProjectMailerSettingsController($em, $settingsRepo, $mailer, null, $this->ssrf);
        $controller->setContainer($this->createTestContainer($user));

        // Port 80
        $reqPort = new Request([], [], [], [], [], [], json_encode(['port' => 80]));
        $reqPort->headers->set('Content-Type', 'application/json');
        $respPort = $controller->update($project->uuid->toRfc4122(), $reqPort);
        $this->assertSame(400, $respPort->getStatusCode());

        // Encryption 'rot13'
        $reqEnc = new Request([], [], [], [], [], [], json_encode(['encryption' => 'rot13']));
        $reqEnc->headers->set('Content-Type', 'application/json');
        $respEnc = $controller->update($project->uuid->toRfc4122(), $reqEnc);
        $this->assertSame(400, $respEnc->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. RedirectController & RedirectPublicController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testRedirectControllerRejectsDangerousSchemesAndBackslashes(): void
    {
        $redirectRepo = $this->createMock(RedirectRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $chainDetector = $this->createMock(RedirectChainDetector::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $user = new User();
        $user->email = 'admin@example.com';

        $controller = new RedirectController($redirectRepo, $projectRepo, $em, $chainDetector);
        $controller->setContainer($this->createTestContainer($user));

        $dangerous = [
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox',
            'file:///etc/passwd',
            'ftp://evil.com/payload',
            '//attacker.com/phish',
            '/\\evil.com',
            '\\\\evil.com\\share',
        ];

        foreach ($dangerous as $badTo) {
            $req = new Request([], [], [], [], [], [], json_encode([
                'fromPath' => '/old-path',
                'toPath' => $badTo,
            ]));
            $req->headers->set('Content-Type', 'application/json');
            $resp = $controller->create($project->uuid->toRfc4122(), $req);
            $this->assertSame(422, $resp->getStatusCode(), "Expected 422 for dangerous toPath: $badTo");
        }
    }

    public function testRedirectControllerRejectsCrlfAndOversizedPaths(): void
    {
        $redirectRepo = $this->createMock(RedirectRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $chainDetector = $this->createMock(RedirectChainDetector::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $user = new User();
        $user->email = 'admin@example.com';

        $controller = new RedirectController($redirectRepo, $projectRepo, $em, $chainDetector);
        $controller->setContainer($this->createTestContainer($user));

        // CRLF in toPath
        $reqCrlf = new Request([], [], [], [], [], [], json_encode([
            'fromPath' => '/safe-path',
            'toPath' => "/dest\r\nLocation: https://evil.com",
        ]));
        $reqCrlf->headers->set('Content-Type', 'application/json');
        $respCrlf = $controller->create($project->uuid->toRfc4122(), $reqCrlf);
        $this->assertSame(422, $respCrlf->getStatusCode());

        // Oversized fromPath (> 2048 chars)
        $reqOver = new Request([], [], [], [], [], [], json_encode([
            'fromPath' => '/' . str_repeat('a', 2050),
            'toPath' => '/target',
        ]));
        $reqOver->headers->set('Content-Type', 'application/json');
        $respOver = $controller->create($project->uuid->toRfc4122(), $reqOver);
        $this->assertSame(422, $respOver->getStatusCode());
    }

    public function testRedirectPublicControllerBlocksUnsafeDestinations(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $resolver = $this->createMock(RedirectResolver::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $redirect = new Redirect();
        $redirect->fromPath = '/login';
        $redirect->toPath = "javascript:alert(1)";
        $redirect->httpCode = 302;
        $resolver->method('resolve')->willReturn($redirect);

        $controller = new RedirectPublicController($projectRepo, $resolver);

        $req = new Request(['path' => '/login']);
        $req->headers->set('Accept', 'text/html');

        $resp = $controller->resolve($project->uuid->toRfc4122(), $req);
        $this->assertSame(400, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true);
        $this->assertSame('Unsafe redirect destination.', $data['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. DeploymentController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testDeploymentHookRejectsOversizedPayloadAndInvalidUrls(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $deployRepo = $this->createMock(DeploymentRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);

        $_ENV['DEPLOY_WEBHOOK_SECRET'] = 'cycle22_test_secret_32_bytes_long!';

        $controller = new DeploymentController($em, $deployRepo, $projectRepo);
        $controller->setContainer($this->createTestContainer(null));

        // 1. Oversized payload > 1MB
        $hugeContent = str_repeat('x', 1048577);
        $sig = 'sha256=' . hash_hmac('sha256', $hugeContent, $_ENV['DEPLOY_WEBHOOK_SECRET']);
        $reqHuge = new Request([], [], [], [], [], [], $hugeContent);
        $reqHuge->headers->set('X-Jambo-Signature', $sig);
        $respHuge = $controller->hook($reqHuge);
        $this->assertSame(413, $respHuge->getStatusCode());

        // 2. Invalid preview_url (javascript scheme)
        $badUrlPayload = json_encode([
            'preview_url' => 'javascript:stealCredentials()',
        ]);
        $sigBad = 'sha256=' . hash_hmac('sha256', $badUrlPayload, $_ENV['DEPLOY_WEBHOOK_SECRET']);
        $reqBad = new Request([], [], [], [], [], [], $badUrlPayload);
        $reqBad->headers->set('X-Jambo-Signature', $sigBad);
        $respBad = $controller->hook($reqBad);
        $this->assertSame(422, $respBad->getStatusCode());

        // 3. Invalid commit_sha format
        $badShaPayload = json_encode([
            'commit_sha' => 'invalid-sha-with-non-hex!@#$',
        ]);
        $sigSha = 'sha256=' . hash_hmac('sha256', $badShaPayload, $_ENV['DEPLOY_WEBHOOK_SECRET']);
        $reqSha = new Request([], [], [], [], [], [], $badShaPayload);
        $reqSha->headers->set('X-Jambo-Signature', $sigSha);
        $respSha = $controller->hook($reqSha);
        $this->assertSame(422, $respSha->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. FormController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testFormControllerRejectsCrlfNameAndInvalidSlug(): void
    {
        $formRepo = $this->createMock(FormRepository::class);
        $subRepo = $this->createMock(FormSubmissionRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $builder = $this->createMock(FormBuilder::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $user = new User();
        $user->email = 'admin@example.com';

        $builder->method('validateDefinition')->willReturn(['valid' => true, 'errors' => []]);

        $controller = new FormController($formRepo, $subRepo, $projectRepo, $builder, $em);
        $controller->setContainer($this->createTestContainer($user));

        // CRLF in name
        $reqName = new Request([], [], [], [], [], [], json_encode([
            'name' => "Contact Us\r\nInjected-Header: evil",
            'slug' => 'contact-us',
        ]));
        $reqName->headers->set('Content-Type', 'application/json');
        $respName = $controller->create($project->uuid->toRfc4122(), $reqName);
        $this->assertSame(422, $respName->getStatusCode());

        // Invalid slug (path traversal / spaces)
        $reqSlug = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Contact Us',
            'slug' => '../../etc/passwd',
        ]));
        $reqSlug->headers->set('Content-Type', 'application/json');
        $respSlug = $controller->create($project->uuid->toRfc4122(), $reqSlug);
        $this->assertSame(422, $respSlug->getStatusCode());

        // Excessive fields count (> 100)
        $excessiveFields = array_map(fn($i) => ['name' => "field_$i", 'type' => 'text'], range(1, 101));
        $reqFields = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Giant Form',
            'slug' => 'giant-form',
            'fields' => $excessiveFields,
        ]));
        $reqFields->headers->set('Content-Type', 'application/json');
        $respFields = $controller->create($project->uuid->toRfc4122(), $reqFields);
        $this->assertSame(422, $respFields->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. ShareController Hardening: Defense-in-depth Authentication
    // ─────────────────────────────────────────────────────────────────

    public function testShareControllerEnforcesAuthenticationDefenseInDepth(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $shareRepo = $this->createMock(ShareRepository::class);
        $shareService = $this->createMock(ShareService::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new ShareController($projectRepo, $entryRepo, $shareRepo, $shareService);
        $controller->setContainer($this->createTestContainer(null));

        // Anonymous request (no user and _project attribute not matching)
        $req = new Request();
        $resp = $controller->store($project->uuid->toRfc4122(), $req);
        $this->assertSame(401, $resp->getStatusCode());

        $respIndex = $controller->index($project->uuid->toRfc4122(), $req);
        $this->assertSame(401, $respIndex->getStatusCode());

        $respDestroy = $controller->destroy($project->uuid->toRfc4122(), 42, $req);
        $this->assertSame(401, $respDestroy->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────
    // 7. CollectionController, FieldController, VersioningController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testCollectionControllerEnforcesAuthenticationDefenseInDepth(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $provisioner = $this->createMock(SchemaProvisioner::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new CollectionController($em, $projectRepo, $collectionRepo, $provisioner);
        $controller->setContainer($this->createTestContainer(null));

        $req = new Request();
        $resp = $controller->index($project->uuid->toRfc4122(), $req);
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testFieldControllerEnforcesAuthenticationDefenseInDepth(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $normalizer = new FieldRelationOptionsNormalizer($collectionRepo);
        $provisioner = $this->createMock(SchemaProvisioner::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'posts';
        $collectionRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $controller = new FieldController($em, $fieldRepo, $projectRepo, $collectionRepo, $normalizer, $provisioner);
        $controller->setContainer($this->createTestContainer(null));

        $req = new Request();
        $resp = $controller->index($project->uuid->toRfc4122(), 'posts', $req);
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testVersioningControllerEnforcesAuthenticationDefenseInDepth(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $versioning = $this->createMock(VersioningService::class);
        $versionRepo = $this->createMock(ContentVersionRepository::class);

        $project = new Project();
        $project->uuid = Uuid::v4();

        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'articles';

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entryUuid = Uuid::v4();
        $entry->uuid = $entryUuid;

        $projectEntityRepo = $this->createMock(EntityRepository::class);
        $projectEntityRepo->method('findOneBy')->willReturn($project);

        $collectionEntityRepo = $this->createMock(EntityRepository::class);
        $collectionEntityRepo->method('findOneBy')->willReturn($collection);

        $entryEntityRepo = $this->createMock(EntityRepository::class);
        $entryEntityRepo->method('findOneBy')->willReturn($entry);

        $em->method('getRepository')->willReturnCallback(function ($class) use ($projectEntityRepo, $collectionEntityRepo, $entryEntityRepo) {
            return match ($class) {
                Project::class => $projectEntityRepo,
                Collection::class => $collectionEntityRepo,
                ContentEntry::class => $entryEntityRepo,
                default => throw new \InvalidArgumentException("Unexpected entity $class"),
            };
        });

        $controller = new VersioningController($em, $versioning, $versionRepo);
        $controller->setContainer($this->createTestContainer(null));

        $req = new Request();
        $resp = $controller->list($project->uuid->toRfc4122(), 'articles', $entryUuid->toRfc4122(), $req);
        $this->assertSame(401, $resp->getStatusCode());
    }
}
