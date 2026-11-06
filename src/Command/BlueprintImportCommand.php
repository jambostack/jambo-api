<?php

namespace App\Command;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'jambo:blueprint:import',
    description: 'Importe un fichier Blueprint JSON portable pour créer ou enrichir un projet',
)]
class BlueprintImportCommand extends Command
{
    use CliSecurityTrait;

    private const ALLOWED_FIELD_TYPES = [
        'text', 'number', 'boolean', 'media', 'relation', 'textarea',
        'email', 'url', 'rich_text', 'date', 'datetime', 'json',
        'markdown', 'enumeration', 'select', 'multiselect', 'file',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('blueprint-file', InputArgument::REQUIRED, 'Chemin vers le fichier JSON blueprint')
            ->addOption('project-uuid', null, InputOption::VALUE_OPTIONAL, 'UUID du projet cible (ou création si omis)')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Nom personnalisé du projet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('blueprint-file');

        if (!is_file($file) || !is_readable($file)) {
            $io->error($this->trans('cli.invalid_file', ['%path%' => $file], null, null, sprintf('Fichier blueprint "%s" introuvable.', $file)));
            return Command::FAILURE;
        }

        if (filesize($file) > 10 * 1024 * 1024) {
            $io->error($this->trans('cli.file_too_large', ['%max%' => '10'], null, null, 'Le fichier dépasse la taille maximale autorisée (10 Mo).'));
            return Command::FAILURE;
        }

        $content = file_get_contents($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $data = null;

        if (in_array($ext, ['yaml', 'yml'], true)) {
            try {
                $data = Yaml::parse((string) $content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (\Throwable $e) {
                $io->error($this->trans('cli.invalid_yaml', ['%error%' => $e->getMessage()], null, null, 'Format YAML invalide : ' . $e->getMessage()));
                return Command::FAILURE;
            }
        } else {
            $data = json_decode((string) $content, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                try {
                    $data = Yaml::parse((string) $content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
                } catch (\Throwable) {
                    $io->error($this->trans('cli.invalid_json', [], null, null, 'Format de blueprint invalide (clé "collections" manquante).'));
                    return Command::FAILURE;
                }
            }
        }

        if (!is_array($data) || !isset($data['collections']) || !is_array($data['collections'])) {
            $io->error($this->trans('cli.invalid_blueprint_structure', [], null, null, 'Format de blueprint invalide (clé "collections" manquante).'));
            return Command::FAILURE;
        }

        $projectUuid = $input->getOption('project-uuid');
        if ($projectUuid) {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
            if (!$project) {
                $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, sprintf('Projet cible UUID "%s" introuvable.', $projectUuid)));
                return Command::FAILURE;
            }
        } else {
            $project = new Project();
            $project->name = substr((string) ($input->getOption('name') ?? $data['project']['name'] ?? 'Projet Importé'), 0, 100);
            $project->description = 'Imported from blueprint';
            $this->em->persist($project);
        }

        $existingCollectionsCount = $project->collections->count();
        if ($existingCollectionsCount + count($data['collections']) > 100) {
            $io->error(sprintf('Le nombre total de collections dépasserait le quota maximal (100). Actuel: %d, À importer: %d', $existingCollectionsCount, count($data['collections'])));
            return Command::FAILURE;
        }

        $importedCollections = 0;
        $importedFields = 0;

        foreach ($data['collections'] as $colData) {
            if (!is_array($colData) || empty($colData['name'])) {
                continue;
            }

            $cleanColName = trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $colData['name'])));
            $collection = new Collection();
            $collection->project = $project;
            $collection->name = substr($cleanColName !== '' ? $cleanColName : 'Collection', 0, 100);
            $slug = substr(preg_replace('/[^a-z0-9_-]/i', '-', strtolower((string) ($colData['slug'] ?? $colData['name']))), 0, 100);
            $collection->slug = $slug ?: 'collection-' . ($importedCollections + 1);
            $this->em->persist($collection);
            $importedCollections++;

            $rawFields = is_array($colData['fields'] ?? null) ? array_slice($colData['fields'], 0, 100) : [];
            $seenFieldSlugs = [];

            foreach ($rawFields as $fDef) {
                if (!is_array($fDef) || empty($fDef['name'])) {
                    continue;
                }

                $rawType = strtolower(trim((string) ($fDef['type'] ?? 'text')));
                $aliasMap = [
                    'textarea'    => 'longtext',
                    'rich_text'   => 'richtext',
                    'select'      => 'enumeration',
                    'multiselect' => 'tags',
                    'file'        => 'media',
                    'int'         => 'number',
                    'integer'     => 'number',
                    'float'       => 'decimal',
                    'bool'        => 'boolean',
                    'string'      => 'text',
                ];
                if (isset($aliasMap[$rawType])) {
                    $rawType = $aliasMap[$rawType];
                }

                $type = in_array($rawType, self::ALLOWED_FIELD_TYPES, true) ? $rawType : 'text';

                $cleanFieldName = trim(strip_tags(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $fDef['name'])));
                $fSlug = substr(preg_replace('/[^a-z0-9_-]/i', '-', strtolower((string) ($fDef['slug'] ?? $fDef['name']))), 0, 100);
                if ($fSlug === '' || isset($seenFieldSlugs[$fSlug])) {
                    $fSlug = ($fSlug ?: 'field') . '-' . ($importedFields + 1);
                }
                $seenFieldSlugs[$fSlug] = true;

                $field = new Field();
                $field->collection = $collection;
                $field->name = substr($cleanFieldName !== '' ? $cleanFieldName : 'Field', 0, 100);
                $field->slug = $fSlug;
                $field->type = $type;
                $field->isRequired = (bool) ($fDef['is_required'] ?? false);
                $field->order = min(1000, max(0, (int) ($fDef['order'] ?? $fDef['sort_order'] ?? 0)));
                $this->em->persist($field);
                $importedFields++;
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'Blueprint importé avec succès dans "%s" (%d collections, %d champs créés).',
            $project->name,
            $importedCollections,
            $importedFields
        ));

        return Command::SUCCESS;
    }
}
