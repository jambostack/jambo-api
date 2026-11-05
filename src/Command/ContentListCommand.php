<?php
namespace App\Command;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:content:list', description: 'Lister les entrées d\'une collection')]
class ContentListCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private ContentEntryRepository $entries,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('collection-slug', InputArgument::REQUIRED, 'Slug de la collection')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre max (1-500)', '20')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filtrer par statut');
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

        $collectionSlug = (string) $i->getArgument('collection-slug');
        $coll = $this->collections->findOneByProjectAndSlug($project, $collectionSlug);
        if (!$coll) {
            $io->error($this->trans('cli.collection_not_found', ['%slug%' => $collectionSlug], null, null, 'Collection introuvable'));
            return Command::FAILURE;
        }

        $limit = min(500, max(1, (int) $i->getOption('limit')));
        $qb = $this->entries->createQueryBuilder('e')
            ->where('e.collection = :coll')->andWhere('e.deletedAt IS NULL')
            ->setParameter('coll', $coll)->setMaxResults($limit)
            ->orderBy('e.createdAt', 'DESC');

        if ($i->getOption('status')) {
            $qb->andWhere('e.status = :st')->setParameter('st', (string) $i->getOption('status'));
        }

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $e) {
            $rows[] = [$e->uuid?->toString() ?? '?', $e->slug, $e->status, $e->locale, $e->createdAt->format('Y-m-d H:i')];
        }
        $io->table(['UUID', 'Slug', 'Statut', 'Locale', 'Créé le'], $rows);
        $io->writeln(count($rows) . " entrée(s)");
        return Command::SUCCESS;
    }
}
