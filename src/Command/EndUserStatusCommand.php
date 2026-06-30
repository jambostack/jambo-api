<?php
namespace App\Command;
use App\Repository\EndUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:enduser:status', description: 'Activer/désactiver un EndUser')]
class EndUserStatusCommand extends Command
{
    public function __construct(
        private EndUserRepository $endUsers,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }
    protected function configure(): void {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'UUID de l\'EndUser')
            ->addArgument('active', InputArgument::REQUIRED, '1 pour activer, 0 pour désactiver');
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $eu = $this->endUsers->findOneBy(['uuid' => $i->getArgument('uuid')]);
        if (!$eu) { $io->error('EndUser introuvable'); return Command::FAILURE; }
        $eu->isActive = (bool)$i->getArgument('active');
        $this->em->flush();
        $io->success("EndUser '{$eu->email}' " . ($eu->isActive ? 'activé' : 'désactivé'));
        return Command::SUCCESS;
    }
}
