<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\CollectionTemplate;
use App\Entity\CollectionTemplateField;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectTemplate;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class Cycle94SchemaImportExportHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const NEW_TRANSLATION_KEYS = [
        'template.import_success',
        'template.export_success',
        'template.invalid_blueprint_format',
        'template.invalid_yaml',
        'template.field_type_unsupported',
        'template.project_collection_limit_exceeded',
        'template.collection_applied_success',
        'template.collection_name_required',
        'template.collections_required',
        'template.too_many_collections',
        'template.target_project_not_found',
        'template.collection_already_exists',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->name = 'Cycle94 Admin';
        $this->admin->email = 'admin_c94_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->member = new User();
        $this->member->name = 'Cycle94 Member';
        $this->member->email = 'member_c94_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->member->password = $hasher->hashPassword($this->member, 'secure123');
        $this->member->roles = ['ROLE_USER'];
        $this->em->persist($this->member);

        $this->project = new Project();
        $this->project->name = 'Cycle94 Test Project';
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->em->persist($this->project);

        $membershipAdmin = new ProjectMember();
        $membershipAdmin->project = $this->project;
        $membershipAdmin->user = $this->admin;
        $membershipAdmin->email = $this->admin->email;
        $membershipAdmin->status = ProjectMemberStatus::Active;
        $membershipAdmin->joinedAt = new \DateTimeImmutable();
        $this->em->persist($membershipAdmin);

        $membershipUser = new ProjectMember();
        $membershipUser->project = $this->project;
        $membershipUser->user = $this->member;
        $membershipUser->email = $this->member->email;
        $membershipUser->status = ProjectMemberStatus::Active;
        $membershipUser->joinedAt = new \DateTimeImmutable();
        $this->em->persist($membershipUser);

        $this->em->flush();
    }

    public function testI18nParityAcrossAllLocales(): void
    {
        $basePath = self::getContainer()->getParameter('kernel.project_dir') . '/translations';
        $keysByLocale = [];

        foreach (self::LOCALES as $locale) {
            $file = sprintf('%s/messages.%s.php', $basePath, $locale);
            $this->assertFileExists($file, sprintf('Translation file missing for %s', $locale));
            $data = require $file;
            $this->assertIsArray($data, sprintf('Translation file for %s must return an array', $locale));
            $keysByLocale[$locale] = array_keys($data);

            foreach (self::NEW_TRANSLATION_KEYS as $key) {
                $this->assertArrayHasKey($key, $data, sprintf('Locale %s is missing key: %s', $locale, $key));
                $this->assertNotEmpty($data[$key], sprintf('Locale %s key %s must not be empty', $locale, $key));
            }
        }

        $enKeys = $keysByLocale['en'];
        foreach (['fr', 'es', 'ar'] as $targetLocale) {
            $diff1 = array_diff($enKeys, $keysByLocale[$targetLocale]);
            $diff2 = array_diff($keysByLocale[$targetLocale], $enKeys);
            $this->assertEmpty($diff1, sprintf('Keys present in en but missing in %s: %s', $targetLocale, implode(', ', $diff1)));
            $this->assertEmpty($diff2, sprintf('Keys present in %s but missing in en: %s', $targetLocale, implode(', ', $diff2)));
        }
    }

    public function testProjectTemplateExportJson(): void
    {
        $template = new ProjectTemplate();
        $template->name = 'Test Export Template';
        $template->description = 'A test template for export';
        $template->structure = [
            'defaultLocale' => 'en',
            'locales'       => ['en', 'fr'],
            'collections'   => [
                [
                    'name' => 'Articles',
                    'slug' => 'articles',
                    'fields' => [
                        ['name' => 'Title', 'slug' => 'title', 'type' => 'text', 'isRequired' => true],
                    ],
                ],
            ],
        ];
        $this->em->persist($template);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/project-templates/' . $template->id . '/export');

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('project_template', $data['type']);
        $this->assertSame('Test Export Template', $data['name']);
        $this->assertArrayHasKey('structure', $data);
    }

    public function testProjectTemplateImportJson(): void
    {
        $this->client->loginUser($this->admin);

        $payload = [
            'name'        => 'Imported Blog Blueprint',
            'description' => 'Clean blog structure with aliased fields',
            'structure'   => [
                'defaultLocale' => 'en',
                'locales'       => ['en', 'es'],
                'collections'   => [
                    [
                        'name'        => 'Posts',
                        'slug'        => 'posts',
                        'description' => 'Blog posts',
                        'fields'      => [
                            ['name' => 'Title', 'slug' => 'title', 'type' => 'text', 'isRequired' => true],
                            ['name' => 'Body', 'slug' => 'body', 'type' => 'textarea'], // alias to longtext
                            ['name' => 'Category', 'slug' => 'category', 'type' => 'select'], // alias to enumeration
                            [
                                'name'    => 'Author',
                                'slug'    => 'author_rel',
                                'type'    => 'relation',
                                'options' => [
                                    'relation' => ['type' => 1, 'collection_slug' => 'users_unknown'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request('POST', '/api/project-templates/import', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('Imported Blog Blueprint', $data['name']);

        $saved = $this->em->getRepository(ProjectTemplate::class)->find($data['id']);
        $this->assertNotNull($saved);
        $collections = $saved->structure['collections'];
        $this->assertCount(1, $collections);
        $fields = $collections[0]['fields'];
        $this->assertCount(4, $fields);

        // Verify type normalization
        $this->assertSame('longtext', $fields[1]['type']);
        $this->assertSame('enumeration', $fields[2]['type']);

        // Verify orphan relation marking
        $this->assertSame('relation', $fields[3]['type']);
        $this->assertTrue($fields[3]['options']['relation']['orphan']);
    }

    public function testProjectTemplateImportYaml(): void
    {
        $this->client->loginUser($this->admin);

        $yaml = <<<YAML
name: "YAML Blueprint Project"
description: "Imported via YAML string"
collections:
  - name: "Products"
    slug: "products"
    fields:
      - name: "Title"
        slug: "title"
        type: "text"
        is_required: true
      - name: "Price"
        slug: "price"
        type: "decimal"
      - name: "Details"
        slug: "details"
        type: "rich_text"
YAML;

        $this->client->request('POST', '/api/project-templates/import', [], [], ['CONTENT_TYPE' => 'application/x-yaml'], $yaml);

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('id', $data);

        $saved = $this->em->getRepository(ProjectTemplate::class)->find($data['id']);
        $this->assertNotNull($saved);
        $this->assertSame('YAML Blueprint Project', $saved->name);
        $fields = $saved->structure['collections'][0]['fields'];
        $this->assertSame('richtext', $fields[2]['type']); // rich_text normalized to richtext
    }

    public function testProjectTemplateImportRejectsExcessiveDepth(): void
    {
        $this->client->loginUser($this->admin);

        $payload = [
            'name' => 'Deep Options Project',
            'collections' => [
                [
                    'name' => 'Deep Coll',
                    'slug' => 'deep_coll',
                    'fields' => [
                        [
                            'name' => 'Deep Field',
                            'slug' => 'deep_field',
                            'type' => 'text',
                            'options' => [
                                'level1' => [
                                    'level2' => [
                                        'level3' => [
                                            'level4' => [
                                                'level5' => [
                                                    'level6' => [
                                                        'level7' => 'too_deep',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->client->request('POST', '/api/project-templates/import', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('OPTIONS_TOO_DEEP', $data['code']);
    }

    public function testCollectionTemplateExportJson(): void
    {
        $template = new CollectionTemplate();
        $template->name = 'Test Export Coll Template';
        $template->description = 'A collection template';
        $template->isSingleton = false;

        $field = new CollectionTemplateField();
        $field->name = 'Headline';
        $field->slug = 'headline';
        $field->type = 'text';
        $field->order = 0;
        $field->isRequired = true;
        $field->collectionTemplate = $template;
        $template->fields->add($field);

        $this->em->persist($template);
        $this->em->persist($field);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/collection-templates/' . $template->id . '/export');

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('collection_template', $data['type']);
        $this->assertSame('Test Export Coll Template', $data['name']);
        $this->assertCount(1, $data['fields']);
    }

    public function testCollectionTemplateImportJson(): void
    {
        $this->client->loginUser($this->admin);

        $payload = [
            'name'        => 'Custom Testimonials Template',
            'description' => 'Customer feedback and reviews',
            'fields'      => [
                ['name' => 'Author Name', 'slug' => 'author_name', 'type' => 'text', 'is_required' => true],
                ['name' => 'Comment', 'slug' => 'comment', 'type' => 'textarea'], // alias to longtext
                ['name' => 'Rating', 'slug' => 'rating', 'type' => 'rating'],
            ],
        ];

        $this->client->request('POST', '/api/collection-templates/import', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('id', $data);

        $saved = $this->em->getRepository(CollectionTemplate::class)->find($data['id']);
        $this->assertNotNull($saved);
        $this->assertCount(3, $saved->fields);
    }

    public function testCollectionTemplateApplyToProject(): void
    {
        $template = new CollectionTemplate();
        $template->name = 'Features List';
        $template->description = 'Product feature items';
        $template->isSingleton = false;

        $f1 = new CollectionTemplateField();
        $f1->name = 'Feature Name';
        $f1->slug = 'feature_name';
        $f1->type = 'text';
        $f1->collectionTemplate = $template;
        $template->fields->add($f1);

        $f2 = new CollectionTemplateField();
        $f2->name = 'Enabled';
        $f2->slug = 'is_enabled';
        $f2->type = 'boolean';
        $f2->collectionTemplate = $template;
        $template->fields->add($f2);

        $this->em->persist($template);
        $this->em->persist($f1);
        $this->em->persist($f2);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request('POST', '/api/collection-templates/' . $template->id . '/apply/' . $projectUuid, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'App Features',
            'slug' => 'app_features',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('App Features', $data['name']);
        $this->assertSame('app_features', $data['slug']);
        $this->assertSame(2, $data['fieldsCount']);

        // Verify collection and fields are in the database attached to project
        $createdColl = $this->em->getRepository(Collection::class)->findOneBy(['project' => $this->project, 'slug' => 'app_features']);
        $this->assertNotNull($createdColl);
        $this->assertCount(2, $createdColl->fields);

        // Test applying again creates a deduplicated slug (slug collision handling)
        $this->client->request('POST', '/api/collection-templates/' . $template->id . '/apply/' . $projectUuid, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'slug' => 'app_features',
        ]));

        $response2 = $this->client->getResponse();
        $this->assertSame(201, $response2->getStatusCode());
        $data2 = json_decode((string) $response2->getContent(), true);
        $this->assertSame('app_features_1', $data2['slug']);
    }

    public function testCollectionTemplateApplyRejectsWhenProjectLimitReached(): void
    {
        $template = new CollectionTemplate();
        $template->name = 'Quota Test Template';
        $this->em->persist($template);

        // Fill project collections up to MAX_COLLECTIONS_PER_PROJECT (100)
        for ($i = 0; $i < 100; $i++) {
            $col = new Collection();
            $col->project = $this->project;
            $col->name = 'Collection ' . $i;
            $col->slug = 'col_limit_' . $i;
            $this->project->collections->add($col);
            $this->em->persist($col);
        }
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request('POST', '/api/collection-templates/' . $template->id . '/apply/' . $projectUuid);

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('PROJECT_COLLECTION_LIMIT_EXCEEDED', $data['code']);
    }
}
