<?php

namespace App\Command;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:sync-schema', description: 'Synchronise le schéma entre deux projets')]
class SyncSchemaCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source_uuid', InputArgument::REQUIRED, 'UUID du projet source')
            ->addArgument('target_uuid', InputArgument::REQUIRED, 'UUID du projet cible')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation sans écriture');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceUuid = $input->getArgument('source_uuid');
        $targetUuid = $input->getArgument('target_uuid');
        $dryRun = $input->getOption('dry-run');

        $source = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $sourceUuid]);
        $target = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $targetUuid]);

        if (!$source || !$target) {
            $io->error('Projet source ou cible introuvable');

            return Command::FAILURE;
        }

        $io->title("Sync Schema: {$source->name} → {$target->name}");

        $sourceCollections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $source, 'deletedAt' => null]);

        $targetCollections = $this->getTargetCollections($target);

        $added = 0;
        $skipped = 0;

        foreach ($sourceCollections as $srcCol) {
            if (isset($targetCollections[$srcCol->slug])) {
                $io->text("  ⏭️  {$srcCol->slug} (déjà présent)");
                $skipped++;
                continue;
            }

            $io->text("  ✅ {$srcCol->slug} → création");

            if (!$dryRun) {
                $newCol = new Collection();
                $newCol->name = $srcCol->name;
                $newCol->slug = $srcCol->slug;
                $newCol->description = $srcCol->description;
                $newCol->isSingleton = $srcCol->isSingleton;
                $newCol->project = $target;
                $newCol->order = count($target->collections->toArray());
                $this->em->persist($newCol);

                foreach ($srcCol->fields as $srcField) {
                    if ($srcField->isDeleted()) continue;
                    $newField = new Field();
                    $newField->name = $srcField->name;
                    $newField->slug = $srcField->slug;
                    $newField->type = $srcField->type;
                    $newField->options = $srcField->options;
                    $newField->isRequired = $srcField->isRequired;
                    $newField->order = $srcField->order;
                    $newField->collection = $newCol;
                    $this->em->persist($newField);
                }
                $added++;
            }
        }

        if (!$dryRun && $added > 0) {
            $this->em->flush();
        }

        $mode = $dryRun ? ' (dry-run)' : '';
        $io->success("Sync terminé$mode — $added collections créées, $skipped ignorées");

        return Command::SUCCESS;
    }

    /** @return array<string, Collection> */
    private function getTargetCollections(Project $target): array
    {
        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $target, 'deletedAt' => null]);

        $map = [];
        foreach ($collections as $c) {
            $map[$c->slug] = $c;
        }

        return $map;
    }
}
