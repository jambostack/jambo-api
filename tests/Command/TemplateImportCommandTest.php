<?php

namespace App\Tests\Command;

use App\Command\TemplateImportCommand;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class TemplateImportCommandTest extends TestCase
{
    public function testFailsOnUnknownTemplate(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ProjectRepository::class);

        $command = new TemplateImportCommand($em, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['template-slug' => 'unknown_starter']);
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Template inconnu', $tester->getDisplay());
    }

    public function testImportsBlogTemplateSuccessfully(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ProjectRepository::class);

        // Expect calls to persist collections, fields, entries, fieldValues, and flush
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->once())->method('flush');

        $command = new TemplateImportCommand($em, $repo);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['template-slug' => 'blog']);
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Starter Blog & Editorial', $tester->getDisplay());
        $this->assertStringContainsString('importé avec succès', $tester->getDisplay());
    }
}
