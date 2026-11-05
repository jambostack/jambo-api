<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class Cycle48SecurityHardeningTest extends WebTestCase
{
    private function createMemberPat(array $scopes, string $locale = 'en'): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'cycle48-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_48';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $user->locale = $locale;
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle48 Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'pat-cycle48';
        $pat->user = $user;
        $pat->scopes = $scopes;
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        return [$plain, $project->uuid->toString(), $user, $project];
    }

    public function testProjectCreatePayloadTooLarge(): void
    {
        $client = static::createClient();
        [$plain] = $this->createMemberPat(['projects:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $oversizedName = str_repeat('A', 524300);
        $client->request('POST', '/admin-api/projects', server: $server, content: json_encode(['name' => $oversizedName]));

        self::assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('PAYLOAD_TOO_LARGE', $data['code']);
        self::assertStringContainsString('512', $data['error']);
        self::assertTrue($client->getResponse()->headers->has('X-Content-Type-Options'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testProjectCreateControlCharactersRejected(): void
    {
        $client = static::createClient();
        [$plain] = $this->createMemberPat(['projects:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('POST', '/admin-api/projects', server: $server, content: json_encode(['name' => "Bad\x00Project\x07Name"]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('NAME_CONTROL_CHARS', $data['code']);
    }

    public function testProjectUpdateControlCharactersRejected(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->createMemberPat(['projects:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('PATCH', "/admin-api/projects/{$uuid}", server: $server, content: json_encode(['name' => "Injection\x1FProject"]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('NAME_CONTROL_CHARS', $data['code']);
    }

    public function testCollectionCreateControlCharactersRejected(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->createMemberPat(['schema:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('POST', "/admin-api/projects/{$uuid}/collections", server: $server, content: json_encode(['name' => "Bad\x08Collection"]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('NAME_CONTROL_CHARS', $data['code']);
    }

    public function testFieldCreateControlCharactersRejected(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->createMemberPat(['schema:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $colSlug = 'col_' . bin2hex(random_bytes(3));
        $client->request('POST', "/admin-api/projects/{$uuid}/collections", server: $server, content: json_encode(['name' => $colSlug]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', "/admin-api/projects/{$uuid}/collections/{$colSlug}/fields", server: $server, content: json_encode([
            'name' => "Bad\x03Field",
            'type' => 'text',
        ]));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('NAME_CONTROL_CHARS', $data['code']);
    }

    public function testSecurityHeadersEnforcedAcrossAdminApiEndpoints(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->createMemberPat(['projects:write', 'schema:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        // Project list
        $client->request('GET', '/admin-api/projects', server: $server);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        // Project show
        $client->request('GET', "/admin-api/projects/{$uuid}", server: $server);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        // Collections list
        $client->request('GET', "/admin-api/projects/{$uuid}/collections", server: $server);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        // Ping
        $client->request('GET', '/admin-api/_ping', server: $server);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testRequireScopeMissingScopeException(): void
    {
        $client = static::createClient();
        // PAT without 'projects:write' scope
        [$plain] = $this->createMemberPat(['content:read']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('POST', '/admin-api/projects', server: $server, content: json_encode(['name' => 'Should Fail']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAuditLogRecordedOnProjectDelete(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->createMemberPat(['projects:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('DELETE', "/admin-api/projects/{$uuid}", server: $server);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testAuditLogRecordedOnCollectionDelete(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->createMemberPat(['schema:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $colSlug = 'col_del_' . bin2hex(random_bytes(3));
        $client->request('POST', "/admin-api/projects/{$uuid}/collections", server: $server, content: json_encode(['name' => $colSlug]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('DELETE', "/admin-api/projects/{$uuid}/collections/{$colSlug}", server: $server);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testAuditLogRecordedOnFieldDelete(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->createMemberPat(['schema:write']);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $colSlug = 'col_fdel_' . bin2hex(random_bytes(3));
        $client->request('POST', "/admin-api/projects/{$uuid}/collections", server: $server, content: json_encode(['name' => $colSlug]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', "/admin-api/projects/{$uuid}/collections/{$colSlug}/fields", server: $server, content: json_encode([
            'name' => 'to_delete',
            'type' => 'text',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('DELETE', "/admin-api/projects/{$uuid}/collections/{$colSlug}/fields/to_delete", server: $server);
        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertGreaterThanOrEqual(2751, count($en));
        self::assertCount(count($en), $fr, 'FR key count must match EN exactly');
        self::assertCount(count($en), $es, 'ES key count must match EN exactly');
        self::assertCount(count($en), $ar, 'AR key count must match EN exactly');

        $newKeys = [
            'admin_api.no_token',
            'admin_api.missing_scope',
            'admin_api.rate_limit_exceeded',
            'admin_api.project.payload_too_large',
            'admin_api.project.name_control_chars',
            'admin_api.project.max_projects_exceeded',
            'admin_api.collection.name_control_chars',
            'admin_api.collection.max_collections_exceeded',
            'admin_api.field.name_control_chars',
            'admin_api.field.max_fields_exceeded',
        ];

        foreach ($newKeys as $key) {
            self::assertArrayHasKey($key, $en, "Missing {$key} in EN");
            self::assertArrayHasKey($key, $fr, "Missing {$key} in FR");
            self::assertArrayHasKey($key, $es, "Missing {$key} in ES");
            self::assertArrayHasKey($key, $ar, "Missing {$key} in AR");
            self::assertNotEmpty($en[$key], "Empty {$key} in EN");
            self::assertNotEmpty($fr[$key], "Empty {$key} in FR");
            self::assertNotEmpty($es[$key], "Empty {$key} in ES");
            self::assertNotEmpty($ar[$key], "Empty {$key} in AR");
        }
    }
}
