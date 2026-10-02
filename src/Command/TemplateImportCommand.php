<?php

namespace App\Command;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
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
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'jambo:template:import',
    description: 'Importe un starter template pré-configuré (blog, ecommerce, portfolio, saas) dans un projet',
)]
class TemplateImportCommand extends Command
{
    private const STARTERS = [
        'blog' => [
            'name' => 'Starter Blog & Editorial (Next.js / Astro)',
            'collections' => [
                [
                    'name' => 'Articles',
                    'slug' => 'articles',
                    'fields' => [
                        ['name' => 'Titre', 'slug' => 'title', 'type' => 'text', 'required' => true],
                        ['name' => 'Contenu', 'slug' => 'content', 'type' => 'rich_text', 'required' => true],
                        ['name' => 'Chapeau', 'slug' => 'excerpt', 'type' => 'text', 'required' => false],
                        ['name' => 'Date de publication', 'slug' => 'published_at', 'type' => 'datetime', 'required' => false],
                    ],
                    'samples' => [
                        ['title' => 'Bienvenue sur votre nouveau CMS Jambo', 'excerpt' => 'Découvrez la puissance du headless CMS.'],
                        ['title' => 'Guide de démarrage rapide', 'excerpt' => 'Connectez votre frontend Next.js en 2 minutes.'],
                    ],
                ],
                [
                    'name' => 'Auteurs',
                    'slug' => 'authors',
                    'fields' => [
                        ['name' => 'Nom complet', 'slug' => 'name', 'type' => 'text', 'required' => true],
                        ['name' => 'Bio', 'slug' => 'bio', 'type' => 'text', 'required' => false],
                    ],
                    'samples' => [
                        ['name' => 'Alexandre Dumas', 'bio' => 'Auteur et contributeur principal.'],
                    ],
                ],
            ],
        ],
        'ecommerce' => [
            'name' => 'Starter E-Commerce & Catalogue (Nuxt / Next)',
            'collections' => [
                [
                    'name' => 'Produits',
                    'slug' => 'products',
                    'fields' => [
                        ['name' => 'Nom du produit', 'slug' => 'name', 'type' => 'text', 'required' => true],
                        ['name' => 'Prix (€)', 'slug' => 'price', 'type' => 'number', 'required' => true],
                        ['name' => 'Description', 'slug' => 'description', 'type' => 'rich_text', 'required' => false],
                        ['name' => 'En stock', 'slug' => 'in_stock', 'type' => 'boolean', 'required' => true],
                    ],
                    'samples' => [
                        ['name' => 'Casque Audio Sans Fil Pro', 'price' => 199.99, 'in_stock' => true],
                        ['name' => 'Clavier Mécanique RGB', 'price' => 129.50, 'in_stock' => true],
                    ],
                ],
            ],
        ],
        'portfolio' => [
            'name' => 'Starter Portfolio & Showcase (Astro)',
            'collections' => [
                [
                    'name' => 'Projets',
                    'slug' => 'projects',
                    'fields' => [
                        ['name' => 'Titre', 'slug' => 'title', 'type' => 'text', 'required' => true],
                        ['name' => 'Description', 'slug' => 'description', 'type' => 'rich_text', 'required' => false],
                        ['name' => 'Lien URL', 'slug' => 'url', 'type' => 'url', 'required' => false],
                    ],
                    'samples' => [
                        ['title' => 'Refonte Portail Corporate', 'url' => 'https://example.com'],
                    ],
                ],
            ],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('template-slug', InputArgument::REQUIRED, 'Slug du template (' . implode(', ', array_keys(self::STARTERS)) . ')')
            ->addOption('project-uuid', null, InputOption::VALUE_OPTIONAL, 'UUID du projet cible (ou création si omis)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = strtolower((string) $input->getArgument('template-slug'));

        if (!isset(self::STARTERS[$slug])) {
            $io->error(sprintf('Template inconnu "%s". Choix disponibles : %s', $slug, implode(', ', array_keys(self::STARTERS))));
            return Command::FAILURE;
        }

        $templateDef = self::STARTERS[$slug];
        $projectUuid = $input->getOption('project-uuid');

        if ($projectUuid) {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
            if (!$project) {
                $io->error(sprintf('Projet avec UUID "%s" introuvable.', $projectUuid));
                return Command::FAILURE;
            }
        } else {
            $project = new Project();
            $project->name = $templateDef['name'];
            $project->description = sprintf('Starter template: %s', $slug);
            $this->em->persist($project);
        }

        $io->title(sprintf('Import du starter : %s', $templateDef['name']));

        foreach ($templateDef['collections'] as $collDef) {
            $collection = new Collection();
            $collection->project = $project;
            $collection->name = $collDef['name'];
            $collection->slug = $collDef['slug'];
            $this->em->persist($collection);

            $fieldsMap = [];
            foreach ($collDef['fields'] as $fIndex => $fDef) {
                $field = new Field();
                $field->collection = $collection;
                $field->name = $fDef['name'];
                $field->slug = $fDef['slug'];
                $field->type = $fDef['type'];
                $field->isRequired = $fDef['required'] ?? false;
                $field->order = $fIndex;
                $this->em->persist($field);
                $fieldsMap[$fDef['slug']] = $field;
            }

            // Générer les entrées d'exemple
            foreach ($collDef['samples'] as $sampleData) {
                $entry = new ContentEntry();
                $entry->project = $project;
                $entry->collection = $collection;
                $entry->status = 'published';
                $entry->slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) reset($sampleData)));
                $this->em->persist($entry);

                foreach ($sampleData as $fSlug => $val) {
                    if (isset($fieldsMap[$fSlug])) {
                        $cfv = new ContentFieldValue();
                        $cfv->contentEntry = $entry;
                        $cfv->field = $fieldsMap[$fSlug];
                        $cfv->fieldType = $fieldsMap[$fSlug]->type;

                        if (is_numeric($val)) {
                            $cfv->numberValue = (string) $val;
                        } elseif (is_bool($val)) {
                            $cfv->booleanValue = $val;
                        } else {
                            $cfv->textValue = (string) $val;
                        }
                        $this->em->persist($cfv);
                    }
                }
            }
        }

        $this->em->flush();

        $io->success(sprintf('Starter "%s" importé avec succès dans le projet %s (UUID: %s)', $templateDef['name'], $project->name, $project->uuid?->toRfc4122()));
        return Command::SUCCESS;
    }
}
