<?php
namespace App\Command;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:field:list', description: 'Lister les champs d\'une collection')]
class FieldListCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
    ) { parent::__construct(); }
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED)
            ->addArgument('collection-slug', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $coll = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
        if (!$coll) { $io->error('Collection introuvable'); return Command::FAILURE; }
        $ff = $this->fields->findBy(['collection' => $coll, 'deletedAt' => null], ['sortOrder' => 'ASC']);
        $rows = [];
        foreach ($ff as $f) $rows[] = [$f->id, $f->name, $f->slug, $f->type, $f->isRequired ? 'Oui' : 'Non'];
        $io->table(['ID', 'Nom', 'Slug', 'Type', 'Requis'], $rows);
        return Command::SUCCESS;
    }
}
