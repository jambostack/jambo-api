<?php

namespace App\Command;

use App\Service\ProjectTemplateBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:export-project-template',
    description: 'Export a project structure as a reusable ProjectTemplate',
)]
class ExportProjectTemplateCommand extends Command
{
    public function __construct(
        private ProjectTemplateBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID of the project to export')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Template name')
            ->addOption('description', null, InputOption::VALUE_OPTIONAL, 'Template description');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $uuid        = $input->getArgument('project-uuid');
        $name        = $input->getOption('name');
        $description = $input->getOption('description');

        $template = $this->builder->exportFromProject($uuid, $name, $description);

        if ($template === null) {
            $io->error(sprintf('Project with UUID "%s" not found.', $uuid));
            return Command::FAILURE;
        }

        $io->success(sprintf('Template "%s" (ID: %d) created successfully.', $template->name, $template->id));

        return Command::SUCCESS;
    }
}
