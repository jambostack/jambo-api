<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\SeoController;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\Seo\SeoAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class SeoControllerHardeningTest extends TestCase
{
    private ProjectRepository $projectRepo;
    private ContentEntryRepository $entryRepo;
    private CollectionRepository $collectionRepo;
    private EntityManagerInterface $em;
    private SeoAnalyzer $analyzer;
    private AuthorizationCheckerInterface $authChecker;

    protected function setUp(): void
    {
        $this->projectRepo = $this->createMock(ProjectRepository::class);
        $this->entryRepo = $this->createMock(ContentEntryRepository::class);
        $this->collectionRepo = $this->createMock(CollectionRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->analyzer = new SeoAnalyzer();
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);
    }

    private function createController(): SeoController
    {
        $controller = new SeoController(
            $this->analyzer,
            $this->entryRepo,
            $this->projectRepo,
            $this->collectionRepo,
            $this->em,
        );

        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('security.authorization_checker', $this->authChecker);
        $controller->setContainer($container);

        return $controller;
    }

    public function testBulkUpdateRequiresContentUpdatePermission(): void
    {
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $project = new Project();

        $this->projectRepo->method('findOneBy')->willReturn($project);
        $this->authChecker->method('isGranted')
            ->with('content.update', $project)
            ->willReturn(false);

        $controller = $this->createController();

        $request = new Request([], [], [], [], [], [], json_encode(['entries' => []]));

        $this->expectException(AccessDeniedException::class);
        $controller->bulkUpdate($projectUuid, $request);
    }

    public function testBulkUpdateRejectsExcessiveEntries(): void
    {
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $project = new Project();

        $this->projectRepo->method('findOneBy')->willReturn($project);
        $this->authChecker->method('isGranted')->willReturn(true);

        $controller = $this->createController();

        $entries = [];
        for ($i = 0; $i < 101; $i++) {
            $entries[] = ['uuid' => 'bafb2021-079e-44b0-8fe9-35af563dc2b2'];
        }

        $request = new Request([], [], [], [], [], [], json_encode(['entries' => $entries]));

        $response = $controller->bulkUpdate($projectUuid, $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testBulkUpdateRejectsInvalidCanonicalUrl(): void
    {
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $entryUuid = 'a1b2c3d4-e5f6-4a1b-8c2d-3e4f5a6b7c8d';
        $project = new Project();

        $entry = new ContentEntry();
        $entry->project = $project;

        $this->projectRepo->method('findOneBy')->willReturn($project);
        $this->entryRepo->method('findOneBy')->willReturn($entry);
        $this->authChecker->method('isGranted')->willReturn(true);

        $controller = $this->createController();

        $request = new Request([], [], [], [], [], [], json_encode([
            'entries' => [
                [
                    'uuid' => $entryUuid,
                    'canonicalUrl' => "javascript:alert('xss')",
                ],
            ],
        ]));

        $response = $controller->bulkUpdate($projectUuid, $request);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testBulkUpdateAppliesSanitizedMetadata(): void
    {
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $entryUuid = 'a1b2c3d4-e5f6-4a1b-8c2d-3e4f5a6b7c8d';
        $project = new Project();

        $entry = new ContentEntry();
        $entry->project = $project;

        $this->projectRepo->method('findOneBy')->willReturn($project);
        $this->entryRepo->method('findOneBy')->willReturn($entry);
        $this->authChecker->method('isGranted')->willReturn(true);

        $controller = $this->createController();

        $request = new Request([], [], [], [], [], [], json_encode([
            'entries' => [
                [
                    'uuid' => $entryUuid,
                    'metaTitle' => '<b>Bold Title</b>',
                    'metaDescription' => 'Safe Description',
                    'slug' => 'clean-slug-2026!@#',
                    'canonicalUrl' => 'https://example.com/canonical-url',
                ],
            ],
        ]));

        $response = $controller->bulkUpdate($projectUuid, $request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame(1, $data['updated']);

        $this->assertSame('Bold Title', $entry->metaTitle);
        $this->assertSame('Safe Description', $entry->metaDescription);
        $this->assertSame('clean-slug-2026', $entry->slug);
        $this->assertSame('https://example.com/canonical-url', $entry->canonicalUrl);
    }
}
