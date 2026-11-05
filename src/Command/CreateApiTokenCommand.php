<?php

namespace App\Command;

use App\Entity\ApiToken;
use App\Repository\UserRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'jambo:token:create',
    description: 'Create an API token for a project',
)]
class CreateApiTokenCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private UserRepository $users,
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private ParameterBagInterface $params,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID of the project')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Email of the user (default: first admin)', '')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token name', 'CLI Token')
            ->addOption('abilities', null, InputOption::VALUE_REQUIRED, 'Comma-separated abilities (read,create,write,delete)', 'read,create,write,delete')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON for automation scripts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectUuid = (string) $input->getArgument('project-uuid');
        $tokenName = substr(trim((string) $input->getOption('name')), 0, 100);

        if (!Uuid::isValid($projectUuid)) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, "Project with UUID '$projectUuid' not found."));
            return Command::FAILURE;
        }

        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, "Project with UUID '$projectUuid' not found."));
            return Command::FAILURE;
        }

        $rawAbilities = array_filter(array_map('trim', explode(',', (string) $input->getOption('abilities'))));
        $abilities = [];
        foreach (array_slice($rawAbilities, 0, 20) as $ab) {
            if (preg_match('/^[a-zA-Z0-9_\-:]+$/', $ab)) {
                $abilities[] = $ab;
            }
        }
        if (empty($abilities)) {
            $abilities = ['read'];
        }

        $plainToken = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = $tokenName;
        $token->abilities = $abilities;
        $token->project = $project;
        $token->tokenVersion = 2;
        $token->tokenHash = ApiToken::hashToken($plainToken, (string) $this->params->get('kernel.secret'));

        $this->em->persist($token);
        $this->em->flush();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'success'     => true,
                'token'       => $plainToken,
                'name'        => $tokenName,
                'abilities'   => $token->abilities,
                'projectUuid' => $project->uuid->toString(),
                'projectName' => $project->name,
            ], JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->success($this->trans('cli.token_created', [], null, null, 'API Token created successfully!'));
        $io->writeln(" Plain token: <comment>$plainToken</comment>");
        $io->writeln(" Token name:  $tokenName");
        $io->writeln(" Abilities:   " . implode(', ', $token->abilities));
        $io->writeln(" Project:     {$project->name} ({$project->uuid->toString()})");
        $io->writeln("");
        $io->writeln(" Use: curl -H 'Authorization: Bearer $plainToken' ...");

        return Command::SUCCESS;
    }
}
