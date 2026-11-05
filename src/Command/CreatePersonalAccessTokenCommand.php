<?php

namespace App\Command;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'jambo:pat:create',
    description: 'Create an Admin Personal Access Token (PAT) for a user',
    aliases: ['jambo:token:pat'],
)]
class CreatePersonalAccessTokenCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private UserRepository $users,
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
            ->addArgument('email', InputArgument::OPTIONAL, 'Email of the user (default: first user found)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token name', 'CLI Admin PAT')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Comma-separated scopes', 'schema:write,projects:write,content:read,content:write')
            ->addOption('expires-in', null, InputOption::VALUE_REQUIRED, 'Expiry string relative to now (e.g. "+30 days", "never")', 'never')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON for automation scripts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $name = substr(trim((string) $input->getOption('name')), 0, 100);

        if ($email) {
            $user = $this->users->findOneBy(['email' => $email]);
        } else {
            $user = $this->users->findOneBy([], ['id' => 'ASC']);
        }

        if (!$user) {
            $io->error($email ? "User with email '$email' not found." : "No users found in database.");
            return Command::FAILURE;
        }

        $expiresOption = $input->getOption('expires-in');
        $expiresAt = null;
        if ($expiresOption && strtolower($expiresOption) !== 'never') {
            try {
                $expiresAt = new \DateTimeImmutable($expiresOption);
            } catch (\Exception $e) {
                $io->error("Invalid expires-in value '$expiresOption': " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $rawScopes = array_filter(array_map('trim', explode(',', (string) $input->getOption('scopes'))));
        $scopes = [];
        foreach (array_slice($rawScopes, 0, 20) as $s) {
            if (preg_match('/^[a-zA-Z0-9_\-:]+$/', $s)) {
                $scopes[] = $s;
            }
        }
        if ($scopes === []) {
            $scopes = ['schema:write'];
        }

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $secret = (string) $this->params->get('kernel.secret');

        $token = new PersonalAccessToken();
        $token->name = $name;
        $token->user = $user;
        $token->scopes = $scopes;
        $token->expiresAt = $expiresAt;
        $token->tokenVersion = 2;
        $token->tokenHash = ApiToken::hashToken($plain, $secret);

        $this->em->persist($token);
        $this->em->flush();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'success'   => true,
                'token'     => $plain,
                'name'      => $name,
                'user'      => $user->email,
                'scopes'    => $token->scopes,
                'expiresAt' => $expiresAt ? $expiresAt->format(\DateTimeInterface::ATOM) : null,
            ], JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->success("Personal Access Token created successfully!");
        $io->writeln(" Plain Token: <comment>$plain</comment>");
        $io->writeln(" Token Name:  $name");
        $io->writeln(" User:        {$user->email}");
        $io->writeln(" Scopes:      " . implode(', ', $token->scopes));
        $io->writeln(" Expires:     " . ($expiresAt ? $expiresAt->format(\DateTimeInterface::ATOM) : 'Never'));
        $io->writeln("");
        $io->writeln(" Use header: Authorization: Bearer $plain");

        return Command::SUCCESS;
    }
}
