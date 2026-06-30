<?php
namespace App\Command;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jambo:export:csv', description: 'Exporter une collection en CSV')]
class ExportCsvCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private ContentEntryRepository $entries,
        private FieldRepository $fields,
    ) { parent::__construct(); }
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED)
            ->addArgument('collection-slug', InputArgument::REQUIRED)
            ->addArgument('output', InputArgument::REQUIRED, 'Fichier CSV de sortie');
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $coll = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
        if (!$coll) { $io->error('Collection introuvable'); return Command::FAILURE; }
        $collFields = $this->fields->findBy(['collection' => $coll, 'deletedAt' => null]);
        $fm = [];
        foreach ($collFields as $f) $fm[$f->slug] = $f->slug;
        $fieldSlugs = array_keys($fm);

        $entries = $this->entries->findBy(['collection' => $coll, 'deletedAt' => null]);
        $fh = fopen($i->getArgument('output'), 'w');
        fputcsv($fh, ['uuid', 'slug', 'status', 'locale', ...$fieldSlugs]);
        foreach ($entries as $e) {
            $row = [$e->uuid?->toString() ?? '', $e->slug, $e->status, $e->locale];
            $vals = [];
            foreach ($e->fieldValues as $fv) { $vals[$fv->field->slug] = $fv->textValue ?? $fv->numericValue ?? ''; }
            foreach ($fieldSlugs as $s) $row[] = $vals[$s] ?? '';
            fputcsv($fh, $row);
        }
        fclose($fh);
        $io->success(count($entries) . " entrées exportées vers {$i->getArgument('output')}");
        return Command::SUCCESS;
    }
}
