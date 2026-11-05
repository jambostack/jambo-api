<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class Cycle51SecurityHardeningTest extends WebTestCase
{
    private function createUserAndProject(string $role = 'ROLE_SUPER_ADMIN', bool $publicApi = false): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'cycle51-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_51';
        $user->roles = [$role];
        $user->locale = 'en';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle51 Project ' . bin2hex(random_bytes(4));
        $project->publicApi = $publicApi;
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $em->flush();

        return [$user, $project];
    }

    public function testTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertGreaterThanOrEqual(2831, count($en));
        self::assertCount(count($en), $fr, 'FR key count must match EN exactly');
        self::assertCount(count($en), $es, 'ES key count must match EN exactly');
        self::assertCount(count($en), $ar, 'AR key count must match EN exactly');

        $newKeys = [
            'studio.fallback_error',
            'studio.ai_chat_failed',
            'studio.ai_chat_error_reply',
            'studio.tool_internal_error',
            'studio.failed_persist_entries',
            'api_docs.project_not_found',
            'api_docs.auth_required',
            'api_project.unauthorized',
            'api_project.insufficient_abilities',
            'api_project_info.not_found',
            'api_project_info.public_disabled',
            'api_collection.unauthorized',
            'api_collection.insufficient_abilities',
            'api_collection.not_found',
            'api_content.collection_not_found',
            'api_content.entry_not_found',
            'api_content.auth_required',
            'api_content.forbidden',
            'api_content.token_create_required',
            'api_content.token_write_required',
            'api_content.token_delete_required',
            'api_content.invalid_json',
            'api_content.scheduled_at_required',
            'api_content.invalid_scheduled_at',
            'api_content.project_not_found',
            'api_content.public_disabled',
            'admin_seo.project_not_found',
            'admin_seo.entry_not_found',
            'admin_seo.invalid_json',
            'page.invalid_json',
            'page.valid_email_required',
            'page.password_min_length',
            'page.email_already_registered',
            'page.invalid_status',
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

    public function testHealthControllerVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(HealthController::VERSION, $data['version']);
        self::assertSame('1.26.110', $data['version']);
    }

    public function testApiDocsSwaggerNotFoundAndAuthRequired(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs/00000000-0000-0000-0000-000000000000');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);

        // Project without public API and anonymous access
        [$user, $project] = $this->createUserAndProject('ROLE_USER', false);
        $client->request('GET', '/api/docs/' . $project->uuid->toString());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Authentication required', $data['error']);
    }

    public function testPublicApiProjectSchemaUnauthorized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public-api');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Unauthorized. Provide a valid Bearer token.', $data['error']);
    }

    public function testPublicApiProjectInfoPublicDisabledAndNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/00000000-0000-0000-0000-000000000000');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Project not found.', $data['error']);

        [$user, $project] = $this->createUserAndProject('ROLE_USER', false);
        $client->request('GET', '/api/' . $project->uuid->toString());
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Public API access is disabled for this project.', $data['error']);
    }

    public function testPublicApiCollectionsUnauthorized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/public-api/collections');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Unauthorized.', $data['error']);

        $client->request('GET', '/public-api/collections/sample-slug');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Unauthorized.', $data['error']);
    }

    public function testPublicApiContentPublicDisabledAndNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/00000000-0000-0000-0000-000000000000/articles');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Project not found.', $data['error']);

        [$user, $project] = $this->createUserAndProject('ROLE_USER', false);
        $client->request('GET', '/api/' . $project->uuid->toString() . '/articles');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Public API access is disabled for this project.', $data['error']);
    }

    public function testAdminSeoScoresNotFound(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        $client->request('GET', '/admin-api/00000000-0000-0000-0000-000000000000/seo/scores');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Project not found.', $data['error']);
    }
}
