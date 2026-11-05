<?php
namespace App\Command;

use App\Repository\EndUserRepository;
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

#[AsCommand(name: 'jambo:enduser:delete', description: 'Supprimer un EndUser')]
class EndUserDeleteCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private EndUserRepository $endUsers,
        private EntityManagerInterface $em,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, "UUID de l'EndUser")
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer la suppression sans confirmation');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $uuidStr = (string) $i->getArgument('uuid');

        if (!Uuid::isValid($uuidStr)) {
            $io->error($this->trans('cli.enduser_not_found', [], null, null, 'EndUser introuvable'));
            return Command::FAILURE;
        }

        $eu = $this->endUsers->findOneBy(['uuid' => $uuidStr]);
        if (!$eu) {
            $io->error($this->trans('cli.enduser_not_found', [], null, null, 'EndUser introuvable'));
            return Command::FAILURE;
        }

        if (!$this->confirmOrAbort($i, $io, "Confirmer la suppression de l'EndUser '{$eu->email}' ?", false)) {
            $io->warning($this->trans('cli.action_cancelled', [], null, null, 'Action annulée.'));
            return Command::SUCCESS;
        }

        $email = $eu->email;
        $this->em->remove($eu);
        $this->em->flush();

        $io->success($this->trans('cli.enduser_deleted', ['%email%' => $email], null, null, "EndUser '$email' supprimé"));
        return Command::SUCCESS;
    }
}
