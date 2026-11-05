<?php
namespace App\Command;

use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:content:delete', description: 'Supprimer une entrée (soft delete)')]
class ContentDeleteCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ContentEntryRepository $entries,
        private EntityManagerInterface $em,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, "UUID de l'entrée")
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer la suppression sans confirmation');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $uuidStr = (string) $i->getArgument('uuid');

        if (!Uuid::isValid($uuidStr)) {
            $io->error($this->trans('cli.entry_not_found', [], null, null, 'Entrée introuvable'));
            return Command::FAILURE;
        }

        $entry = $this->entries->findOneBy(['uuid' => $uuidStr]);
        if (!$entry) {
            $io->error($this->trans('cli.entry_not_found', [], null, null, 'Entrée introuvable'));
            return Command::FAILURE;
        }

        if (!$this->confirmOrAbort($i, $io, "Confirmer la suppression (soft delete) de l'entrée '{$entry->slug}' ?", false)) {
            $io->warning($this->trans('cli.action_cancelled', [], null, null, 'Action annulée.'));
            return Command::SUCCESS;
        }

        $entry->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $io->success("Entrée '{$entry->slug}' supprimée");
        return Command::SUCCESS;
    }
}
