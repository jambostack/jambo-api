<?php

declare(strict_types=1);

namespace App\Tests\GraphQL;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Field;
use App\Entity\Media;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class GraphQLMutationRbacAndIdorSecurityTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private Project $projectA;
    private Project $projectB;
    private Collection $collectionA;
    private Collection $collectionB;
    private ContentEntry $entryA;
    private ContentEntry $entryB;
    private Media $mediaB;

    private string $writeTokenSecret;
    private string $createTokenSecret;
    private string $updateTokenSecret;
    private string $deleteTokenSecret;
    private string $readOnlyTokenSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // ── Projet A ────────────────────────────────────────────────────────
        $this->projectA = new Project();
        $this->projectA->name = 'Tenant A ' . bin2hex(random_bytes(4));
        $this->em->persist($this->projectA);

        $this->collectionA = new Collection();
        $this->collectionA->project = $this->projectA;
        $this->collectionA->name = 'Articles';
        $this->collectionA->slug = 'articles';
        $this->collectionA->order = 0;
        $this->em->persist($this->collectionA);

        $fieldTitle = new Field();
        $fieldTitle->collection = $this->collectionA;
        $fieldTitle->name = 'Title';
        $fieldTitle->slug = 'title';
        $fieldTitle->type = 'text';
        $fieldTitle->order = 0;
        $this->collectionA->fields->add($fieldTitle);
        $this->em->persist($fieldTitle);

        $fieldAuthor = new Field();
        $fieldAuthor->collection = $this->collectionA;
        $fieldAuthor->name = 'Related Article';
        $fieldAuthor->slug = 'related_article';
        $fieldAuthor->type = 'relation';
        $fieldAuthor->order = 1;
        $this->collectionA->fields->add($fieldAuthor);
        $this->em->persist($fieldAuthor);

        $fieldMedia = new Field();
        $fieldMedia->collection = $this->collectionA;
        $fieldMedia->name = 'Cover Image';
        $fieldMedia->slug = 'cover_image';
        $fieldMedia->type = 'media';
        $fieldMedia->order = 2;
        $this->collectionA->fields->add($fieldMedia);
        $this->em->persist($fieldMedia);

        $this->entryA = new ContentEntry();
        $this->entryA->collection = $this->collectionA;
        $this->entryA->project = $this->projectA;
        $this->entryA->locale = 'en';
        $this->entryA->slug = 'entry-a-' . bin2hex(random_bytes(4));
        $this->entryA->status = 'published';
        $this->em->persist($this->entryA);

        // ── Projet B (Autre Tenant) ──────────────────────────────────────────
        $this->projectB = new Project();
        $this->projectB->name = 'Tenant B ' . bin2hex(random_bytes(4));
        $this->em->persist($this->projectB);

        $this->collectionB = new Collection();
        $this->collectionB->project = $this->projectB;
        $this->collectionB->name = 'Articles B';
        $this->collectionB->slug = 'articles_b';
        $this->collectionB->order = 0;
        $this->em->persist($this->collectionB);

        $this->entryB = new ContentEntry();
        $this->entryB->collection = $this->collectionB;
        $this->entryB->project = $this->projectB;
        $this->entryB->locale = 'en';
        $this->entryB->slug = 'entry-b-' . bin2hex(random_bytes(4));
        $this->entryB->status = 'published';
        $this->em->persist($this->entryB);

        $this->mediaB = new Media();
        $this->mediaB->uuid = Uuid::v4();
        $this->mediaB->project = $this->projectB;
        $this->mediaB->createdAt = new \DateTimeImmutable();
        $this->em->persist($this->mediaB);

        // ── Tokens pour Projet A ─────────────────────────────────────────────
        $this->writeTokenSecret = bin2hex(random_bytes(24));
        $writeToken = new ApiToken();
        $writeToken->project = $this->projectA;
        $writeToken->name = 'WriteToken';
        $writeToken->tokenHash = hash('sha256', $this->writeTokenSecret);
        $writeToken->abilities = ['read', 'write'];
        $this->em->persist($writeToken);

        $this->createTokenSecret = bin2hex(random_bytes(24));
        $createToken = new ApiToken();
        $createToken->project = $this->projectA;
        $createToken->name = 'CreateOnlyToken';
        $createToken->tokenHash = hash('sha256', $this->createTokenSecret);
        $createToken->abilities = ['create'];
        $this->em->persist($createToken);

        $this->updateTokenSecret = bin2hex(random_bytes(24));
        $updateToken = new ApiToken();
        $updateToken->project = $this->projectA;
        $updateToken->name = 'UpdateOnlyToken';
        $updateToken->tokenHash = hash('sha256', $this->updateTokenSecret);
        $updateToken->abilities = ['update'];
        $this->em->persist($updateToken);

        $this->deleteTokenSecret = bin2hex(random_bytes(24));
        $deleteToken = new ApiToken();
        $deleteToken->project = $this->projectA;
        $deleteToken->name = 'DeleteOnlyToken';
        $deleteToken->tokenHash = hash('sha256', $this->deleteTokenSecret);
        $deleteToken->abilities = ['delete'];
        $this->em->persist($deleteToken);

        $this->readOnlyTokenSecret = bin2hex(random_bytes(24));
        $readToken = new ApiToken();
        $readToken->project = $this->projectA;
        $readToken->name = 'ReadOnlyToken';
        $readToken->tokenHash = hash('sha256', $this->readOnlyTokenSecret);
        $readToken->abilities = ['read'];
        $this->em->persist($readToken);

        $this->em->flush();
    }

    public function testTranslationParityForCycle127Keys(): void
    {
        $keys = [
            'graphql.mutation_create_permission_denied',
            'graphql.mutation_update_permission_denied',
            'graphql.mutation_delete_permission_denied',
            'graphql.cross_project_relation_forbidden',
            'graphql.cross_project_media_forbidden',
            'graphql.field_required',
        ];

        $counts = [];
        foreach (self::LOCALES as $locale) {
            $path = __DIR__ . "/../../translations/messages.{$locale}.php";
            $this->assertFileExists($path);
            $dict = require $path;
            $counts[$locale] = count($dict);

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale {$locale} must have key {$key}");
                $this->assertNotEmpty($dict[$key], "Locale {$locale} key {$key} must not be empty");
            }
        }

        // All 4 locales have perfectly identical key counts
        $this->assertSame($counts['en'], $counts['fr']);
        $this->assertSame($counts['en'], $counts['es']);
        $this->assertSame($counts['en'], $counts['ar']);
    }

    public function testCreateOnlyTokenCanCreateButNotUpdateOrDelete(): void
    {
        $projectUuid = $this->projectA->uuid->toString();

        // 1. Create mutation is ALLOWED
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createTokenSecret,
            ],
            content: json_encode([
                'query' => 'mutation { createArticles(input: { title: "Allowed Created Title" }) { uuid title } }',
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertSame('Allowed Created Title', $data['data']['createArticles']['title']);

        // 2. Update mutation is FORBIDDEN (403)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf('mutation { updateArticles(uuid: "%s", input: { title: "Hacked" }) { uuid } }', $this->entryA->uuid->toString()),
            ])
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $err = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $err);

        // 3. Delete mutation is FORBIDDEN (403)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf('mutation { deleteArticles(uuid: "%s") }', $this->entryA->uuid->toString()),
            ])
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $err = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $err);
    }

    public function testUpdateOnlyTokenCanUpdateButNotCreateOrDelete(): void
    {
        $projectUuid = $this->projectA->uuid->toString();

        // 1. Create is FORBIDDEN (403)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->updateTokenSecret,
            ],
            content: json_encode([
                'query' => 'mutation { createArticles(input: { title: "Denied" }) { uuid } }',
            ])
        );
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // 2. Update is ALLOWED (200)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->updateTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf('mutation { updateArticles(uuid: "%s", input: { title: "Updated By Token" }) { uuid title } }', $this->entryA->uuid->toString()),
            ])
        );
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertSame('Updated By Token', $data['data']['updateArticles']['title']);

        // 3. Delete is FORBIDDEN (403)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->updateTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf('mutation { deleteArticles(uuid: "%s") }', $this->entryA->uuid->toString()),
            ])
        );
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteOnlyTokenCanDeleteButNotCreateOrUpdate(): void
    {
        $projectUuid = $this->projectA->uuid->toString();

        // 1. Create is FORBIDDEN (403)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->deleteTokenSecret,
            ],
            content: json_encode([
                'query' => 'mutation { createArticles(input: { title: "Denied" }) { uuid } }',
            ])
        );
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // 2. Update is FORBIDDEN (403)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->deleteTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf('mutation { updateArticles(uuid: "%s", input: { title: "Denied" }) { uuid } }', $this->entryA->uuid->toString()),
            ])
        );
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // 3. Delete is ALLOWED (200)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->deleteTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf('mutation { deleteArticles(uuid: "%s") }', $this->entryA->uuid->toString()),
            ])
        );
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertTrue($data['data']['deleteArticles']);
    }

    public function testCrossProjectMediaReferencingBlockedWithUserError(): void
    {
        $projectUuid = $this->projectA->uuid->toString();
        $mediaBUuid = $this->mediaB->uuid->toString();

        // Attempt to create an article in Project A pointing to Project B's media
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf(
                    'mutation { createArticles(input: { title: "Article with B Media", cover_image: "%s" }) { uuid cover_image } }',
                    $mediaBUuid
                ),
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertNull($data['data']['createArticles']);
        $this->assertTrue(
            str_contains(strtolower($data['errors'][0]['message']), 'média') ||
            str_contains(strtolower($data['errors'][0]['message']), 'media')
        );
    }

    public function testCrossProjectRelationReferencingBlockedWithUserError(): void
    {
        $projectUuid = $this->projectA->uuid->toString();
        $entryBUuid = $this->entryB->uuid->toString();

        // Attempt to create an article in Project A referencing an entry from Project B
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf(
                    'mutation { createArticles(input: { title: "Article with B Rel", related_article: "%s" }) { uuid related_article } }',
                    $entryBUuid
                ),
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertNull($data['data']['createArticles']);
        $this->assertTrue(
            str_contains(strtolower($data['errors'][0]['message']), 'relation') ||
            str_contains(strtolower($data['errors'][0]['message']), 'projet') ||
            str_contains(strtolower($data['errors'][0]['message']), 'project')
        );
    }

    public function testCrossProjectEntryIdorUpdateAndDeletionReturnsNullOrFalse(): void
    {
        $projectUuid = $this->projectA->uuid->toString();
        $entryBUuid = $this->entryB->uuid->toString();

        // Attempt to update Entry B via Project A endpoint
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf(
                    'mutation { updateArticles(uuid: "%s", input: { title: "Cross Tenant Attack" }) { uuid title } }',
                    $entryBUuid
                ),
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['data']['updateArticles']);

        // Attempt to delete Entry B via Project A endpoint
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => sprintf(
                    'mutation { deleteArticles(uuid: "%s") }',
                    $entryBUuid
                ),
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['data']['deleteArticles']);
    }

    public function testRequiredFieldValidationEnforcedInCreateMutation(): void
    {
        // Add a required field to collection A
        $reqField = new Field();
        $reqField->collection = $this->collectionA;
        $reqField->name = 'Mandatory Code';
        $reqField->slug = 'mandatory_code';
        $reqField->type = 'text';
        $reqField->isRequired = true;
        $reqField->order = 3;
        $this->collectionA->fields->add($reqField);
        $this->em->persist($reqField);
        $this->em->flush();

        // Invalidate schema cache for project A
        $schemaGen = self::getContainer()->get(\App\GraphQL\SchemaGenerator::class);
        $schemaGen->invalidateCache($this->projectA);

        $projectUuid = $this->projectA->uuid->toString();

        // Submit creation without mandatory_code
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => 'mutation { createArticles(input: { title: "No Code" }) { uuid } }',
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertNull($data['data']['createArticles']);
        $this->assertTrue(
            str_contains(strtolower($data['errors'][0]['message']), 'requis') ||
            str_contains(strtolower($data['errors'][0]['message']), 'required') ||
            str_contains(strtolower($data['errors'][0]['message']), 'mandatory')
        );
    }
}
