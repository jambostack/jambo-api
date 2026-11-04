<?php

namespace App\Tests\Command;

use App\Command\PruneMediaCacheCommand;
use App\Service\ImageTransformService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PruneMediaCacheCommandTest extends TestCase
{
    private ImageTransformService $imageTransformService;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->imageTransformService = $this->createMock(ImageTransformService::class);
        $command = new PruneMediaCacheCommand($this->imageTransformService);

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:media:prune-cache'));
    }

    public function testExecutesSuccessfullyWithDefaults(): void
    {
        $this->imageTransformService
            ->expects($this->once())
            ->method('pruneCache')
            ->with(30, null)
            ->willReturn([
                'deletedFiles' => 5,
                'freedBytes' => 1048576, // 1 MB
                'remainingFiles' => 12,
                'remainingBytes' => 2097152, // 2 MB
            ]);

        $status = $this->commandTester->execute([]);

        $this->assertSame(0, $status);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Image Transform Cache Pruning', $output);
        $this->assertStringContainsString('Pruned 5 files (1 MB freed)', $output);
    }

    public function testExecutesWithCustomDaysAndMaxMb(): void
    {
        $this->imageTransformService
            ->expects($this->once())
            ->method('pruneCache')
            ->with(7, 52428800) // 50 MB
            ->willReturn([
                'deletedFiles' => 10,
                'freedBytes' => 5242880, // 5 MB
                'remainingFiles' => 20,
                'remainingBytes' => 10485760, // 10 MB
            ]);

        $status = $this->commandTester->execute([
            '--days' => '7',
            '--max-mb' => '50',
        ]);

        $this->assertSame(0, $status);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Enforcing max cache size: 50 MB', $output);
        $this->assertStringContainsString('Pruned 10 files (5 MB freed)', $output);
    }

    public function testRejectsInvalidDays(): void
    {
        $status = $this->commandTester->execute([
            '--days' => '0',
        ]);

        $this->assertSame(2, $status); // Command::INVALID
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('The --days option must be a positive integer', $output);
    }
}
