<?php

namespace App\Command;

use App\Service\ImageTransformService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:media:prune-cache',
    description: 'Prune obsolete image transform cache files to prevent disk exhaustion'
)]
class PruneMediaCacheCommand extends Command
{
    public function __construct(
        private readonly ImageTransformService $imageTransformService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Retention window for image transform cache in days',
                '30'
            )
            ->addOption(
                'max-mb',
                'm',
                InputOption::VALUE_REQUIRED,
                'Maximum total size in megabytes for the image cache directory',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $io->error('The --days option must be a positive integer.');
            return Command::INVALID;
        }

        $maxMb = $input->getOption('max-mb');
        $maxSizeBytes = null;
        if ($maxMb !== null) {
            $maxMbInt = (int) $maxMb;
            if ($maxMbInt < 1) {
                $io->error('The --max-mb option must be a positive integer.');
                return Command::INVALID;
            }
            $maxSizeBytes = $maxMbInt * 1024 * 1024;
        }

        $io->title('Image Transform Cache Pruning');
        $io->text(sprintf('Cleaning cache files older than %d days...', $days));
        if ($maxSizeBytes !== null) {
            $io->text(sprintf('Enforcing max cache size: %d MB', (int) $maxMb));
        }

        $result = $this->imageTransformService->pruneCache($days, $maxSizeBytes);

        $freedMb = round($result['freedBytes'] / (1024 * 1024), 2);
        $remainingMb = round($result['remainingBytes'] / (1024 * 1024), 2);

        $io->success(sprintf(
            'Pruned %d files (%s MB freed). Remaining: %d files (%s MB).',
            $result['deletedFiles'],
            $freedMb,
            $result['remainingFiles'],
            $remainingMb
        ));

        return Command::SUCCESS;
    }
}
