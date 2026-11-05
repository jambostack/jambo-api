<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class Cycle53SecurityHardeningTest extends WebTestCase
{
    private function createUserAndProject(string $role = 'ROLE_SUPER_ADMIN', bool $publicApi = false): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'cycle53-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_53';
        $user->roles = [$role];
        $user->locale = 'en';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle53 Project ' . bin2hex(random_bytes(4));
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

        self::assertIsArray($en);
        self::assertIsArray($fr);
        self::assertIsArray($es);
        self::assertIsArray($ar);

        $enKeys = array_keys($en);
        $frKeys = array_keys($fr);
        $esKeys = array_keys($es);
        $arKeys = array_keys($ar);

        sort($enKeys);
        sort($frKeys);
        sort($esKeys);
        sort($arKeys);

        $diffFr = array_diff($enKeys, $frKeys);
        $diffEs = array_diff($enKeys, $esKeys);
        $diffAr = array_diff($enKeys, $arKeys);

        self::assertEmpty($diffFr, 'FR keys differ from EN: ' . implode(', ', array_slice($diffFr, 0, 10)));
        self::assertEmpty($diffEs, 'ES keys differ from EN: ' . implode(', ', array_slice($diffEs, 0, 10)));
        self::assertEmpty($diffAr, 'AR keys differ from EN: ' . implode(', ', array_slice($diffAr, 0, 10)));

        $extraFr = array_diff($frKeys, $enKeys);
        $extraEs = array_diff($esKeys, $enKeys);
        $extraAr = array_diff($arKeys, $enKeys);

        self::assertEmpty($extraFr, 'FR has extra keys: ' . implode(', ', array_slice($extraFr, 0, 10)));
        self::assertEmpty($extraEs, 'ES has extra keys: ' . implode(', ', array_slice($extraEs, 0, 10)));
        self::assertEmpty($extraAr, 'AR has extra keys: ' . implode(', ', array_slice($extraAr, 0, 10)));

        // Verify newly added key
        self::assertArrayHasKey('graphql.mercure_not_configured', $en);
        self::assertArrayHasKey('graphql.mercure_not_configured', $fr);
        self::assertArrayHasKey('graphql.mercure_not_configured', $es);
        self::assertArrayHasKey('graphql.mercure_not_configured', $ar);
    }

    public function testHealthControllerVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(HealthController::VERSION, $data['version']);
        self::assertGreaterThanOrEqual('1.26.112', $data['version']);
    }

    public function testAiControllerSecurityHeadersAndValidation(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // Invalid JSON body
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/ai/generate',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'invalid-json'
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testColumnSettingControllerSecurityHeaders(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // Not found collection
        $client->request('GET', '/api/projects/' . $project->uuid . '/collections/nonexistent-slug/column-settings');
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testSearchControllerSecurityHeadersAndAccess(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // Missing query parameter 'q'
        $client->request('GET', '/api/projects/' . $project->uuid . '/search');
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testMcpControllerSecurityHeaders(): void
    {
        $client = static::createClient();

        // Empty body rejection
        $client->request(
            'POST',
            '/mcp',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            ''
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testGraphQLSecurityHeaders(): void
    {
        $client = static::createClient();
        [$user, $project] = $this->createUserAndProject();
        $client->loginUser($user);

        // Missing query
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/graphql',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => ''])
        );

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
