<?php

namespace App\Tests\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SchemaCrudTest extends WebTestCase
{
    /** @return array{string,string} [plainToken, projectUuid] — user membre du projet */
    private function setupMemberPat(array $scopes): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'm-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Schema ' . bin2hex(random_bytes(4));
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
        $pat->name = 't';
        $pat->user = $user;
        $pat->scopes = $scopes;
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        return [$plain, $project->uuid->toString()];
    }

    public function testCreateCollectionThenField(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->setupMemberPat(['schema:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => 'Poles']));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', "/admin-api/projects/$uuid/collections/poles/fields", server: $h, content: json_encode(['name' => 'Titre', 'type' => 'text']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('titre', $body['data']['slug']);
    }

    /** Ajoute un second PAT (scopes donnés) au même user/projet qu'un setup existant. */
    private function addPatForUser(User $user, array $scopes): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'extra';
        $pat->user = $user;
        $pat->scopes = $scopes;
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        return $plain;
    }

    public function testReadSchemaWithGet(): void
    {
        $client = static::createClient();
        [$writePlain, $uuid] = $this->setupMemberPat(['schema:write']);
        $wh = ['HTTP_AUTHORIZATION' => 'Bearer ' . $writePlain, 'CONTENT_TYPE' => 'application/json'];

        // Crée le schéma (écriture)
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $wh, content: json_encode(['name' => 'Articles']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $client->request('POST', "/admin-api/projects/$uuid/collections/articles/fields", server: $wh, content: json_encode(['name' => 'Titre', 'type' => 'text']));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // Jeton SANS scope appartenant au même membre : la lecture (GET) doit marcher.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $member = $em->getRepository(ProjectMember::class)->findOneBy([
            'project' => $em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]),
        ]);
        $readPlain = $this->addPatForUser($member->user, []);
        $rh = ['HTTP_AUTHORIZATION' => 'Bearer ' . $readPlain];

        // GET liste des collections
        $client->request('GET', "/admin-api/projects/$uuid/collections", server: $rh);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('articles', json_decode($client->getResponse()->getContent(), true)['data'][0]['slug']);

        // GET collection unique avec ses champs
        $client->request('GET', "/admin-api/projects/$uuid/collections/articles", server: $rh);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('titre', json_decode($client->getResponse()->getContent(), true)['data']['fields'][0]['slug']);

        // GET liste des champs
        $client->request('GET', "/admin-api/projects/$uuid/collections/articles/fields", server: $rh);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('text', json_decode($client->getResponse()->getContent(), true)['data'][0]['type']);

        // GET collection inexistante → 404
        $client->request('GET', "/admin-api/projects/$uuid/collections/nope", server: $rh);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testNonMemberGets403(): void
    {
        $client = static::createClient();
        [, $uuid] = $this->setupMemberPat(['schema:write']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $intruder = new User();
        $intruder->email = 'x-' . bin2hex(random_bytes(4)) . '@e.com';
        $intruder->password = 'x';
        $em->persist($intruder);
        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 't';
        $pat->user = $intruder;
        $pat->scopes = ['schema:write'];
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        $client->request('POST', "/admin-api/projects/$uuid/collections", server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['name' => 'Hack']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUnknownTypeReturns422(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->setupMemberPat(['schema:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => 'Stuff']));
        $client->request('POST', "/admin-api/projects/$uuid/collections/stuff/fields", server: $h, content: json_encode(['name' => 'Bad', 'type' => 'wormhole']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }
}
