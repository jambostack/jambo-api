<?php

namespace App\Command;

use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:content:purge', description: 'Supprimer TOUTES les entrées d\'une collection (soft/hard delete)')]
class ContentPurgeCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID (6+ premiers caractères acceptés)')
            ->addArgument('collection-slug', InputArgument::REQUIRED)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation')
            ->addOption('hard', null, InputOption::VALUE_NONE, 'DELETE définitif (au lieu de soft delete)');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $project = $this->findProject($i->getArgument('project-uuid'));
        if (!$project) {
            $io->error("Projet introuvable: '{$i->getArgument('project-uuid')}'");
            return Command::FAILURE;
        }

        $coll = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
        if (!$coll) {
            $io->error("Collection '{$i->getArgument('collection-slug')}' introuvable");
            return Command::FAILURE;
        }

        // Count active entries
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')->from(\App\Entity\ContentEntry::class, 'e')
            ->where('e.collection = :c')->andWhere('e.deletedAt IS NULL')
            ->setParameter('c', $coll)->getQuery()->getSingleScalarResult();

        if ($count === 0) {
            $io->success("Aucune entrée à supprimer dans '{$coll->name}'.");
            return Command::SUCCESS;
        }

        $mode = $i->getOption('hard') ? 'SUPPRESSION DÉFINITIVE' : 'soft delete';
        if (!$i->getOption('force') && !$io->confirm(
            "⚠️  Vider '{$coll->name}' ({$count} entrées, $mode) ?", false
        )) {
            $io->warning('Annulé.');
            return Command::SUCCESS;
        }

        if ($i->getOption('hard')) {
            $conn = $this->em->getConnection();
            $cid = $coll->id;
            $conn->executeStatement('DELETE FROM content_field_value WHERE content_entry_id IN (SELECT id FROM content_entry WHERE collection_id = :c)', ['c' => $cid]);
            $conn->executeStatement('DELETE FROM content_entry WHERE collection_id = :c', ['c' => $cid]);
        } else {
            $this->em->createQueryBuilder()
                ->update(\App\Entity\ContentEntry::class, 'e')
                ->set('e.deletedAt', ':now')->where('e.collection = :c')->andWhere('e.deletedAt IS NULL')
                ->setParameter('now', new \DateTimeImmutable())->setParameter('c', $coll)
                ->getQuery()->execute();
        }

        $io->success("{$count} entrée(s) {$mode} dans '{$coll->name}' !");
        return Command::SUCCESS;
    }

    private function findProject(string $input): ?\App\Entity\Project
    {
        if (strlen($input) >= 6) {
            $conn = $this->em->getConnection();
            $isSqlite = $conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform;
            $castExpr = $isSqlite ? "hex(uuid)" : "CAST(uuid AS TEXT)";
            $rows = $conn->fetchAllAssociative(
                "SELECT id FROM project WHERE $castExpr LIKE :p LIMIT 2",
                ['p' => strtolower($input) . '%']
            );
            if (count($rows) === 1) return $this->projects->find($rows[0]['id']);
            if (count($rows) > 1) throw new \RuntimeException("Plusieurs projets avec le préfixe '$input'.");
        }
        return $this->projects->findOneBy(['uuid' => $input]);
    }
}
