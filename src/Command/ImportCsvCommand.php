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
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:import:csv', description: 'Importer un fichier CSV dans une collection')]
class ImportCsvCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

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
        $path = (string) $i->getArgument('csv-file');
        if (!is_file($path) || !is_readable($path)) {
            $io->error($this->trans('cli.invalid_file', ['%path%' => $path], null, null, "Fichier introuvable : $path"));
            return Command::FAILURE;
        }

        if (filesize($path) > 50 * 1024 * 1024) {
            $io->error($this->trans('cli.file_too_large', ['%max%' => '50'], null, null, 'Le fichier dépasse la taille maximale autorisée (50 Mo).'));
            return Command::FAILURE;
        }

        $delimiter = (string) $i->getOption('delimiter');
        if (strlen($delimiter) !== 1) {
            $io->error($this->trans('cli.csv_delimiter_single_char', [], null, null, 'Le délimiteur CSV doit être un caractère unique.'));
            return Command::FAILURE;
        }

        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error($this->trans('cli.project_not_found', ['%ident%' => (string)$i->getArgument('project-uuid')], null, null, 'Projet introuvable')); return Command::FAILURE; }
        $collection = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
        if (!$collection) { $io->error($this->trans('cli.collection_not_found', ['%slug%' => (string)$i->getArgument('collection-slug')], null, null, 'Collection introuvable')); return Command::FAILURE; }

        $collFields = $this->fields->findBy(['collection' => $collection, 'deletedAt' => null]);
        $fieldMap = [];
        foreach ($collFields as $f) { $fieldMap[$f->slug] = $f; }

        // Read CSV
        $fh = fopen($path, 'r');
        if (!$fh) { $io->error($this->trans('cli.csv_cannot_open', ['%path%' => $path], null, null, "Impossible d'ouvrir $path")); return Command::FAILURE; }
        $headers = fgetcsv($fh, 0, $i->getOption('delimiter'));
        if (!$headers) { $io->error($this->trans('cli.csv_empty_or_invalid', [], null, null, 'CSV vide ou invalide')); return Command::FAILURE; }

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
                $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
                $collection = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
                if (!$project || !$collection) {
                    $io->error($this->trans('cli.csv_reload_failed', [], null, null, 'Impossible de recharger le projet/collection après erreur'));
                    return Command::FAILURE;
                }
                $collFields = $this->fields->findBy(['collection' => $collection, 'deletedAt' => null]);
                $fieldMap = [];
                foreach ($collFields as $f) { $fieldMap[$f->slug] = $f; }
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
