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
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:enduser:status', description: 'Activer/désactiver un EndUser')]
class EndUserStatusCommand extends Command
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
            ->addArgument('active', InputArgument::REQUIRED, '1 pour activer, 0 pour désactiver');
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

        $activeVal = $i->getArgument('active');
        $isActive = filter_var($activeVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isActive === null) {
            $isActive = (bool) $activeVal;
        }

        $eu->isActive = $isActive;
        $this->em->flush();

        $statusStr = $eu->isActive ? 'activé' : 'désactivé';
        $io->success($this->trans('cli.enduser_status_changed', ['%email%' => $eu->email, '%status%' => $statusStr], null, null, "EndUser '{$eu->email}' $statusStr"));
        return Command::SUCCESS;
    }
}
