<?php

namespace App\Command;

use App\Service\ImageTransformService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:media:prune-cache',
    description: 'Prune obsolete image transform cache files to prevent disk exhaustion'
)]
class PruneMediaCacheCommand extends Command
{
    use LockableTrait;
    use CliSecurityTrait;

    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly ImageTransformService $imageTransformService,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
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
            )
            ->addOption(
                'tenant',
                't',
                InputOption::VALUE_REQUIRED,
                'Optional project/tenant UUID to prune only isolated cache for that tenant',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->comment($this->trans('cli.command_already_running', [], null, null, 'Command is already running in another process.'));
            return Command::SUCCESS;
        }

        try {
            $days = (int) $input->getOption('days');
            if ($days < 1) {
                $io->error($this->trans('cli.days_positive_integer', [], null, null, 'The --days option must be a positive integer.'));
                return Command::INVALID;
            }

            $maxMb = $input->getOption('max-mb');
            $maxSizeBytes = null;
            if ($maxMb !== null) {
                $maxMbInt = (int) $maxMb;
                if ($maxMbInt < 1) {
                    $io->error($this->trans('cli.max_mb_positive_integer', [], null, null, 'The --max-mb option must be a positive integer.'));
                    return Command::INVALID;
                }
                $maxSizeBytes = $maxMbInt * 1024 * 1024;
            }

            $tenant = $input->getOption('tenant');
            if ($tenant !== null) {
                $tenant = trim((string) $tenant);
                if (!preg_match(self::UUID_REGEX, $tenant)) {
                    $io->error($this->trans('cli.invalid_tenant_uuid', [], null, null, 'The --tenant option must be a valid RFC 4122 UUID.'));
                    return Command::INVALID;
                }
            }

            $io->title('Image Transform Cache Pruning');
            $io->text(sprintf('Cleaning cache files older than %d days...', $days));
            if ($maxSizeBytes !== null) {
                $io->text(sprintf('Enforcing max cache size: %d MB', (int) $maxMb));
            }
            if ($tenant !== null) {
                $io->text(sprintf('Targeting isolated tenant cache: %s', $tenant));
            }

            $result = $this->imageTransformService->pruneCache($days, $maxSizeBytes, $tenant);

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
        } finally {
            $this->release();
        }
    }
}
