<?php
namespace App\Command;

use App\Repository\ApiTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:token:list', description: 'Lister les tokens API')]
class TokenListCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ApiTokenRepository $apiTokens,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }
    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $rows = [];
        foreach ($this->apiTokens->findAll() as $t) {
            $rows[] = [$t->id, $t->name, implode(', ', $t->abilities), $t->project?->name ?? '?', $t->lastUsedAt?->format('Y-m-d') ?? 'jamais'];
        }
        $io->table(['ID', 'Nom', 'Permissions', 'Projet', 'Dernier usage'], $rows);
        return Command::SUCCESS;
    }
}
