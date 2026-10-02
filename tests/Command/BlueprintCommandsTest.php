<?php

namespace App\Tests\Command;

use App\Command\BlueprintExportCommand;
use App\Command\BlueprintImportCommand;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class BlueprintCommandsTest extends TestCase
{
    public function testExportOutputsJson(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ProjectRepository::class);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test Project';

        $col = new Collection();
        $col->name = 'Articles';
        $col->slug = 'articles';

        $field = new Field();
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->isRequired = true;
        $field->order = 1;

        $repo->method('findOneBy')->willReturn($project);

        $colRepo = $this->createMock(EntityRepository::class);
        $colRepo->method('findBy')->willReturn([$col]);

        $fieldRepo = $this->createMock(EntityRepository::class);
        $fieldRepo->method('findBy')->willReturn([$field]);

        $em->method('getRepository')->willReturnCallback(function ($entityClass) use ($colRepo, $fieldRepo) {
            if ($entityClass === Collection::class) return $colRepo;
            if ($entityClass === Field::class) return $fieldRepo;
            return null;
        });

        $command = new BlueprintExportCommand($em, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['project-uuid' => $project->uuid->toRfc4122()]);
        $this->assertEquals(0, $exitCode);

        $output = $tester->getDisplay();
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertEquals('Test Project', $data['project']['name']);
        $this->assertEquals('Articles', $data['collections'][0]['name']);
        $this->assertEquals('Title', $data['collections'][0]['fields'][0]['name']);
    }

    public function testImportLoadsJsonBlueprint(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ProjectRepository::class);

        $tempFile = tempnam(sys_get_temp_dir(), 'blueprint_') . '.json';
        file_put_contents($tempFile, json_encode([
            'project' => ['name' => 'Imported Showcase'],
            'collections' => [
                [
                    'name' => 'Products',
                    'slug' => 'products',
                    'fields' => [
                        ['name' => 'Price', 'slug' => 'price', 'type' => 'number', 'is_required' => true, 'order' => 1],
                    ],
                ],
            ],
        ]));

        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->once())->method('flush');

        $command = new BlueprintImportCommand($em, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['blueprint-file' => $tempFile]);
        unlink($tempFile);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Blueprint importé avec succès', $tester->getDisplay());
    }
}
