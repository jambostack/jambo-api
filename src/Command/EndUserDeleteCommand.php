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

#[AsCommand(name: 'jambo:enduser:delete', description: 'Supprimer un EndUser')]
class EndUserDeleteCommand extends Command
{
    public function __construct(
        private EndUserRepository $endUsers,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('uuid', InputArgument::REQUIRED, 'UUID de l\'EndUser'); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $eu = $this->endUsers->findOneBy(['uuid' => $i->getArgument('uuid')]);
        if (!$eu) { $io->error('EndUser introuvable'); return Command::FAILURE; }
        $this->em->remove($eu);
        $this->em->flush();
        $io->success("EndUser '{$eu->email}' supprimé");
        return Command::SUCCESS;
    }
}
