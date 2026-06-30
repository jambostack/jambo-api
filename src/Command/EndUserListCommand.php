<?php
namespace App\Command;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:enduser:list', description: 'Lister les utilisateurs frontend (EndUsers) d\'un projet')]
class EndUserListCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre max', '50');
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $endUsers = $this->em->getRepository(\App\Entity\EndUser::class)
            ->findBy(['project' => $project], ['createdAt' => 'DESC'], (int)$i->getOption('limit'));
        $rows = [];
        foreach ($endUsers as $u) {
            $rows[] = [$u->uuid?->toString() ?? '?', $u->email, $u->name ?? '', $u->isActive ? 'Actif' : 'Inactif', $u->createdAt->format('Y-m-d')];
        }
        $io->table(['UUID', 'Email', 'Nom', 'Statut', 'Inscrit le'], $rows);
        $io->writeln(count($rows) . " utilisateur(s)");
        return Command::SUCCESS;
    }
}
