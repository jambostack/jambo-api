<?php
namespace App\Command;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:content:show', description: 'Afficher le détail d\'une entrée')]
class ContentShowCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private ContentEntryRepository $entries,
    ) { parent::__construct(); }
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED)
            ->addArgument('collection-slug', InputArgument::REQUIRED)
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID de l\'entrée');
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $entry = $this->entries->findOneBy(['uuid' => $i->getArgument('uuid')]);
        if (!$entry) { $io->error('Entrée introuvable'); return Command::FAILURE; }
        $io->writeln("UUID: {$entry->uuid}");
        $io->writeln("Slug: {$entry->slug}");
        $io->writeln("Statut: {$entry->status}");
        $io->writeln("Locale: {$entry->locale}");
        $io->writeln("Créé: {$entry->createdAt->format('Y-m-d H:i:s')}");
        $io->writeln("Champs:");
        foreach ($entry->fieldValues as $fv) {
            $val = $fv->textValue ?? $fv->numericValue ?? ($fv->booleanValue ? 'true' : 'false') ?? 'null';
            $io->writeln("  {$fv->field->slug}: $val");
        }
        return Command::SUCCESS;
    }
}
