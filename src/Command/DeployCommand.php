<?php

namespace App\Command;

use App\Entity\Project;
use App\Service\ExportImport\ProjectExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:deploy', description: 'Exporte un projet pour déploiement')]
class DeployCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project_uuid', InputArgument::REQUIRED, 'UUID du projet à exporter')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Répertoire de sortie', 'var/deploy')
            ->addOption('include-media', null, InputOption::VALUE_NONE, 'Inclure les fichiers média');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uuid = $input->getArgument('project_uuid');
        $outDir = $input->getOption('output');
        $includeMedia = $input->getOption('include-media');

        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            $io->error("Projet $uuid introuvable");

            return Command::FAILURE;
        }

        $io->title("Jambo Deploy — Export du projet {$project->name}");

        $options = new \App\Dto\ExportOptions();
        $options->structure = true;
        $options->content = true;
        $options->settings = true;
        $options->endUsers = true;
        $options->media = $includeMedia;

        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $zipPath = $outDir . '/jambo_deploy_' . $project->uuid->toRfc4122() . '_' . date('Ymd_His') . '.zip';

        $io->section('Export en cours...');
        $this->exporter->export($project, $options, $zipPath);

        $size = file_exists($zipPath) ? filesize($zipPath) : 0;
        $io->success(sprintf(
            "Projet '%s' exporté avec succès.\nFichier: %s\nTaille: %s",
            $project->name,
            $zipPath,
            $this->formatBytes((int) $size)
        ));

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';

        return $bytes . ' B';
    }
}
