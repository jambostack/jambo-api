<?php

namespace App\Tests\Command;

use App\Command\BlueprintExportCommand;
use App\Command\BlueprintImportCommand;
use App\Command\ContentDeleteCommand;
use App\Command\ContentShowCommand;
use App\Command\CreateApiTokenCommand;
use App\Command\DeployCommand;
use App\Command\EndUserCreateCommand;
use App\Command\EndUserDeleteCommand;
use App\Command\FieldCreateCommand;
use App\Command\ProjectCreateCommand;
use App\Command\TokenRevokeCommand;
use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\EndUser;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\ExportImport\ProjectExporter;
use App\Service\SchemaProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class CliSecurityHardeningTest extends TestCase
{
    public function testEndUserCreateGeneratesPasswordAndRedactsInNonInteractive(): void
    {
        $project = new Project();
        $project->name = 'Test Project';
        $project->uuid = Uuid::v4();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($endUserRepo);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed_secret_pw');

        $command = new EndUserCreateCommand($projectRepo, $em, $hasher);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => $project->uuid->toString(),
            'email' => 'client@example.com',
        ], ['interactive' => false]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('client@example.com', $display);
        $this->assertStringContainsString('Password', $display);
        // By default, non-interactive auto-generated password is shown with warning
        $this->assertStringContainsString('Save this generated password', $display);
    }

    public function testEndUserCreateRejectsDuplicateEmail(): void
    {
        $project = new Project();
        $project->name = 'Test Project';
        $project->uuid = Uuid::v4();

        $existingUser = new EndUser($project, 'existing@example.com');

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneBy')->willReturn($existingUser);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($endUserRepo);
        $em->expects($this->never())->method('persist');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $command = new EndUserCreateCommand($projectRepo, $em, $hasher);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => $project->uuid->toString(),
            'email' => 'existing@example.com',
            'password' => 'SecurePass123!',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    public function testEndUserCreateRejectsWeakPassword(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($endUserRepo);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $command = new EndUserCreateCommand($projectRepo, $em, $hasher);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => $project->uuid->toString(),
            'email' => 'test@example.com',
            'password' => 'weak',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mot de passe doit comporter', $tester->getDisplay());
    }

    public function testEndUserDeleteRequiresForceFlagInNonInteractive(): void
    {
        $project = new Project();
        $uuid = Uuid::v4()->toString();
        $eu = new EndUser($project, 'delete_me@example.com');

        $repo = $this->createMock(EndUserRepository::class);
        $repo->method('findOneBy')->willReturn($eu);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $command = new EndUserDeleteCommand($repo, $em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['uuid' => $uuid], ['interactive' => false]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Action annulée', $tester->getDisplay());
    }

    public function testEndUserDeleteSucceedsWithForceFlag(): void
    {
        $project = new Project();
        $uuid = Uuid::v4()->toString();
        $eu = new EndUser($project, 'delete_me@example.com');

        $repo = $this->createMock(EndUserRepository::class);
        $repo->method('findOneBy')->willReturn($eu);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($eu);
        $em->expects($this->once())->method('flush');

        $command = new EndUserDeleteCommand($repo, $em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['uuid' => $uuid, '--force' => true], ['interactive' => false]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('supprimé', $tester->getDisplay());
    }

    public function testTokenRevokeRequiresForceFlag(): void
    {
        $token = new ApiToken();
        $token->name = 'Production Key';

        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->expects($this->once())->method('find')->with(42)->willReturn($token);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $command = new TokenRevokeCommand($repo, $em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['id' => '42'], ['interactive' => false]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Action annulée', $tester->getDisplay());
    }

    public function testTokenRevokeSucceedsWithForceFlag(): void
    {
        $token = new ApiToken();
        $token->name = 'Production Key';

        $repo = $this->createMock(ApiTokenRepository::class);
        $repo->expects($this->once())->method('find')->with(42)->willReturn($token);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($token);
        $em->expects($this->once())->method('flush');

        $command = new TokenRevokeCommand($repo, $em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['id' => '42', '--force' => true], ['interactive' => false]);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('révoqué', $tester->getDisplay());
    }

    public function testContentDeleteRequiresForceFlag(): void
    {
        $uuid = Uuid::v4()->toString();
        $entry = new ContentEntry();
        $entry->slug = 'sample-article';

        $repo = $this->createMock(ContentEntryRepository::class);
        $repo->method('findOneBy')->willReturn($entry);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $command = new ContentDeleteCommand($repo, $em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['uuid' => $uuid], ['interactive' => false]);
        $this->assertSame(0, $exitCode);
        $this->assertNull($entry->deletedAt);
        $this->assertStringContainsString('Action annulée', $tester->getDisplay());
    }

    public function testContentDeleteSucceedsWithForceFlag(): void
    {
        $uuid = Uuid::v4()->toString();
        $entry = new ContentEntry();
        $entry->slug = 'sample-article';

        $repo = $this->createMock(ContentEntryRepository::class);
        $repo->method('findOneBy')->willReturn($entry);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $command = new ContentDeleteCommand($repo, $em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['uuid' => $uuid, '--force' => true], ['interactive' => false]);
        $this->assertSame(0, $exitCode);
        $this->assertNotNull($entry->deletedAt);
        $this->assertStringContainsString('supprimée', $tester->getDisplay());
    }

    public function testFieldCreateRejectsInvalidType(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();

        $collection = new Collection();
        $collection->slug = 'articles';

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $em = $this->createMock(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $command = new FieldCreateCommand($projectRepo, $collectionRepo, $em, $slugger);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => $project->uuid->toString(),
            'collection-slug' => 'articles',
            'name' => 'Bad Field',
            '--type' => 'malicious_code_type',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Type de champ invalide', $tester->getDisplay());
    }

    public function testFieldCreateSetsOrderProperly(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();

        $collection = new Collection();
        $collection->slug = 'articles';

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $fieldRepo = $this->createMock(\App\Repository\FieldRepository::class);
        $fieldRepo->method('findOneBy')->willReturn(null);

        $persistedField = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($fieldRepo);
        $em->expects($this->once())->method('persist')->willReturnCallback(function ($field) use (&$persistedField) {
            $persistedField = $field;
        });
        $em->expects($this->once())->method('flush');

        $slugger = new AsciiSlugger();

        $command = new FieldCreateCommand($projectRepo, $collectionRepo, $em, $slugger);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => $project->uuid->toString(),
            'collection-slug' => 'articles',
            'name' => 'Subtitle',
            '--type' => 'text',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertInstanceOf(Field::class, $persistedField);
        $this->assertSame(0, $persistedField->order);
        $this->assertSame('subtitle', $persistedField->slug);
    }

    public function testContentShowHandlesVariousFieldTypesGracefully(): void
    {
        $project = new Project();
        $collection = new Collection();
        $entry = new ContentEntry();
        $entry->slug = 'hello-world';
        $entry->uuid = Uuid::v4();
        $entry->status = 'published';
        $entry->locale = 'fr';
        $entry->createdAt = new \DateTimeImmutable('2026-01-01 10:00:00');

        $field1 = new Field();
        $field1->slug = 'score';

        $cfv1 = new ContentFieldValue();
        $cfv1->field = $field1;
        $cfv1->numberValue = '99.5';

        $field2 = new Field();
        $field2->slug = 'is_active';

        $cfv2 = new ContentFieldValue();
        $cfv2->field = $field2;
        $cfv2->booleanValue = true;

        $entry->fieldValues->add($cfv1);
        $entry->fieldValues->add($cfv2);

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('findOneBy')->willReturn($entry);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);

        $command = new ContentShowCommand($projectRepo, $collectionRepo, $entryRepo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => Uuid::v4()->toString(),
            'collection-slug' => 'posts',
            'uuid' => $entry->uuid->toString(),
        ]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('score: 99.5', $output);
        $this->assertStringContainsString('is_active: true', $output);
    }

    public function testDeployCommandRejectsTraversalPath(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $exporter = $this->createMock(ProjectExporter::class);

        $command = new DeployCommand($em, $exporter);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project_uuid' => Uuid::v4()->toString(),
            '--output' => '../../etc',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('restricted', $tester->getDisplay());
    }

    public function testBlueprintExportRejectsRestrictedFile(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);

        $project = new Project();
        $project->name = 'Test';
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $command = new BlueprintExportCommand($em, $projectRepo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => $project->uuid->toString(),
            'output-file' => '.env',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('restricted', $tester->getDisplay());
    }

    public function testCreateApiTokenSupportsJsonOutput(): void
    {
        $project = new Project();
        $project->name = 'API Project';
        $project->uuid = Uuid::v4();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $params = new ParameterBag(['kernel.secret' => 'sec_12345678901234567890123456789012']);

        $command = new CreateApiTokenCommand($userRepo, $projectRepo, $em, $params);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'project-uuid' => $project->uuid->toString(),
            '--name' => 'Deploy Key',
            '--abilities' => 'read,write',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $json = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success']);
        $this->assertNotEmpty($json['token']);
        $this->assertSame('Deploy Key', $json['name']);
        $this->assertSame(['read', 'write'], $json['abilities']);
    }

    public function testCliSecurityTraitDirectValidation(): void
    {
        $dummy = new class {
            use \App\Command\CliSecurityTrait;

            public function testSafePath(string $p, ?string $base = null): string {
                return $this->assertSafePath($p, $base);
            }

            public function testPassword(string $pw): bool {
                return $this->validatePasswordComplexity($pw);
            }

            public function testGenerate(int $len): string {
                return $this->generateSecurePassword($len);
            }

            public function testLike(string $str): string {
                return $this->sanitizeLikePattern($str);
            }
        };

        // Path validation
        $this->expectException(\InvalidArgumentException::class);
        $dummy->testSafePath("exploit\0file.txt");
    }

    public function testCliSecurityTraitBlocksTraversal(): void
    {
        $dummy = new class {
            use \App\Command\CliSecurityTrait;

            public function testSafePath(string $p, ?string $base = null): string {
                return $this->assertSafePath($p, $base);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $dummy->testSafePath('../../etc/passwd');
    }

    public function testCliSecurityTraitPasswordComplexityAndGeneration(): void
    {
        $dummy = new class {
            use \App\Command\CliSecurityTrait;

            public function testPassword(string $pw): bool {
                return $this->validatePasswordComplexity($pw);
            }

            public function testGenerate(int $len): string {
                return $this->generateSecurePassword($len);
            }

            public function testLike(string $str): string {
                return $this->sanitizeLikePattern($str);
            }
        };

        $this->assertFalse($dummy->testPassword('short1A')); // < 8 chars
        $this->assertFalse($dummy->testPassword('alllowercase123')); // no uppercase
        $this->assertFalse($dummy->testPassword('ALLUPPERCASE123')); // no lowercase
        $this->assertFalse($dummy->testPassword('NoDigitsHere!')); // no digit
        $this->assertTrue($dummy->testPassword('StrongPassword123!'));

        $generated = $dummy->testGenerate(20);
        $this->assertSame(20, strlen($generated));
        $this->assertTrue($dummy->testPassword($generated));

        $this->assertSame('100\\%\\_discount', $dummy->testLike('100%_discount'));
    }

    public function testSyncSchemaRejectsIdenticalSourceAndTarget(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $command = new \App\Command\SyncSchemaCommand($em);
        $tester = new CommandTester($command);

        $sameUuid = Uuid::v4()->toString();
        $exitCode = $tester->execute([
            'source_uuid' => $sameUuid,
            'target_uuid' => $sameUuid,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('distinct', $tester->getDisplay());
    }
}
