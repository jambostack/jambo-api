<?php
namespace App\Command;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:collection:list', description: 'Lister les collections d\'un projet')]
class CollectionListCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }
    protected function configure(): void { $this->addArgument('project-uuid', InputArgument::REQUIRED); }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $projectUuid = (string) $i->getArgument('project-uuid');
        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, 'Projet introuvable'));
            return Command::FAILURE;
        }
        $cols = $this->em->getRepository(\App\Entity\Collection::class)->findBy(['project' => $project, 'deletedAt' => null]);
        $rows = [];
        foreach ($cols as $c) $rows[] = [$c->id, $c->name, $c->slug, $c->isSingleton ? 'Oui' : 'Non'];
        $io->table(['ID', 'Nom', 'Slug', 'Singleton'], $rows);
        return Command::SUCCESS;
    }
}
