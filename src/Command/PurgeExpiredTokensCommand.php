<?php

namespace App\Command;

use App\Repository\ApiTokenRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectAuthAuthorizationCodeRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:purge-expired-tokens', description: 'Purge all expired and revoked security tokens and inactive sessions')]
class PurgeExpiredTokensCommand extends Command
{
    public function __construct(
        private readonly PasswordResetTokenRepository $resetTokenRepository,
        private readonly PersonalAccessTokenRepository $patRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly ProjectAuthRefreshTokenRepository $refreshTokenRepository,
        private readonly ProjectAuthAuthorizationCodeRepository $authCodeRepository,
        private readonly ProjectAuthSessionRepository $sessionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'session-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days of inactivity after which sessions are purged',
                '30'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate the purge and report eligible expired records without deleting them'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sessionDays = max(1, (int) $input->getOption('session-days'));
        $threshold = (new \DateTimeImmutable())->modify("-{$sessionDays} days");
        $isDryRun = (bool) $input->getOption('dry-run');

        if ($isDryRun) {
            $countReset = $this->resetTokenRepository->countExpired();
            $countPat = $this->patRepository->countExpired();
            $countApi = $this->apiTokenRepository->countExpired();
            $countRefresh = $this->refreshTokenRepository->countExpired();
            $countCodes = $this->authCodeRepository->countExpired();
            $countSessions = $this->sessionRepository->countInactive($threshold);

            $io->note('DRY-RUN MODE — Simulation only, no records were deleted.');
            $io->table(
                ['Record Category', 'Eligible Records'],
                [
                    ['Password reset tokens', $countReset],
                    ['Personal access tokens (PAT)', $countPat],
                    ['Project API tokens', $countApi],
                    ['OAuth refresh tokens (expired or revoked)', $countRefresh],
                    ['OAuth authorization codes (expired or used)', $countCodes],
                    ["Inactive sessions (> {$sessionDays}d)", $countSessions],
                ]
            );

            return Command::SUCCESS;
        }

        $purgedReset = $this->resetTokenRepository->deleteExpired();
        $purgedPat = $this->patRepository->deleteExpired();
        $purgedApi = $this->apiTokenRepository->deleteExpired();
        $purgedRefresh = $this->refreshTokenRepository->deleteExpired();
        $purgedCodes = $this->authCodeRepository->deleteExpired();
        $purgedSessions = $this->sessionRepository->deleteInactive($threshold);

        $io->success([
            'Token and session retention cleanup completed:',
            "- Password reset tokens purged: {$purgedReset}",
            "- Personal access tokens (PAT) purged: {$purgedPat}",
            "- Project API tokens purged: {$purgedApi}",
            "- OAuth refresh tokens purged: {$purgedRefresh}",
            "- OAuth authorization codes purged: {$purgedCodes}",
            "- Inactive sessions (> {$sessionDays}d) purged: {$purgedSessions}",
        ]);

        return Command::SUCCESS;
    }
}
