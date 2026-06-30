<?php
namespace App\Command;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:content:delete', description: 'Supprimer une entrée (soft delete)')]
class ContentDeleteCommand extends Command
{
    public function __construct(
        private ContentEntryRepository $entries,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('uuid', InputArgument::REQUIRED, 'UUID de l\'entrée'); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $entry = $this->entries->findOneBy(['uuid' => $i->getArgument('uuid')]);
        if (!$entry) { $io->error('Entrée introuvable'); return Command::FAILURE; }
        $entry->deletedAt = new \DateTimeImmutable();
        $this->em->flush();
        $io->success("Entrée '{$entry->slug}' supprimée");
        return Command::SUCCESS;
    }
}
