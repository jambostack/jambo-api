<?php

namespace App\Tests\Service;

use App\Dto\ExportOptions;
use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Repository\EndUserFieldRepository;
use App\Repository\EndUserRepository;
use App\Service\ExportImport\Export\ContentExportHandler;
use App\Service\ExportImport\Export\EndUserExportHandler;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Export\StructureExportHandler;
use App\Service\ExportImport\Import\ContentImportHandler;
use App\Service\ExportImport\Import\EndUserImportHandler;
use App\Service\ExportImport\Import\MediaImportHandler;
use App\Service\ExportImport\Import\SettingsImportHandler;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle78ExportImportI18nTest extends TestCase
{
    private string $tempDir;
    private array $catalogs = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cycle78_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0777, true);

        $locales = ['en', 'fr', 'es', 'ar'];
        foreach ($locales as $loc) {
            $path = __DIR__ . '/../../translations/messages.' . $loc . '.php';
            $this->catalogs[$loc] = require $path;
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->removeDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function createMockTranslator(string $locale = 'fr'): TranslatorInterface
    {
        $catalog = $this->catalogs[$locale] ?? $this->catalogs['en'];
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $parameters = []) use ($catalog) {
            if (isset($catalog[$id])) {
                $text = $catalog[$id];
                foreach ($parameters as $k => $v) {
                    $text = str_replace($k, (string) $v, $text);
                }
                return $text;
            }
            return $id;
        });
        return $translator;
    }

    public function testTranslationsExistInAllFourLocales(): void
    {
        $keys = [
            'export.temp_dir_create_failed',
            'export.zip_create_failed',
            'import.zip.open_failed',
            'import.zip.too_many_files',
            'import.zip.symlinks_forbidden',
            'import.zip.traversal_detected',
            'import.zip.dangerous_extension',
            'import.zip.hidden_files_forbidden',
            'import.zip.excessive_compression_ratio',
            'import.zip.total_size_exceeded',
            'import.manifest.not_found',
            'import.manifest.too_large',
            'import.manifest.malformed',
            'import.payload.size_exceeded',
            'import.structure.collections_must_be_array',
            'import.structure.too_many_collections',
            'import.structure.collection_missing_slug',
            'import.structure.invalid_collection_slug',
            'import.structure.fields_must_be_array',
            'import.structure.too_many_fields',
            'import.structure.field_missing_slug',
            'import.structure.invalid_field_slug',
            'import.structure.invalid_field_type',
            'import.structure.too_many_end_user_fields',
            'import.content.too_many_entries',
            'import.media.too_many_files',
            'import.end_users.too_many_users',
        ];

        foreach (['en', 'fr', 'es', 'ar'] as $loc) {
            foreach ($keys as $k) {
                $this->assertArrayHasKey($k, $this->catalogs[$loc], "Key $k must exist in $loc catalog");
                $this->assertNotEmpty($this->catalogs[$loc][$k], "Key $k cannot be empty in $loc catalog");
            }
        }
    }

    public function testProjectImporterTranslatesZipErrors(): void
    {
        $translatorFr = $this->createMockTranslator('fr');

        $importer = new ProjectImporter(
            new StructureImportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentImportHandler(),
            new MediaImportHandler($this->tempDir),
            new SettingsImportHandler(),
            new EndUserImportHandler($this->createMock(EndUserRepository::class)),
            $this->tempDir,
            $translatorFr,
        );

        // 1. Open failed
        try {
            $importer->extractZip($this->tempDir . '/non_existent.zip');
            $this->fail('Expected RuntimeException on invalid zip file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Impossible d\'ouvrir le fichier ZIP', $e->getMessage());
        }

        // 2. Dangerous extension in zip
        $zipPath = $this->tempDir . '/dangerous.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['version' => '1.0', 'included' => []]));
        $zip->addFromString('shell.php', '<?php echo 1;');
        $zip->close();

        try {
            $importer->extractZip($zipPath);
            $this->fail('Expected RuntimeException on dangerous extension');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Extension de fichier exécutable ou dangereuse interdite dans l\'archive ZIP : shell.php', $e->getMessage());
        }

        // 3. Traversal detected
        $zipPathTrav = $this->tempDir . '/traversal.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPathTrav, \ZipArchive::CREATE);
        $zip->addFromString('../secret.txt', 'secret');
        $zip->close();

        try {
            $importer->extractZip($zipPathTrav);
            $this->fail('Expected RuntimeException on directory traversal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Chemin invalide dans l\'archive ZIP (traversée de répertoire détectée)', $e->getMessage());
        }

        // 4. Hidden file detected
        $zipPathHidden = $this->tempDir . '/hidden.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPathHidden, \ZipArchive::CREATE);
        $zip->addFromString('.hidden/data.txt', 'secret');
        $zip->close();

        try {
            $importer->extractZip($zipPathHidden);
            $this->fail('Expected RuntimeException on hidden file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fichiers ou répertoires masqués interdits dans l\'archive ZIP', $e->getMessage());
        }
    }

    public function testProjectImporterTranslatesManifestAndPayloadErrors(): void
    {
        $translatorEs = $this->createMockTranslator('es');

        $importer = new ProjectImporter(
            new StructureImportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentImportHandler(),
            new MediaImportHandler($this->tempDir),
            new SettingsImportHandler(),
            new EndUserImportHandler($this->createMock(EndUserRepository::class)),
            $this->tempDir,
            $translatorEs,
        );

        // Missing manifest.json
        $extractDir = $this->tempDir . '/extracted';
        mkdir($extractDir, 0777, true);

        try {
            $importer->validateManifest($extractDir);
            $this->fail('Expected RuntimeException on missing manifest');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Paquete de exportación no válido: manifest.json no encontrado', $e->getMessage());
        }

        // Malformed manifest.json
        file_put_contents($extractDir . '/manifest.json', '{"invalid": true}');
        try {
            $importer->validateManifest($extractDir);
            $this->fail('Expected RuntimeException on malformed manifest');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Paquete de exportación no válido: manifest.json mal formado', $e->getMessage());
        }

        // Oversized payload
        file_put_contents($extractDir . '/manifest.json', json_encode(['version' => '1.0']));
        // structure.json max is 10MB; create a sparse large file or dummy payload > 10MB
        $largeFile = $extractDir . '/structure.json';
        $fp = fopen($largeFile, 'wb');
        fseek($fp, (11 * 1024 * 1024) - 1);
        fwrite($fp, "\0");
        fclose($fp);

        try {
            $importer->validateManifest($extractDir);
            $this->fail('Expected RuntimeException on oversized payload');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('El archivo de importación "structure.json" supera el límite de tamaño (10 MB).', $e->getMessage());
        }
    }

    public function testStructureImportHandlerTranslatesErrors(): void
    {
        $translatorAr = $this->createMockTranslator('ar');
        $repo = $this->createMock(EndUserFieldRepository::class);
        $handler = new StructureImportHandler($repo, $translatorAr);

        $extractDir = $this->tempDir . '/struct_test';
        mkdir($extractDir, 0777, true);

        // Collections not array
        file_put_contents($extractDir . '/structure.json', json_encode(['collections' => 'not_an_array']));
        $project = new Project();
        $options = new ImportOptions();
        $uuidMap = [];

        try {
            $handler->import($project, $extractDir, $options, $uuidMap);
            $this->fail('Expected RuntimeException on collections not array');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تنسيق structure.json غير صالح: يجب أن تكون المجموعات مصفوفة.', $e->getMessage());
        }

        // Collection missing slug
        file_put_contents($extractDir . '/structure.json', json_encode(['collections' => [['name' => 'No Slug']]]));
        try {
            $handler->import($project, $extractDir, $options, $uuidMap);
            $this->fail('Expected RuntimeException on missing slug');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('المجموعة تفتقر إلى المعرّف (slug) الصالح المطلوب.', $e->getMessage());
        }

        // Invalid field type
        file_put_contents($extractDir . '/structure.json', json_encode([
            'collections' => [
                [
                    'name' => 'Articles',
                    'slug' => 'articles',
                    'fields' => [
                        ['name' => 'Bad Field', 'slug' => 'bad_field', 'type' => 'dangerous_eval_type']
                    ]
                ]
            ]
        ]));
        try {
            $handler->import($project, $extractDir, $options, $uuidMap);
            $this->fail('Expected RuntimeException on invalid field type');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('نوع الحقل غير صالح "dangerous_eval_type" للحقل "bad_field" في المجموعة "articles".', $e->getMessage());
        }
    }

    public function testContentMediaAndEndUserHandlersTranslateErrors(): void
    {
        $translatorFr = $this->createMockTranslator('fr');

        // ContentImportHandler: too many entries
        $contentHandler = new ContentImportHandler($translatorFr);
        $extractDir = $this->tempDir . '/handlers_test';
        mkdir($extractDir, 0777, true);

        $dummyEntries = array_fill(0, 10005, ['collection_slug' => 'articles', 'uuid' => '123']);
        file_put_contents($extractDir . '/content.json', json_encode(['entries' => $dummyEntries]));

        $project = new Project();
        $options = new ImportOptions();
        $uuidMap = [];

        try {
            $contentHandler->import($project, $extractDir, $options, $uuidMap);
            $this->fail('Expected RuntimeException on too many content entries');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('content.json contient trop d\'entrées (max 10000).', $e->getMessage());
        }

        // MediaImportHandler: too many files
        $mediaHandler = new MediaImportHandler($this->tempDir, $translatorFr);
        $dummyMedia = array_fill(0, 2005, ['file_name' => 'image.jpg', 'uuid' => '123']);
        file_put_contents($extractDir . '/media.json', json_encode(['media' => $dummyMedia]));

        try {
            $mediaHandler->import($project, $extractDir, $options, $uuidMap);
            $this->fail('Expected RuntimeException on too many media files');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('media.json contient trop de fichiers (max 2000).', $e->getMessage());
        }

        // EndUserImportHandler: too many users
        $userRepo = $this->createMock(EndUserRepository::class);
        $endUserHandler = new EndUserImportHandler($userRepo, null, $translatorFr);
        $dummyUsers = array_fill(0, 5005, ['email' => 'user@example.com', 'password' => 'hash']);
        file_put_contents($extractDir . '/end_users.json', json_encode(['end_users' => $dummyUsers]));

        try {
            $endUserHandler->import($project, $extractDir, $options, $uuidMap);
            $this->fail('Expected RuntimeException on too many end users');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('end_users.json contient trop d\'utilisateurs (max 5000).', $e->getMessage());
        }
    }
}
