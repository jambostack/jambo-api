<?php
namespace App\Command;

use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Service\Security\CsvSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
        private CsvSanitizer $csvSanitizer,
        private ?EntityManagerInterface $em = null,
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
        $fieldSlugs = array_map(fn ($f) => $f->slug, $collFields);

        $outputPath = $i->getArgument('output');
        $fh = fopen($outputPath, 'w');
        if ($fh === false) {
            $io->error("Impossible d'ouvrir le fichier de sortie: {$outputPath}");
            return Command::FAILURE;
        }

        // BOM UTF-8 pour Excel
        fwrite($fh, CsvSanitizer::BOM_UTF8);

        // En-tête assaini
        $header = ['uuid', 'slug', 'status', 'locale', ...$fieldSlugs];
        fputcsv($fh, $this->csvSanitizer->sanitizeRow($header), ',', '"', "\\");

        $batchSize = 200;
        $offset = 0;
        $totalExported = 0;

        while (true) {
            $entries = $this->entries->findBy(
                ['collection' => $coll, 'deletedAt' => null],
                ['id' => 'ASC'],
                $batchSize,
                $offset
            );

            if (empty($entries)) {
                break;
            }

            foreach ($entries as $e) {
                $row = [$e->uuid?->toString() ?? '', $e->slug, $e->status, $e->locale];
                $vals = [];
                foreach ($e->fieldValues as $fv) {
                    $vals[$fv->field?->slug ?? ''] = $fv->textValue ?? $fv->numberValue ?? $fv->booleanValue ?? '';
                }
                foreach ($fieldSlugs as $s) {
                    $row[] = $vals[$s] ?? '';
                }
                fputcsv($fh, $this->csvSanitizer->sanitizeRow($row), ',', '"', "\\");
                $totalExported++;
            }

            // Libération de la mémoire pour les gros exports
            if ($this->em !== null) {
                $this->em->clear();
                // Recharger project & collection pour le tour suivant
                $coll = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
            }

            $offset += $batchSize;
        }

        fclose($fh);
        $io->success("{$totalExported} entrées exportées de manière sécurisée vers {$outputPath}");
        return Command::SUCCESS;
    }
}
