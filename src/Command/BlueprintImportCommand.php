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
        $data = json_decode((string) $content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !isset($data['collections'])) {
            $io->error($this->trans('cli.invalid_json', [], null, null, 'Format de blueprint invalide (clé "collections" manquante).'));
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

        $importedCollections = 0;
        $importedFields = 0;

        foreach ($data['collections'] as $colData) {
            if (!is_array($colData) || empty($colData['name'])) {
                continue;
            }

            $collection = new Collection();
            $collection->project = $project;
            $collection->name = substr((string) $colData['name'], 0, 100);
            $slug = substr(preg_replace('/[^a-z0-9_-]/i', '-', strtolower((string) ($colData['slug'] ?? $colData['name']))), 0, 100);
            $collection->slug = $slug;
            $this->em->persist($collection);
            $importedCollections++;

            foreach ($colData['fields'] ?? [] as $fDef) {
                if (!is_array($fDef) || empty($fDef['name'])) {
                    continue;
                }

                $rawType = strtolower((string) ($fDef['type'] ?? 'text'));
                $type = in_array($rawType, self::ALLOWED_FIELD_TYPES, true) ? $rawType : 'text';

                $field = new Field();
                $field->collection = $collection;
                $field->name = substr((string) $fDef['name'], 0, 100);
                $field->slug = substr(preg_replace('/[^a-z0-9_-]/i', '-', strtolower((string) ($fDef['slug'] ?? $fDef['name']))), 0, 100);
                $field->type = $type;
                $field->isRequired = (bool) ($fDef['is_required'] ?? false);
                $field->order = (int) ($fDef['order'] ?? $fDef['sort_order'] ?? 0);
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
