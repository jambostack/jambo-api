<?php
namespace App\Command;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:token:revoke', description: 'Révoquer un token API par son ID')]
class TokenRevokeCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ApiTokenRepository $apiTokens,
        private EntityManagerInterface $em,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'ID du token à révoquer')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer la révocation sans confirmation');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $id = (int) $i->getArgument('id');
        if ($id <= 0) {
            $io->error($this->trans('cli.token_not_found', [], null, null, 'Token introuvable'));
            return Command::FAILURE;
        }

        $token = $this->apiTokens->find($id);
        if (!$token) {
            $io->error($this->trans('cli.token_not_found', [], null, null, 'Token introuvable'));
            return Command::FAILURE;
        }

        if (!$this->confirmOrAbort($i, $io, "Confirmer la révocation du token '{$token->name}' (ID: {$token->id}) ?", false)) {
            $io->warning($this->trans('cli.action_cancelled', [], null, null, 'Action annulée.'));
            return Command::SUCCESS;
        }

        $name = $token->name;
        $this->em->remove($token);
        $this->em->flush();

        $io->success($this->trans('cli.token_revoked', ['%name%' => $name], null, null, "Token '$name' révoqué !"));
        return Command::SUCCESS;
    }
}
