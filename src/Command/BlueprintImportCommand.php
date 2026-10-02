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

#[AsCommand(
    name: 'jambo:blueprint:import',
    description: 'Importe un fichier Blueprint JSON portable pour créer ou enrichir un projet',
)]
class BlueprintImportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
    ) {
        parent::__construct();
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

        if (!file_exists($file)) {
            $io->error(sprintf('Fichier blueprint "%s" introuvable.', $file));
            return Command::FAILURE;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || !isset($data['collections'])) {
            $io->error('Format de blueprint invalide (clé "collections" manquante).');
            return Command::FAILURE;
        }

        $projectUuid = $input->getOption('project-uuid');
        if ($projectUuid) {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
            if (!$project) {
                $io->error(sprintf('Projet cible UUID "%s" introuvable.', $projectUuid));
                return Command::FAILURE;
            }
        } else {
            $project = new Project();
            $project->name = $input->getOption('name') ?? $data['project']['name'] ?? 'Projet Importé';
            $project->description = 'Imported from blueprint';
            $this->em->persist($project);
        }

        $importedCollections = 0;
        $importedFields = 0;

        foreach ($data['collections'] as $colData) {
            $collection = new Collection();
            $collection->project = $project;
            $collection->name = $colData['name'];
            $collection->slug = $colData['slug'];
            $this->em->persist($collection);
            $importedCollections++;

            foreach ($colData['fields'] ?? [] as $fDef) {
                $field = new Field();
                $field->collection = $collection;
                $field->name = $fDef['name'];
                $field->slug = $fDef['slug'];
                $field->type = $fDef['type'];
                $field->isRequired = $fDef['is_required'] ?? false;
                $field->order = $fDef['order'] ?? $fDef['sort_order'] ?? 0;
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
