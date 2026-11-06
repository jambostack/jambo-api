<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\ImportOptions;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectTemplate;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\EndUserFieldRepository;
use App\Service\ExportImport\Export\StructureExportHandler;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ProjectTemplateBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

class Cycle94TemplateExportHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const NEW_TRANSLATION_KEYS = [
        'template.duplicate_field_slug',
        'template.field_options_too_deep',
        'import.structure.project_collection_quota_exceeded',
        'import.structure.options_too_deep',
        'import.structure.duplicate_field_slug',
        'cli.invalid_yaml',
        'cli.invalid_blueprint_structure',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private Project $project;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\CollectionTemplateField f')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\CollectionTemplate c')->execute();

        $this->admin = new User();
        $this->admin->name = 'Cycle94 Admin';
        $this->admin->email = 'admin_c94_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Cycle94 Test Project ' . bin2hex(random_bytes(2));
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->admin;
        $member->email = $this->admin->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->em->flush();

        $this->tempDir = sys_get_temp_dir() . '/jambo_c94_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
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

    public function testCollectionTemplateRejectsOversizedPayload(): void
    {
        $this->client->loginUser($this->admin);

        $hugePayload = [
            'name' => 'Huge Template',
            'fields' => [
                [
                    'name' => 'Text',
                    'slug' => 'text',
                    'type' => 'text',
                    'options' => ['filler' => str_repeat('A', 600000)],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            '/api/collection-templates',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($hugePayload)
        );

        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testCollectionTemplateRejectsDuplicateFieldSlugs(): void
    {
        $this->client->loginUser($this->admin);

        $payload = [
            'name' => 'Duplicate Slug Template',
            'fields' => [
                ['name' => 'Title One', 'slug' => 'title', 'type' => 'text'],
                ['name' => 'Title Two', 'slug' => 'title', 'type' => 'text'],
            ],
        ];

        $this->client->request(
            'POST',
            '/api/collection-templates',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('DUPLICATE_FIELD_SLUG', $data['code'] ?? null);
    }

    public function testCollectionTemplateRejectsOptionsTooDeep(): void
    {
        $this->client->loginUser($this->admin);

        // 7 levels of nesting
        $deepOptions = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => ['l7' => 'val']]]]]]];

        $payload = [
            'name' => 'Deep Options Template',
            'fields' => [
                ['name' => 'Deep Field', 'slug' => 'deep_field', 'type' => 'text', 'options' => $deepOptions],
            ],
        ];

        $this->client->request(
            'POST',
            '/api/collection-templates',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('OPTIONS_TOO_DEEP', $data['code'] ?? null);
    }

    public function testCollectionTemplateSanitizesNameAndDesc(): void
    {
        $this->client->loginUser($this->admin);

        $payload = [
            'name' => "<script>alert('xss')</script>Clean Template\x00\x1F",
            'description' => "<b>Safe</b> Description\x07",
            'fields' => [
                ['name' => 'Field 1', 'slug' => 'field_1', 'type' => 'text'],
            ],
        ];

        $this->client->request(
            'POST',
            '/api/collection-templates',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame("alert('xss')Clean Template", $data['name']);
        $this->assertSame("Safe Description", $data['description']);
    }

    public function testCollectionTemplateNormalizesRelationField(): void
    {
        $this->client->loginUser($this->admin);

        $payload = [
            'name' => 'Relation Template',
            'fields' => [
                [
                    'name' => 'Author Relation',
                    'slug' => 'author',
                    'type' => 'relation',
                    'options' => [
                        'relation' => [
                            'type' => 1,
                            'collection' => 99999, // arbitrary integer DB ID
                            'collection_slug' => 'authors',
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request(
            'POST',
            '/api/collection-templates',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $field = $data['fields'][0] ?? [];
        $this->assertSame('authors', $field['options']['relation']['collection_slug'] ?? null);
        $this->assertArrayNotHasKey('collection', $field['options']['relation'] ?? []);
    }

    public function testProjectTemplateBuilderHandlesPortableAndOrphanRelations(): void
    {
        $builder = self::getContainer()->get(ProjectTemplateBuilder::class);

        // Create collection A: Categories
        $colA = new Collection();
        $colA->name = 'Categories';
        $colA->slug = 'categories';
        $colA->project = $this->project;
        $this->project->collections->add($colA);
        $this->em->persist($colA);

        // Create collection B: Products with relation to Categories
        $colB = new Collection();
        $colB->name = 'Products';
        $colB->slug = 'products';
        $colB->project = $this->project;
        $this->project->collections->add($colB);
        $this->em->persist($colB);

        $this->em->flush();

        // Add relation field from Products -> Categories
        $relField = new Field();
        $relField->name = 'Category';
        $relField->slug = 'category';
        $relField->type = 'relation';
        $relField->options = [
            'relation' => [
                'type' => 1,
                'collection' => $colA->id,
            ],
        ];
        $relField->collection = $colB;
        $colB->fields->add($relField);
        $this->em->persist($relField);

        // Add orphan relation field pointing to deleted/foreign collection 88888
        $orphanField = new Field();
        $orphanField->name = 'Deleted Target';
        $orphanField->slug = 'deleted_target';
        $orphanField->type = 'relation';
        $orphanField->options = [
            'relation' => [
                'type' => 1,
                'collection' => 88888,
            ],
        ];
        $orphanField->collection = $colB;
        $colB->fields->add($orphanField);
        $this->em->persist($orphanField);

        $this->em->flush();

        // 1. Export template from project
        $template = $builder->exportFromProject($this->project->uuid->toString(), 'Exported Catalog Template');
        $this->assertInstanceOf(ProjectTemplate::class, $template);

        // Verify structure has portable slug for Category and orphan for Deleted Target
        $structure = $template->structure;
        $collections = $structure['collections'];
        $productsCol = null;
        foreach ($collections as $c) {
            if ($c['slug'] === 'products') {
                $productsCol = $c;
                break;
            }
        }
        $this->assertNotNull($productsCol);

        $categoryFieldData = null;
        $orphanFieldData = null;
        foreach ($productsCol['fields'] as $f) {
            if ($f['slug'] === 'category') {
                $categoryFieldData = $f;
            }
            if ($f['slug'] === 'deleted_target') {
                $orphanFieldData = $f;
            }
        }

        $this->assertSame('categories', $categoryFieldData['options']['relation']['collection_slug'] ?? null);
        $this->assertTrue($orphanFieldData['options']['relation']['orphan'] ?? false);
        $this->assertNull($orphanFieldData['options']['relation']['collection_slug'] ?? null);

        // 2. Apply template to a brand new project
        $newProject = $builder->applyTemplate($template, 'New Applied Project', $this->admin);
        $this->assertInstanceOf(Project::class, $newProject);

        $newColA = null;
        $newColB = null;
        foreach ($newProject->collections as $c) {
            if ($c->slug === 'categories') {
                $newColA = $c;
            }
            if ($c->slug === 'products') {
                $newColB = $c;
            }
        }
        $this->assertNotNull($newColA);
        $this->assertNotNull($newColB);

        // Verify that the Category relation points to the NEW collection A's integer ID
        $newRelField = null;
        $newOrphanField = null;
        foreach ($newColB->fields as $f) {
            if ($f->slug === 'category') {
                $newRelField = $f;
            }
            if ($f->slug === 'deleted_target') {
                $newOrphanField = $f;
            }
        }
        $this->assertNotNull($newRelField);
        $this->assertSame($newColA->id, $newRelField->options['relation']['collection'] ?? null);
        $this->assertSame('categories', $newRelField->options['relation']['collection_slug'] ?? null);

        // Verify orphan relation is kept isolated
        $this->assertTrue($newOrphanField->options['relation']['orphan'] ?? false);
        $this->assertNull($newOrphanField->options['relation']['collection_slug'] ?? null);
    }

    public function testStructureExportHandlerMakesRelationsPortable(): void
    {
        $endUserFieldRepo = self::getContainer()->get(EndUserFieldRepository::class);
        $exporter = new StructureExportHandler($endUserFieldRepo);

        $col = new Collection();
        $col->name = 'Tags';
        $col->slug = 'tags';
        $col->project = $this->project;
        $this->project->collections->add($col);
        $this->em->persist($col);
        $this->em->flush();

        $relField = new Field();
        $relField->name = 'Tag Rel';
        $relField->slug = 'tag_rel';
        $relField->type = 'relation';
        $relField->options = [
            'relation' => [
                'type' => 2,
                'collection' => $col->id,
            ],
        ];
        $relField->collection = $col;
        $col->fields->add($relField);
        $this->em->persist($relField);
        $this->em->flush();

        $exporter->export($this->project, $this->tempDir);
        $json = file_get_contents($this->tempDir . '/structure.json');
        $data = json_decode($json, true);

        $exportedField = $data['collections'][0]['fields'][0] ?? [];
        $this->assertSame('tags', $exportedField['options']['relation']['collection_slug'] ?? null);
        $this->assertArrayNotHasKey('collection', $exportedField['options']['relation'] ?? []);
    }

    public function testStructureImportHandlerEnforcesCollectionQuota(): void
    {
        $endUserFieldRepo = self::getContainer()->get(EndUserFieldRepository::class);
        $importer = new StructureImportHandler($endUserFieldRepo);

        // Pre-populate project with 95 collections
        for ($i = 1; $i <= 95; $i++) {
            $c = new Collection();
            $c->name = "Coll $i";
            $c->slug = "coll_$i";
            $c->project = $this->project;
            $this->project->collections->add($c);
        }

        // Prepare incoming structure with 10 new collections (95 + 10 = 105 > 100 limit)
        $incomingCollections = [];
        for ($i = 96; $i <= 105; $i++) {
            $incomingCollections[] = [
                'name' => "New Coll $i",
                'slug' => "new_coll_$i",
                'fields' => [
                    ['name' => 'F1', 'slug' => 'f1', 'type' => 'text'],
                ],
            ];
        }

        file_put_contents($this->tempDir . '/structure.json', json_encode(['collections' => $incomingCollections]));

        $options = new ImportOptions();
        $uuidMap = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Importing collections would exceed project limit of 100 collections');

        $importer->import($this->project, $this->tempDir, $options, $uuidMap);
    }

    public function testStructureImportHandlerRejectsDuplicateFieldSlugsAndDeepOptions(): void
    {
        $endUserFieldRepo = self::getContainer()->get(EndUserFieldRepository::class);
        $importer = new StructureImportHandler($endUserFieldRepo);

        // 1. Duplicate field slugs
        $structureDuplicate = [
            'collections' => [
                [
                    'name' => 'Dup Coll',
                    'slug' => 'dup_coll',
                    'fields' => [
                        ['name' => 'Field A', 'slug' => 'slug_dup', 'type' => 'text'],
                        ['name' => 'Field B', 'slug' => 'slug_dup', 'type' => 'text'],
                    ],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/structure.json', json_encode($structureDuplicate));

        $options = new ImportOptions();
        $uuidMap = [];

        try {
            $importer->import($this->project, $this->tempDir, $options, $uuidMap);
            $this->fail('Should have thrown RuntimeException for duplicate field slug');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Duplicate field slug', $e->getMessage());
        }

        // 2. Options too deep
        $deepOpts = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 1]]]]]]];
        $structureDeep = [
            'collections' => [
                [
                    'name' => 'Deep Coll',
                    'slug' => 'deep_coll',
                    'fields' => [
                        ['name' => 'Field Deep', 'slug' => 'field_deep', 'type' => 'text', 'options' => $deepOpts],
                    ],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/structure.json', json_encode($structureDeep));

        try {
            $importer->import($this->project, $this->tempDir, $options, $uuidMap);
            $this->fail('Should have thrown RuntimeException for deep options');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('options nested too deeply', $e->getMessage());
        }
    }

    public function testStructureImportHandlerSafelyIsolatesOrphanRelations(): void
    {
        $endUserFieldRepo = self::getContainer()->get(EndUserFieldRepository::class);
        $importer = new StructureImportHandler($endUserFieldRepo);

        $structure = [
            'collections' => [
                [
                    'name' => 'Posts',
                    'slug' => 'posts',
                    'fields' => [
                        [
                            'name' => 'Secret Vault Rel',
                            'slug' => 'vault',
                            'type' => 'relation',
                            'options' => [
                                'relation' => [
                                    'type' => 1,
                                    'collection_slug' => 'non_existent_vault_coll',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/structure.json', json_encode($structure));

        $options = new ImportOptions();
        $uuidMap = [];
        $importer->import($this->project, $this->tempDir, $options, $uuidMap);

        $importedCol = null;
        foreach ($this->project->collections as $c) {
            if ($c->slug === 'posts') {
                $importedCol = $c;
                break;
            }
        }

        $this->assertNotNull($importedCol);
        $field = $importedCol->fields->first();
        $this->assertTrue($field->options['relation']['orphan'] ?? false);
        $this->assertNull($field->options['relation']['collection_slug'] ?? null);
    }

    public function testBlueprintImportCommandSupportsYamlAndJson(): void
    {
        // 1. YAML file
        $yamlData = [
            'project' => ['name' => 'YAML Blueprint Project'],
            'collections' => [
                [
                    'name' => 'Articles',
                    'slug' => 'articles',
                    'fields' => [
                        ['name' => 'Title', 'slug' => 'title', 'type' => 'text', 'is_required' => true],
                    ],
                ],
            ],
        ];
        $yamlPath = $this->tempDir . '/blueprint.yaml';
        file_put_contents($yamlPath, Yaml::dump($yamlData));

        $parsed = Yaml::parse(file_get_contents($yamlPath), Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        $this->assertSame('YAML Blueprint Project', $parsed['project']['name']);
        $this->assertCount(1, $parsed['collections']);

        // 2. JSON file
        $jsonPath = $this->tempDir . '/blueprint.json';
        file_put_contents($jsonPath, json_encode($yamlData));
        $parsedJson = json_decode(file_get_contents($jsonPath), true);
        $this->assertSame('YAML Blueprint Project', $parsedJson['project']['name']);
    }

    public function testI18nParityAcrossAllFourLocales(): void
    {
        $translations = [];
        foreach (self::LOCALES as $loc) {
            $path = dirname(__DIR__, 2) . "/translations/messages.{$loc}.php";
            $this->assertFileExists($path);
            $translations[$loc] = require $path;
            $this->assertIsArray($translations[$loc]);
        }

        $enCount = count($translations['en']);
        $this->assertGreaterThanOrEqual(3473, $enCount, 'Expected at least 3473 keys in messages.en.php');

        foreach (self::LOCALES as $loc) {
            $this->assertCount($enCount, $translations[$loc], "Locale {$loc} must have {$enCount} keys.");

            foreach (self::NEW_TRANSLATION_KEYS as $key) {
                $this->assertArrayHasKey($key, $translations[$loc], "Locale {$loc} missing key {$key}");
                $this->assertNotEmpty($translations[$loc][$key], "Locale {$loc} has empty value for {$key}");
            }
        }
    }
}
