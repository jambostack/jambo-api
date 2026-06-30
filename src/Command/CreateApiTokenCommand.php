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

#[AsCommand(
    name: 'jambo:token:create',
    description: 'Create an API token for a project',
)]
class CreateApiTokenCommand extends Command
{
    public function __construct(
        private UserRepository $users,
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private ParameterBagInterface $params,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID of the project')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Email of the user (default: first admin)', '')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token name', 'CLI Token')
            ->addOption('abilities', null, InputOption::VALUE_REQUIRED, 'Comma-separated abilities (read,create,write,delete)', 'read,create,write,delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectUuid = $input->getArgument('project-uuid');
        $tokenName = $input->getOption('name');

        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error("Project with UUID '$projectUuid' not found.");
            return Command::FAILURE;
        }

        $plainToken = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name = $tokenName;
        $token->abilities = array_map('trim', explode(',', $input->getOption('abilities')));
        $token->project = $project;
        $token->tokenVersion = 2;
        $token->tokenHash = ApiToken::hashToken($plainToken, $this->params->get('kernel.secret'));

        $this->em->persist($token);
        $this->em->flush();

        $io->success("API Token created successfully!");
        $io->writeln(" Plain token: <comment>$plainToken</comment>");
        $io->writeln(" Token name:  $tokenName");
        $io->writeln(" Abilities:   " . implode(', ', $token->abilities));
        $io->writeln(" Project:     {$project->name} ({$project->uuid->toString()})");
        $io->writeln("");
        $io->writeln(" Use: curl -H 'Authorization: Bearer $plainToken' ...");

        return Command::SUCCESS;
    }
}
