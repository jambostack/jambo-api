<?php
namespace App\Command;
use App\Entity\EndUser;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'jambo:enduser:create', description: 'Créer un utilisateur frontend (EndUser)')]
class EndUserCreateCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) { parent::__construct(); }
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom', '');
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $eu = new EndUser();
        $eu->project = $project;
        $eu->email = $i->getArgument('email');
        $eu->name = $i->getOption('name');
        $eu->isActive = true;
        $eu->password = $this->hasher->hashPassword($eu, $i->getArgument('password'));
        $this->em->persist($eu);
        $this->em->flush();
        $io->success("EndUser '{$eu->email}' créé !");
        return Command::SUCCESS;
    }
}
