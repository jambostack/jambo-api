<?php

namespace App\Command;

use App\Dto\ExportOptions;
use App\Repository\ProjectRepository;
use App\Service\ExportImport\ProjectExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:export',
    description: 'Export a project to a ZIP file',
)]
class ProjectExportCommand extends Command
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID of the project to export')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output ZIP file path')
            ->addOption('with-content', null, InputOption::VALUE_NONE, 'Include content entries')
            ->addOption('with-media', null, InputOption::VALUE_NONE, 'Include media files')
            ->addOption('with-settings', null, InputOption::VALUE_NONE, 'Include project settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uuid = $input->getArgument('uuid');

        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            $io->error("Project not found: $uuid");
            return Command::FAILURE;
        }

        $options = new ExportOptions();
        $options->content = $input->getOption('with-content');
        $options->media = $input->getOption('with-media');
        $options->settings = $input->getOption('with-settings');

        $outputPath = $input->getOption('output')
            ?? sprintf('export-%s-%s.zip', preg_replace('/[^a-zA-Z0-9_-]/', '-', $project->name), date('Y-m-d-His'));

        $io->section("Exporting project: {$project->name}");
        $io->listing($options->getEnabledOptions());

        $this->exporter->export($project, $options, $outputPath);

        $size = filesize($outputPath);
        $io->success(sprintf('Exported to %s (%s)', $outputPath, $this->formatBytes($size)));

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
