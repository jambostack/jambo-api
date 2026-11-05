<?php

namespace App\Tests\Controller;

use App\Dto\ImportOptions;
use App\Entity\Collection;
use App\Entity\EndUser;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\User;
use App\Repository\EndUserFieldRepository;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectTemplateRepository;
use App\Service\AuditService;
use App\Service\EndUserSchemaSeeder;
use App\Service\ExportImport\Import\ContentImportHandler;
use App\Service\ExportImport\Import\EndUserImportHandler;
use App\Service\ExportImport\Import\MediaImportHandler;
use App\Service\ExportImport\Import\SettingsImportHandler;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ExportImport\ProjectImporter;
use App\Service\ProjectTemplateBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class Cycle59TemplateExportImportHardeningTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/jambo_c59_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }

    public function testProjectImporterRejectsDangerousExtensionsInZip(): void
    {
        $importer = new ProjectImporter(
            new StructureImportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentImportHandler(),
            new MediaImportHandler($this->tempDir),
            new SettingsImportHandler(),
            new EndUserImportHandler($this->createMock(EndUserRepository::class)),
            $this->tempDir,
        );

        $zipPath = $this->tempDir . '/malicious.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['version' => '1.0', 'included' => []]));
        $zip->addFromString('exploit.php', '<?php echo "evil"; ?>');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Executable or dangerous file extension forbidden in ZIP archive: exploit.php');

        $importer->extractZip($zipPath);
    }

    public function testProjectImporterRejectsHiddenFilesInZip(): void
    {
        $importer = new ProjectImporter(
            new StructureImportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentImportHandler(),
            new MediaImportHandler($this->tempDir),
            new SettingsImportHandler(),
            new EndUserImportHandler($this->createMock(EndUserRepository::class)),
            $this->tempDir,
        );

        $zipPath = $this->tempDir . '/hidden.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['version' => '1.0', 'included' => []]));
        $zip->addFromString('.git/config', '[core]');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hidden files or directories forbidden in ZIP archive: .git/config');

        $importer->extractZip($zipPath);
    }

    public function testMediaImportHandlerRejectsDoubleExtensionsAndPolyglotPhp(): void
    {
        $mediaDir = $this->tempDir . '/media';
        mkdir($mediaDir, 0777, true);

        // Create dangerous double-extension and polyglot files
        file_put_contents($mediaDir . '/avatar.php.jpg', '<?php phpinfo(); ?>');
        file_put_contents($mediaDir . '/safe.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF valid image data");

        $mediaJson = [
            'media' => [
                [
                    'uuid' => Uuid::v4()->toString(),
                    'file_name' => 'avatar.php.jpg',
                    'original_name' => 'avatar.php.jpg',
                    'mime_type' => 'image/jpeg',
                ],
                [
                    'uuid' => Uuid::v4()->toString(),
                    'file_name' => 'safe.jpg',
                    'original_name' => 'safe.jpg',
                    'mime_type' => 'image/jpeg',
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/media.json', json_encode($mediaJson));

        $handler = new MediaImportHandler($this->tempDir);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $options = new ImportOptions();
        $options->createNewProject = true;
        $uuidMap = [];

        $handler->import($project, $this->tempDir, $options, $uuidMap);

        $uploadDir = $this->tempDir . '/public/uploads/media/' . $project->uuid->toString();
        // The double extension file avatar.php.jpg MUST NOT be copied
        $this->assertFileDoesNotExist($uploadDir . '/avatar.php.jpg');
        // The safe file safe.jpg MUST be copied
        $this->assertFileExists($uploadDir . '/safe.jpg');
    }

    public function testMediaImportHandlerRejectsOversizedMediaList(): void
    {
        $mediaList = [];
        for ($i = 0; $i < 2005; $i++) {
            $mediaList[] = ['file_name' => "file_$i.png", 'original_name' => "file_$i.png"];
        }
        file_put_contents($this->tempDir . '/media.json', json_encode(['media' => $mediaList]));

        $handler = new MediaImportHandler($this->tempDir);
        $project = new Project();
        $options = new ImportOptions();
        $uuidMap = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('media.json contains too many files (max 2000).');

        $handler->import($project, $this->tempDir, $options, $uuidMap);
    }

    public function testStructureImportHandlerHardensEndUserFieldsAndOversizedOptions(): void
    {
        $structure = [
            'collections' => [
                [
                    'name' => "Articles\x00\x08",
                    'slug' => 'articles',
                    'fields' => [
                        [
                            'name' => "Title\x0B",
                            'slug' => 'title',
                            'type' => 'text',
                            'options' => ['huge' => str_repeat('A', 40000)], // > 32KB
                        ],
                    ],
                ],
            ],
            'end_user_fields' => [
                [
                    'name' => "Custom Age\x07",
                    'slug' => 'custom_age',
                    'type' => 'number',
                ],
                [
                    'name' => 'Invalid Type Field',
                    'slug' => 'invalid_field',
                    'type' => 'unsupported_dangerous_type',
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/structure.json', json_encode($structure));

        $endUserFieldRepo = $this->createMock(EndUserFieldRepository::class);
        $endUserFieldRepo->method('findByProject')->willReturn([]);

        $handler = new StructureImportHandler($endUserFieldRepo);
        $project = new Project();
        $options = new ImportOptions();
        $options->createNewProject = true;
        $uuidMap = [];

        $handler->import($project, $this->tempDir, $options, $uuidMap);

        $col = $project->collections->first();
        $this->assertSame('Articles', $col->name);

        $field = $col->fields->first();
        $this->assertSame('Title', $field->name);
        $this->assertNull($field->options, 'Oversized options payload (>32KB) must be neutralized to null');

        $importedEndUserFields = $handler->getImportedEndUserFields();
        $this->assertCount(2, $importedEndUserFields);
        $this->assertSame('Custom Age', $importedEndUserFields[0]->name);
        $this->assertSame('number', $importedEndUserFields[0]->type);
        // Unsupported type falls back to safe 'text'
        $this->assertSame('text', $importedEndUserFields[1]->type);
    }

    public function testEndUserImportHandlerRejectsOversizedUserCount(): void
    {
        $users = [];
        for ($i = 0; $i < 5005; $i++) {
            $users[] = ['email' => "user$i@example.com", 'password' => '$2y$10$hash'];
        }
        file_put_contents($this->tempDir . '/end_users.json', json_encode(['end_users' => $users]));

        $repo = $this->createMock(EndUserRepository::class);
        $handler = new EndUserImportHandler($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('end_users.json contains too many users (max 5000).');

        $project = new Project();
        $options = new ImportOptions();
        $uuidMap = [];
        $handler->import($project, $this->tempDir, $options, $uuidMap);
    }

    public function testContentImportHandlerSafelyHandlesMalformedDatesAndOversizedText(): void
    {
        $content = [
            'entries' => [
                [
                    'collection_slug' => 'articles',
                    'uuid' => Uuid::v4()->toString(),
                    'locale' => 'fr-FR',
                    'status' => 'published',
                    'field_values' => [
                        [
                            'field_slug' => 'publish_date',
                            'field_type' => 'datetime',
                            'value' => 'invalid-date-string-that-would-throw',
                        ],
                        [
                            'field_slug' => 'body',
                            'field_type' => 'text',
                            'value' => str_repeat('X', 80000), // > 65536
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/content.json', json_encode($content));

        $project = new Project();
        $col = new Collection();
        $col->slug = 'articles';
        $project->collections->add($col);

        $fDate = new Field();
        $fDate->slug = 'publish_date';
        $fDate->type = 'datetime';
        $col->fields->add($fDate);

        $fBody = new Field();
        $fBody->slug = 'body';
        $fBody->type = 'text';
        $col->fields->add($fBody);

        $handler = new ContentImportHandler();
        $options = new ImportOptions();
        $uuidMap = [];

        // Must not throw \Exception when encountering invalid date string
        $handler->import($project, $this->tempDir, $options, $uuidMap);

        $entry = $col->contentEntries->first();
        $this->assertNotNull($entry);
        $this->assertSame('fr-FR', $entry->locale);
        $this->assertSame('published', $entry->status);

        $fvs = $entry->fieldValues->toArray();
        $this->assertCount(2, $fvs);
        // Date field value should be null instead of crashing
        $this->assertNull($fvs[0]->datetimeValue);
        // Text field value should be capped at 65536 chars
        $this->assertSame(65536, strlen($fvs[1]->textValue));
    }

    public function testProjectTemplateBuilderSanitizesInputAndSeedsEndUserSchema(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $templateRepo = $this->createMock(ProjectTemplateRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $seeder = $this->createMock(EndUserSchemaSeeder::class);

        // Verify that EndUserSchemaSeeder::seed() is called when applying a template
        $seeder->expects($this->once())->method('seed')->with($this->isInstanceOf(Project::class));

        $builder = new ProjectTemplateBuilder($projectRepo, $templateRepo, $em, $seeder);

        $template = new ProjectTemplate();
        $template->name = 'Starter SaaS';
        $template->structure = [
            'defaultLocale' => 'en',
            'locales' => ['en', 'fr'],
            'collections' => [
                [
                    'name' => "Posts\x00\x08",
                    'slug' => 'posts_slug',
                    'fields' => [
                        [
                            'name' => "Title\x02",
                            'slug' => 'title',
                            'type' => 'text',
                        ],
                        [
                            'name' => 'Bad Type',
                            'slug' => 'bad_type',
                            'type' => 'exploit_code',
                        ],
                    ],
                ],
            ],
        ];

        $owner = new User();
        $owner->email = 'admin@jambostack.site';

        $project = $builder->applyTemplate($template, "My Custom Project\x0B", $owner);

        $this->assertSame('My Custom Project', $project->name);
        $this->assertSame('en', $project->defaultLocale);
    }
}
