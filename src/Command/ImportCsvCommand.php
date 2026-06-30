<?php
namespace App\Command;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsCommand(name: 'jambo:import:csv', description: 'Importer un fichier CSV dans une collection')]
class ImportCsvCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('collection-slug', InputArgument::REQUIRED, 'Slug de la collection')
            ->addArgument('csv-file', InputArgument::REQUIRED, 'Chemin du fichier CSV')
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'Délimiteur CSV', ',')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale', 'en')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Statut (draft/published)', 'draft');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $path = $i->getArgument('csv-file');
        if (!file_exists($path)) { $io->error("Fichier introuvable : $path"); return Command::FAILURE; }

        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $collection = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
        if (!$collection) { $io->error('Collection introuvable'); return Command::FAILURE; }

        $collFields = $this->fields->findBy(['collection' => $collection, 'deletedAt' => null]);
        $fieldMap = [];
        foreach ($collFields as $f) { $fieldMap[$f->slug] = $f; }

        // Read CSV
        $fh = fopen($path, 'r');
        if (!$fh) { $io->error("Impossible d'ouvrir $path"); return Command::FAILURE; }
        $headers = fgetcsv($fh, 0, $i->getOption('delimiter'));
        if (!$headers) { $io->error('CSV vide ou invalide'); return Command::FAILURE; }

        $created = 0; $errors = [];
        $locale = $i->getOption('locale');
        $status = $i->getOption('status');
        $io->progressStart();

        while (($row = fgetcsv($fh, 0, $i->getOption('delimiter'))) !== false) {
            try {
                $data = array_combine($headers, $row);
                $entry = new ContentEntry();
                $entry->project = $project;
                $entry->collection = $collection;
                $entry->locale = $locale;
                $entry->status = $status;

                // Build slug from first non-empty field
                $first = reset($data);
                $slugStr = is_string($first) && $first ? $first : 'imported-' . $created;
                $entry->slug = (string)$this->slugger->slug($slugStr)->lower()->truncate(50, '');

                $this->em->persist($entry);
                $this->em->flush();

                foreach ($data as $colName => $value) {
                    $slug = (string)$this->slugger->slug($colName)->lower();
                    if (!isset($fieldMap[$slug])) continue;
                    $fv = new ContentFieldValue();
                    $fv->contentEntry = $entry;
                    $fv->field = $fieldMap[$slug];
                    $fv->fieldType = $fieldMap[$slug]->type;
                    $fv->textValue = (string)$value;
                    $this->em->persist($fv);
                }
                $this->em->flush();
                $created++;
            } catch (\Exception $e) {
                $errors[] = "Ligne " . ($created + 1) . " : " . $e->getMessage();
                $this->em->clear();
            }
            $io->progressAdvance();
        }
        fclose($fh);
        $io->progressFinish();

        $io->success("$created entrées importées !");
        if ($errors) { $io->warning(implode("\n", array_slice($errors, 0, 5))); }
        return Command::SUCCESS;
    }
}
