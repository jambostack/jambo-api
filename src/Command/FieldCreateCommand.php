<?php
namespace App\Command;

use App\Entity\Field;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsCommand(name: 'jambo:field:create', description: 'Ajouter un champ à une collection')]
class FieldCreateCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('collection-slug', InputArgument::REQUIRED, 'Slug de la collection')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom du champ')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Type (text,number,boolean,media,relation,textarea,email,url)', 'text')
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'Slug (généré auto si omis)')
            ->addOption('required', 'r', InputOption::VALUE_NONE, 'Champ obligatoire');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }
        $collection = $this->collections->findOneByProjectAndSlug($project, $i->getArgument('collection-slug'));
        if (!$collection) { $io->error('Collection introuvable'); return Command::FAILURE; }

        $slug = $i->getOption('slug') ?: (string)$this->slugger->slug($i->getArgument('name'))->lower();
        $field = new Field();
        $field->name = $i->getArgument('name');
        $field->slug = $slug;
        $field->type = $i->getOption('type');
        $field->collection = $collection;
        $field->isRequired = $i->getOption('required');
        $field->sortOrder = 0;
        $this->em->persist($field);
        $this->em->flush();

        $io->success("Champ '{$field->name}' ({$field->type}) ajouté !");
        return Command::SUCCESS;
    }
}
