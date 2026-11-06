<?php

namespace App\Command;

use App\Entity\Media;
use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\DirectUploadService;
use App\Service\StorageDriverFactory;
use App\Service\StorageManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

#[AsCommand(
    name: 'app:media:prune-orphans',
    description: 'Prune expired direct upload intents, obsolete soft-deleted media and orphaned files to prevent disk exhaustion'
)]
class PruneOrphanUploadsCommand extends Command
{
    use LockableTrait;
    use CliSecurityTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DirectUploadService $directUploadService,
        private readonly ProjectRepository $projectRepository,
        private readonly MediaRepository $mediaRepository,
        private readonly ProjectStorageProfileRepository $profileRepo,
        private readonly StorageRuleRepository $ruleRepo,
        private readonly StorageDriverFactory $driverFactory,
        private readonly UploadHandler $uploadHandler,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'retention-days',
                'r',
                InputOption::VALUE_REQUIRED,
                'Retention window in days for soft-deleted media before hard deletion',
                '30'
            )
            ->addOption(
                'project',
                'p',
                InputOption::VALUE_REQUIRED,
                'Target project UUID or slug',
                null
            )
            ->addOption(
                'intents-only',
                null,
                InputOption::VALUE_NONE,
                'Only prune expired upload intents'
            )
            ->addOption(
                'clean-disk',
                null,
                InputOption::VALUE_NONE,
                'Scan and delete orphaned files on local disk'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate cleanup without deleting data'
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
            $retentionDays = (int) $input->getOption('retention-days');
            if ($retentionDays < 1) {
                $io->error($this->trans('cli.days_positive_integer', [], null, null, 'The --retention-days option must be a positive integer.'));
                return Command::INVALID;
            }

            $projectParam = $input->getOption('project');
            $project = null;
            if ($projectParam !== null && $projectParam !== '') {
                $project = $this->projectRepository->findOneBy(['uuid' => $projectParam])
                    ?? $this->projectRepository->findOneBy(['slug' => $projectParam]);
                if (!$project) {
                    $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectParam], null, null, "Project '$projectParam' not found."));
                    return Command::FAILURE;
                }
            }

            $dryRun = (bool) $input->getOption('dry-run');
            $intentsOnly = (bool) $input->getOption('intents-only');
            $cleanDisk = (bool) $input->getOption('clean-disk');

            $io->title($this->trans('cli.prune_orphans.title', [], null, null, 'Orphaned Uploads & Media Retention Pruning'));
            if ($dryRun) {
                $io->warning($this->trans('cli.prune_orphans.dry_run_mode', [], null, null, 'DRY RUN MODE: No database records or physical files will be deleted.'));
            }

            // 1. Nettoyage des intentions d'upload direct expirées (S3 multipart abort + statut expired)
            $io->section($this->trans('cli.prune_orphans.cleaning_intents', [], null, null, '1. Cleaning expired upload intents...'));
            $cleanedIntents = 0;
            if (!$dryRun) {
                $cleanedIntents = $this->directUploadService->cleanupExpiredIntents($project, 100);
            }
            $io->text(sprintf('Expired intents cleaned: %d', $cleanedIntents));

            if ($intentsOnly) {
                $io->success($this->trans('cli.prune_orphans.success', [], null, null, 'Orphan upload cleanup completed successfully.'));
                return Command::SUCCESS;
            }

            // 2. Suppression définitive des médias soft-deleted au-delà de la fenêtre de rétention
            $io->section($this->trans('cli.prune_orphans.purging_soft_deleted', ['%days%' => $retentionDays], null, null, sprintf('2. Purging soft-deleted media older than %d days...', $retentionDays)));
            $threshold = new \DateTimeImmutable("-{$retentionDays} days");

            $qb = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Media::class, 'm')
                ->where('m.deletedAt IS NOT NULL')
                ->andWhere('m.deletedAt <= :threshold')
                ->setParameter('threshold', $threshold);

            if ($project !== null) {
                $qb->andWhere('m.project = :project')->setParameter('project', $project);
            }

            /** @var Media[] $obsoleteMedia */
            $obsoleteMedia = $qb->getQuery()->getResult();
            $purgedMediaCount = 0;
            $freedBytes = 0;

            foreach ($obsoleteMedia as $media) {
                $fileSize = $media->fileSize ?? 0;
                $freedBytes += $fileSize;
                $purgedMediaCount++;

                if (!$dryRun) {
                    // Supprimer du stockage distant S3 / Flysystem
                    if (!empty($media->storagePaths)) {
                        try {
                            $storageManager = new StorageManager($media->project, $this->profileRepo, $this->ruleRepo, $this->driverFactory);
                            $storageManager->delete($media->storagePaths);
                        } catch (\Throwable) {}
                    }

                    // Supprimer du stockage local Vich
                    try {
                        $this->uploadHandler->remove($media, 'file');
                    } catch (\Throwable) {}

                    $this->em->remove($media);
                }
            }

            if (!$dryRun && $purgedMediaCount > 0) {
                $this->em->flush();
            }

            $freedMb = round($freedBytes / (1024 * 1024), 2);
            $io->text(sprintf('Obsolete soft-deleted media purged: %d (~%s MB freed)', $purgedMediaCount, $freedMb));

            // 3. Détection et nettoyage des fichiers orphelins sur le disque local
            $orphanedDiskFilesCount = 0;
            $orphanedDiskBytes = 0;
            if ($cleanDisk) {
                $io->section($this->trans('cli.prune_orphans.scanning_disk', [], null, null, '3. Scanning local storage for unreferenced physical files...'));
                $mediaBaseDir = $this->projectDir . '/public/uploads/media';
                if (is_dir($mediaBaseDir)) {
                    $projectsToScan = $project !== null ? [$project] : $this->projectRepository->findAll();
                    foreach ($projectsToScan as $proj) {
                        $projUuid = $proj->uuid?->toRfc4122();
                        if (!$projUuid) {
                            continue;
                        }
                        $projDir = $mediaBaseDir . '/' . $projUuid;
                        if (!is_dir($projDir)) {
                            continue;
                        }
                        $files = @scandir($projDir) ?: [];
                        foreach ($files as $file) {
                            if ($file === '.' || $file === '..' || is_dir($projDir . '/' . $file)) {
                                continue;
                            }
                            $filePath = $projDir . '/' . $file;
                            // Vérifier si un enregistrement Media existe pour ce fichier
                            $exists = (int) $this->em->createQueryBuilder()
                                ->select('COUNT(m.id)')
                                ->from(Media::class, 'm')
                                ->where('m.project = :proj')
                                ->andWhere('m.fileName = :fn')
                                ->setParameter('proj', $proj)
                                ->setParameter('fn', $file)
                                ->getQuery()
                                ->getSingleScalarResult();

                            if ($exists === 0) {
                                $size = (int) @filesize($filePath);
                                $orphanedDiskBytes += $size;
                                $orphanedDiskFilesCount++;
                                if (!$dryRun) {
                                    @unlink($filePath);
                                }
                            }
                        }
                    }
                }
                $orphanedDiskMb = round($orphanedDiskBytes / (1024 * 1024), 2);
                $io->text(sprintf('Orphaned physical files on disk removed: %d (~%s MB freed)', $orphanedDiskFilesCount, $orphanedDiskMb));
            }

            $io->success(sprintf(
                'Cleanup completed. %d expired intents, %d purged media records, %d unreferenced disk files.',
                $cleanedIntents,
                $purgedMediaCount,
                $orphanedDiskFilesCount
            ));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
