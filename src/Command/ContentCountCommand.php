<?php
namespace App\Command;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:content:count', description: 'Compter les entrées par collection')]
class ContentCountCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet'); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $cols = $this->collections->findByProjectPaginated($project, 1, 100);
        $rows = [];
        $total = 0;
        foreach ($cols as $c) {
            $count = $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')->from(\App\Entity\ContentEntry::class, 'e')
                ->where('e.collection = :c')->andWhere('e.deletedAt IS NULL')
                ->setParameter('c', $c)->getQuery()->getSingleScalarResult();
            $rows[] = [$c->name, $c->slug, $count];
            $total += (int)$count;
        }
        $io->table(['Collection', 'Slug', 'Entrées'], $rows);
        $io->writeln("Total: $total entrée(s)");
        return Command::SUCCESS;
    }
}
