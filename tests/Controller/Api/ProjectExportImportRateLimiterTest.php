<?php

namespace App\Tests\Controller\Api;

use App\Controller\Api\ProjectExportImportController;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\EndUserSchemaSeeder;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class ProjectExportImportRateLimiterTest extends TestCase
{
    private function createFactory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    public function testExportRejectsWhenRateLimitExceeded(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->id = 1;
        $project->name = 'Test Project';
        $projectRepo->method('findOneBy')->willReturn($project);

        $exporter = $this->createMock(ProjectExporter::class);
        $exporter->method('streamExport')->willReturn(tempnam(sys_get_temp_dir(), 'exp'));

        $importer = $this->createMock(ProjectImporter::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $seeder = $this->createMock(EndUserSchemaSeeder::class);

        // Limit of 1 request
        $exportLimiter = $this->createFactory('export_test', 1);

        $controller = new ProjectExportImportController(
            $projectRepo,
            $exporter,
            $importer,
            $em,
            $seeder,
            $exportLimiter
        );
        $controller->setContainer(new Container());

        $req = new Request([], [], ['_project' => $project]);

        // First call accepted (streamExport returned file)
        $res1 = $controller->export('some-uuid', $req);
        $this->assertSame(200, $res1->getStatusCode());

        // Second call throttled
        $res2 = $controller->export('some-uuid', $req);
        $this->assertSame(429, $res2->getStatusCode());
        $data = json_decode($res2->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Too many export requests', $data['error']);
    }

    public function testImportMergeRejectsWhenRateLimitExceeded(): void
    {
        $project = new Project();
        $project->id = 1;
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $exporter = $this->createMock(ProjectExporter::class);
        $importer = $this->createMock(ProjectImporter::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $seeder = $this->createMock(EndUserSchemaSeeder::class);

        // Limit of 1 request
        $importLimiter = $this->createFactory('import_test', 1);

        $controller = new ProjectExportImportController(
            $projectRepo,
            $exporter,
            $importer,
            $em,
            $seeder,
            null,
            $importLimiter
        );
        $controller->setContainer(new Container());

        $req = new Request([], [], ['_project' => $project]);

        // First call consumes the token
        $controller->importMerge('some-uuid', $req);

        // Second call throttled
        $res2 = $controller->importMerge('some-uuid', $req);
        $this->assertSame(429, $res2->getStatusCode());
        $data = json_decode($res2->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Too many import requests', $data['error']);
    }
}
