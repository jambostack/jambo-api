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
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:content:count', description: 'Compter les entrées par collection')]
class ContentCountCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private EntityManagerInterface $em,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }
    protected function configure(): void { $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet'); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $projectUuid = (string) $i->getArgument('project-uuid');
        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, 'Projet introuvable'));
            return Command::FAILURE;
        }
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
