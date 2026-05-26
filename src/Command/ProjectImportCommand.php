<?php

namespace App\Command;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\ExportImport\ConflictResolver;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:import',
    description: 'Import a project from a ZIP file',
)]
class ProjectImportCommand extends Command
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectImporter $importer,
        private ConflictResolver $conflictResolver,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the ZIP file to import')
            ->addOption('project-name', null, InputOption::VALUE_OPTIONAL, 'Name for the new project')
            ->addOption('target-project', null, InputOption::VALUE_OPTIONAL, 'UUID of existing project to merge into')
            ->addOption('strategy', null, InputOption::VALUE_OPTIONAL, 'Conflict resolution: overwrite, skip, or new-uuids', 'skip')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview conflicts only, do not import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file');

        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return Command::FAILURE;
        }

        $options = new ImportOptions();
        $options->strategy = $input->getOption('strategy') ?? 'skip';

        $targetUuid = $input->getOption('target-project');

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($filePath);
            $manifest = $this->importer->validateManifest($extractedDir);

            $io->section('Export package info');
            $io->definitionList(
                ['Version' => $manifest['version']],
                ['Exported at' => $manifest['exported_at'] ?? 'N/A'],
                ['Source project' => $manifest['project']['name'] ?? 'N/A'],
                ['Includes' => implode(', ', $manifest['included'] ?? [])],
            );

            if ($targetUuid) {
                $project = $this->projectRepository->findOneBy(['uuid' => $targetUuid]);
                if (!$project) {
                    $io->error("Target project not found: $targetUuid");
                    return Command::FAILURE;
                }
                $options->createNewProject = false;
            } else {
                $project = new Project();
                $project->name = $input->getOption('project-name')
                    ?? ($manifest['project']['name'] ?? 'Imported') . ' (imported)';
                $project->defaultLocale = $manifest['project']['default_locale'] ?? 'en';
                $project->locales = [$project->defaultLocale];
                $options->createNewProject = true;
            }

            $conflicts = $this->importer->previewConflicts($project, $extractedDir);

            if ($this->conflictResolver->hasConflicts($conflicts)) {
                $io->section('Conflicts detected');
                $rows = array_map(fn($c) => [
                    $c->entityType, $c->entityName, $c->entityUuid, $options->strategy,
                ], $conflicts);
                $io->table(['Type', 'Name', 'UUID', 'Action'], $rows);
            } else {
                $io->success('No conflicts detected');
            }

            if ($input->getOption('dry-run')) {
                return Command::SUCCESS;
            }

            if (!$io->confirm('Proceed with import?', true)) {
                return Command::SUCCESS;
            }

            if ($options->createNewProject) {
                $this->em->persist($project);
                $this->em->flush();
            }

            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            try {
                $this->importer->import($project, $extractedDir, $options);
                $this->em->flush();
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            $io->success(sprintf('Import complete. Project UUID: %s', $project->uuid?->toString()));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Import failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }
}
