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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'jambo:blueprint:export',
    description: 'Exporte la structure et le schéma d\'un projet dans un fichier Blueprint JSON portable',
)]
class BlueprintExportCommand extends Command
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
            ->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID ou Slug du projet à exporter')
            ->addArgument('output-file', InputArgument::OPTIONAL, 'Chemin du fichier JSON de sortie (ou stdout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ident = (string) $input->getArgument('project-uuid');
        $outputFile = $input->getArgument('output-file');

        $project = null;
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $ident]);
        } catch (\Throwable) {
            // Pas un UUID valide
        }
        $project ??= $this->projectRepository->findOneBy(['name' => $ident]);

        if (!$project) {
            $io->error(sprintf('Projet "%s" introuvable.', $ident));
            return Command::FAILURE;
        }

        $collections = $this->em->getRepository(Collection::class)->findBy([
            'project'   => $project,
            'deletedAt' => null,
        ]);

        $blueprint = [
            'version'     => '1.0',
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'project'     => [
                'name'           => $project->name,
                'uuid'           => $project->uuid?->toRfc4122(),
                'default_locale' => $project->defaultLocale ?? 'fr',
            ],
            'collections' => [],
        ];

        foreach ($collections as $col) {
            $fields = $this->em->getRepository(Field::class)->findBy([
                'collection' => $col,
            ], ['order' => 'ASC']);

            $colData = [
                'name'   => $col->name,
                'slug'   => $col->slug,
                'fields' => [],
            ];

            foreach ($fields as $field) {
                $colData['fields'][] = [
                    'name'        => $field->name,
                    'slug'        => $field->slug,
                    'type'        => $field->type,
                    'is_required' => $field->isRequired,
                    'order'       => $field->order,
                ];
            }

            $blueprint['collections'][] = $colData;
        }

        $json = json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($outputFile) {
            file_put_contents($outputFile, $json);
            $io->success(sprintf('Blueprint exporté avec succès dans "%s" (%d collections)', $outputFile, count($collections)));
        } else {
            $output->writeln($json);
        }

        return Command::SUCCESS;
    }
}
