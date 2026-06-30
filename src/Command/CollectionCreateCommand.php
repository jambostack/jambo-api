<?php
namespace App\Command;

use App\Entity\Collection;
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

#[AsCommand(name: 'jambo:collection:create', description: 'Créer une collection dans un projet')]
class CollectionCreateCommand extends Command
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom de la collection')
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'Slug (généré auto si omis)')
            ->addOption('singleton', null, InputOption::VALUE_NONE, 'Collection à enregistrement unique');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $project = $this->projects->findOneBy(['uuid' => $i->getArgument('project-uuid')]);
        if (!$project) { $io->error('Projet introuvable'); return Command::FAILURE; }

        $slug = $i->getOption('slug') ?: (string)$this->slugger->slug($i->getArgument('name'))->lower();
        $coll = new Collection();
        $coll->name = $i->getArgument('name');
        $coll->slug = $slug;
        $coll->project = $project;
        $coll->isSingleton = $i->getOption('singleton');
        $this->em->persist($coll);
        $this->em->flush();

        $io->success("Collection '{$coll->name}' créée !");
        $io->writeln("Slug: $slug");
        return Command::SUCCESS;
    }
}
