<?php
namespace App\Command;
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

#[AsCommand(name: 'jambo:enduser:list', description: 'Lister les utilisateurs frontend (EndUsers) d\'un projet')]
class EndUserListCommand extends Command
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
    protected function configure(): void {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre max (1-500)', '50');
    }
    protected function execute(InputInterface $i, OutputInterface $o): int {
        $io = new SymfonyStyle($i, $o);
        $projectUuid = (string) $i->getArgument('project-uuid');
        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, 'Projet introuvable'));
            return Command::FAILURE;
        }
        $limit = min(500, max(1, (int) $i->getOption('limit')));
        $endUsers = $this->em->getRepository(\App\Entity\EndUser::class)
            ->findBy(['project' => $project], ['createdAt' => 'DESC'], $limit);
        $rows = [];
        foreach ($endUsers as $u) {
            $rows[] = [$u->uuid?->toString() ?? '?', $u->email, $u->name ?? '', $u->isActive ? 'Actif' : 'Inactif', $u->createdAt->format('Y-m-d')];
        }
        $io->table(['UUID', 'Email', 'Nom', 'Statut', 'Inscrit le'], $rows);
        $io->writeln(count($rows) . " utilisateur(s)");
        return Command::SUCCESS;
    }
}
