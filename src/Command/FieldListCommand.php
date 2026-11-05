<?php
namespace App\Command;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:field:list', description: 'Lister les champs d\'une collection')]
class FieldListCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED)
            ->addArgument('collection-slug', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
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
        $ff = $this->fields->findBy(['collection' => $coll, 'deletedAt' => null], ['order' => 'ASC']);
        $rows = [];
        foreach ($ff as $f) $rows[] = [$f->id, $f->name, $f->slug, $f->type, $f->isRequired ? 'Oui' : 'Non'];
        $io->table(['ID', 'Nom', 'Slug', 'Type', 'Requis'], $rows);
        return Command::SUCCESS;
    }
}
