<?php
namespace App\Command;

use App\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:project:list', description: 'Lister tous les projets')]
class ProjectListCommand extends Command
{
    public function __construct(private ProjectRepository $projects) { parent::__construct(); }
    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $rows = [];
        foreach ($this->projects->findAll() as $p) {
            $rows[] = [$p->uuid?->toString() ?? 'N/A', $p->name, $p->defaultLocale ?? 'en', $p->publicApi ? 'Oui' : 'Non'];
        }
        $io->table(['UUID', 'Nom', 'Locale', 'API Publique'], $rows);
        return Command::SUCCESS;
    }
}
