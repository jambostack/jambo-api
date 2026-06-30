<?php
namespace App\Command;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:content:list', description: 'Lister les entrées d\'une collection')]
class ContentListCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private ContentEntryRepository $entries,
    ) { parent::__construct(); }
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('collection-slug', InputArgument::REQUIRED, 'Slug de la collection')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre max', '20')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filtrer par statut');
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $coll = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
        if (!$coll) { $io->error('Collection introuvable'); return Command::FAILURE; }
        $qb = $this->entries->createQueryBuilder('e')
            ->where('e.collection = :coll')->andWhere('e.deletedAt IS NULL')
            ->setParameter('coll', $coll)->setMaxResults((int)$i->getOption('limit'))
            ->orderBy('e.createdAt', 'DESC');
        if ($i->getOption('status')) $qb->andWhere('e.status = :st')->setParameter('st', $i->getOption('status'));
        $rows = [];
        foreach ($qb->getQuery()->getResult() as $e) {
            $rows[] = [$e->uuid?->toString() ?? '?', $e->slug, $e->status, $e->locale, $e->createdAt->format('Y-m-d H:i')];
        }
        $io->table(['UUID', 'Slug', 'Statut', 'Locale', 'Créé le'], $rows);
        $io->writeln(count($rows) . " entrée(s)");
        return Command::SUCCESS;
    }
}
