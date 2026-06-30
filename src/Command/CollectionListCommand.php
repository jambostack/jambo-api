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

#[AsCommand(name: 'jambo:collection:list', description: 'Lister les collections d\'un projet')]
class CollectionListCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('project-uuid', InputArgument::REQUIRED); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $cols = $this->em->getRepository(\App\Entity\Collection::class)->findBy(['project' => $project, 'deletedAt' => null]);
        $rows = [];
        foreach ($cols as $c) $rows[] = [$c->id, $c->name, $c->slug, $c->isSingleton ? 'Oui' : 'Non'];
        $io->table(['ID', 'Nom', 'Slug', 'Singleton'], $rows);
        return Command::SUCCESS;
    }
}
