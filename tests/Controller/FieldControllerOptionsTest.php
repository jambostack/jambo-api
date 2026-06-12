<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les options relation écrites via l'API fields sont normalisées au format
 * canonique à la persistance, et enrichies (collection_slug) à la lecture.
 */
class FieldControllerOptionsTest extends WebTestCase
{
    private string $projectUuid;
    private string $plainToken;
    private string $articlesSlug;
    private int $articlesId;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'FieldOpts ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $pages = new Collection();
        $pages->project = $project;
        $pages->name = 'Pages';
        $pages->slug = 'pages';
        $pages->order = 0;
        $em->persist($pages);

        $articles = new Collection();
        $articles->project = $project;
        $articles->name = 'Articles';
        $articles->slug = 'articles';
        $articles->order = 1;
        $em->persist($articles);

        $plain = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = 'fieldopts';
        $token->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $token->tokenVersion = 2;
        $token->abilities = ['write'];
        $token->project = $project;
        $em->persist($token);

        $em->flush();

        $this->projectUuid = $project->uuid->toString();
        $this->plainToken = $plain;
        $this->articlesSlug = $articles->slug;
        $this->articlesId = $articles->id;

        self::ensureKernelShutdown();
    }

    public function testRelationOptionsAreNormalizedOnCreateAndEnrichedOnRead(): void
    {
        $client = static::createClient();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken];
        $base = '/api/projects/' . $this->projectUuid . '/collections/pages/fields';

        // Création avec un format legacy SchemaBuilder
        $client->jsonRequest('POST', $base, [
            'name' => 'Article lié',
            'slug' => 'article_lie',
            'type' => 'relation',
            'options' => ['targetCollection' => $this->articlesSlug, 'relationType' => 2],
        ], $auth);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // Lecture : format canonique + collection_slug dérivé
        $client->jsonRequest('GET', $base . '/article_lie', [], $auth);
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $opts = json_decode($client->getResponse()->getContent(), true)['data']['options'];

        $this->assertSame($this->articlesId, $opts['relation']['collection'] ?? null);
        $this->assertSame(2, $opts['relation']['type'] ?? null);
        $this->assertSame($this->articlesSlug, $opts['relation']['collection_slug'] ?? null);
        $this->assertArrayNotHasKey('targetCollection', $opts);
        $this->assertArrayNotHasKey('relationType', $opts);
    }
}
