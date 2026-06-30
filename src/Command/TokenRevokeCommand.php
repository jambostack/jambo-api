<?php
namespace App\Command;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:token:revoke', description: 'Révoquer un token API par son ID')]
class TokenRevokeCommand extends Command
{
    public function __construct(
        private ApiTokenRepository $apiTokens,
        private EntityManagerInterface $em,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'ID du token à révoquer');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $token = $this->apiTokens->find($i->getArgument('id'));
        if (!$token) { $io->error('Token introuvable'); return Command::FAILURE; }
        $this->em->remove($token);
        $this->em->flush();
        $io->success("Token '{$token->name}' révoqué !");
        return Command::SUCCESS;
    }
}
