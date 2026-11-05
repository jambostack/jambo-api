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
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:collection:create', description: 'Créer une collection dans un projet')]
class CollectionCreateCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
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
            ->addArgument('name', InputArgument::REQUIRED, 'Nom de la collection')
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'Slug (généré auto si omis)')
            ->addOption('singleton', null, InputOption::VALUE_NONE, 'Collection à enregistrement unique');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $projectUuid = (string) $i->getArgument('project-uuid');
        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, 'Projet introuvable'));
            return Command::FAILURE;
        }

        $name = (string) $i->getArgument('name');
        $rawSlug = $i->getOption('slug') ?: (string) $this->slugger->slug($name)->lower();
        $slug = substr(preg_replace('/[^a-z0-9_-]/i', '-', strtolower($rawSlug)), 0, 100);

        $existing = $this->em->getRepository(Collection::class)->findOneBy([
            'project' => $project,
            'slug' => $slug,
            'deletedAt' => null,
        ]);
        if ($existing) {
            $io->error($this->trans('cli.collection_already_exists', ['%slug%' => $slug], null, null, "Une collection avec le slug '$slug' existe déjà dans ce projet."));
            return Command::FAILURE;
        }

        $coll = new Collection();
        $coll->name = substr($name, 0, 100);
        $coll->slug = $slug;
        $coll->project = $project;
        $coll->isSingleton = (bool) $i->getOption('singleton');
        $this->em->persist($coll);
        $this->em->flush();

        $io->success("Collection '{$coll->name}' créée !");
        $io->writeln("Slug: $slug");
        return Command::SUCCESS;
    }
}
