<?php

namespace App\Command;

use App\Repository\ApiTokenRepository;
use App\Repository\AssetUploadIntentRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectAuthAuthorizationCodeRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use App\Repository\ProjectRepository;
use App\Repository\ShareRepository;
use App\Service\DirectUploadService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:purge-expired-tokens', description: 'Purge all expired and revoked security tokens and inactive sessions')]
class PurgeExpiredTokensCommand extends Command
{
    use LockableTrait;
    use CliSecurityTrait;

    public function __construct(
        private readonly PasswordResetTokenRepository $resetTokenRepository,
        private readonly PersonalAccessTokenRepository $patRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly ProjectAuthRefreshTokenRepository $refreshTokenRepository,
        private readonly ProjectAuthAuthorizationCodeRepository $authCodeRepository,
        private readonly ProjectAuthSessionRepository $sessionRepository,
        private readonly ?ShareRepository $shareRepository = null,
        private readonly ?AssetUploadIntentRepository $intentRepository = null,
        private readonly ?DirectUploadService $directUploadService = null,
        private readonly ?ProjectRepository $projectRepo = null,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->trans(
                'cli.purge_tokens.description',
                [],
                null,
                null,
                'Purge all expired and revoked security tokens and inactive sessions according to retention policies'
            ))
            ->addOption(
                'session-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days of inactivity after which sessions are purged',
                '30'
            )
            ->addOption(
                'project',
                'p',
                InputOption::VALUE_REQUIRED,
                'Scope token and session purge to a specific project UUID, numeric ID, or exact name'
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

        if (!$this->lock()) {
            $io->comment($this->trans('cli.command_already_running', [], null, null, 'Command is already running in another process.'));
            return Command::SUCCESS;
        }

        try {
            $projectOption = $input->getOption('project');
            $project = null;

            if ($projectOption !== null && $projectOption !== '') {
                if ($this->projectRepo === null) {
                    $io->error($this->trans(
                        'cli.purge_tokens.project_not_found',
                        ['%identifier%' => (string) $projectOption],
                        null,
                        null,
                        sprintf("Project '%s' was not found.", $projectOption)
                    ));
                    return Command::FAILURE;
                }

                $project = $this->projectRepo->findByIdentifier((string) $projectOption);
                if ($project === null) {
                    $io->error($this->trans(
                        'cli.purge_tokens.project_not_found',
                        ['%identifier%' => (string) $projectOption],
                        null,
                        null,
                        sprintf("Project '%s' was not found.", $projectOption)
                    ));
                    return Command::FAILURE;
                }
            }

            $sessionDays = max(1, (int) $input->getOption('session-days'));
            $threshold = (new \DateTimeImmutable())->modify("-{$sessionDays} days");
            $isDryRun = (bool) $input->getOption('dry-run');

            if ($project !== null) {
                $io->comment($this->trans(
                    'cli.purge_tokens.scoped_to_project',
                    ['%name%' => $project->name, '%uuid%' => $project->uuid?->toRfc4122() ?? (string) $project->id],
                    null,
                    null,
                    sprintf('Scoped to project: %s (%s)', $project->name, $project->uuid?->toRfc4122())
                ));
            }

            if ($isDryRun) {
                $countReset = $project === null ? $this->resetTokenRepository->countExpired() : 0;
                $countPat = $project === null ? $this->patRepository->countExpired() : 0;
                $countApi = $this->apiTokenRepository->countExpired($project);
                $countRefresh = $this->refreshTokenRepository->countExpired($project);
                $countCodes = $this->authCodeRepository->countExpired($project);
                $countSessions = $this->sessionRepository->countInactive($threshold, $project);

                $tableRows = [];
                if ($project === null) {
                    $tableRows[] = [
                        $this->trans('cli.purge_tokens.cat_reset_tokens', [], null, null, 'Password reset tokens'),
                        $countReset,
                    ];
                    $tableRows[] = [
                        $this->trans('cli.purge_tokens.cat_pat_tokens', [], null, null, 'Personal access tokens (PAT)'),
                        $countPat,
                    ];
                }
                $tableRows[] = [
                    $this->trans('cli.purge_tokens.cat_api_tokens', [], null, null, 'Project API tokens'),
                    $countApi,
                ];
                $tableRows[] = [
                    $this->trans('cli.purge_tokens.cat_refresh_tokens', [], null, null, 'OAuth refresh tokens (expired or revoked)'),
                    $countRefresh,
                ];
                $tableRows[] = [
                    $this->trans('cli.purge_tokens.cat_auth_codes', [], null, null, 'OAuth authorization codes (expired or used)'),
                    $countCodes,
                ];
                $tableRows[] = [
                    $this->trans('cli.purge_tokens.cat_inactive_sessions', ['%days%' => $sessionDays], null, null, "Inactive sessions (> {$sessionDays}d)"),
                    $countSessions,
                ];

                if ($this->shareRepository !== null) {
                    $tableRows[] = [
                        $this->trans('cli.purge_tokens.cat_shares', [], null, null, 'Public shares (expired or revoked)'),
                        $this->shareRepository->countExpired($project),
                    ];
                }
                if ($this->intentRepository !== null) {
                    $tableRows[] = [
                        $this->trans('cli.purge_tokens.cat_expired_intents', [], null, null, 'Expired upload intents'),
                        $this->intentRepository->countExpiredPending($project),
                    ];
                    $tableRows[] = [
                        $this->trans('cli.purge_tokens.cat_old_intents', ['%days%' => $sessionDays], null, null, "Old upload intents (> {$sessionDays}d)"),
                        $this->intentRepository->countOldIntents($threshold, $project),
                    ];
                }

                $io->note($this->trans('cli.purge_tokens.dry_run_title', [], null, null, 'DRY-RUN MODE — Simulation only, no records were deleted.'));
                $io->table([
                    $this->trans('cli.purge_tokens.table_header_category', [], null, null, 'Record Category'),
                    $this->trans('cli.purge_tokens.table_header_count', [], null, null, 'Eligible Records'),
                ], $tableRows);

                return Command::SUCCESS;
            }

            $purgedReset = $project === null ? $this->resetTokenRepository->deleteExpired() : 0;
            $purgedPat = $project === null ? $this->patRepository->deleteExpired() : 0;
            $purgedApi = $this->apiTokenRepository->deleteExpired($project);
            $purgedRefresh = $this->refreshTokenRepository->deleteExpired($project);
            $purgedCodes = $this->authCodeRepository->deleteExpired($project);
            $purgedSessions = $this->sessionRepository->deleteInactive($threshold, $project);
            $purgedShares = $this->shareRepository !== null ? $this->shareRepository->purgeExpired($project) : 0;
            $cleanedIntents = $this->directUploadService !== null ? $this->directUploadService->cleanupExpiredIntents($project, 200) : 0;
            $deletedOldIntents = $this->intentRepository !== null ? $this->intentRepository->deleteOldIntents($threshold, $project) : 0;

            $messages = [
                $this->trans('cli.purge_tokens.success_title', [], null, null, 'Token and session retention cleanup completed:'),
            ];
            if ($project === null) {
                $messages[] = $this->trans('cli.purge_tokens.msg_reset_purged', ['%count%' => $purgedReset], null, null, "- Password reset tokens purged: {$purgedReset}");
                $messages[] = $this->trans('cli.purge_tokens.msg_pat_purged', ['%count%' => $purgedPat], null, null, "- Personal access tokens (PAT) purged: {$purgedPat}");
            }
            $messages[] = $this->trans('cli.purge_tokens.msg_api_purged', ['%count%' => $purgedApi], null, null, "- Project API tokens purged: {$purgedApi}");
            $messages[] = $this->trans('cli.purge_tokens.msg_refresh_purged', ['%count%' => $purgedRefresh], null, null, "- OAuth refresh tokens purged: {$purgedRefresh}");
            $messages[] = $this->trans('cli.purge_tokens.msg_codes_purged', ['%count%' => $purgedCodes], null, null, "- OAuth authorization codes purged: {$purgedCodes}");
            $messages[] = $this->trans('cli.purge_tokens.msg_sessions_purged', ['%count%' => $purgedSessions, '%days%' => $sessionDays], null, null, "- Inactive sessions (> {$sessionDays}d) purged: {$purgedSessions}");

            if ($this->shareRepository !== null) {
                $messages[] = $this->trans('cli.purge_tokens.msg_shares_purged', ['%count%' => $purgedShares], null, null, "- Public shares purged: {$purgedShares}");
            }
            if ($this->directUploadService !== null) {
                $messages[] = $this->trans('cli.purge_tokens.msg_intents_cleaned', ['%count%' => $cleanedIntents], null, null, "- Expired upload intents cleaned up: {$cleanedIntents}");
            }
            if ($this->intentRepository !== null) {
                $messages[] = $this->trans('cli.purge_tokens.msg_intents_purged', ['%count%' => $deletedOldIntents, '%days%' => $sessionDays], null, null, "- Old upload intents purged (> {$sessionDays}d): {$deletedOldIntents}");
            }

            $io->success($messages);

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}

