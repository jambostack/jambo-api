<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ApiToken;
use App\Entity\AssetUploadIntent;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthAuthorizationCode;
use App\Entity\ProjectAuthClient;
use App\Entity\ProjectAuthRefreshToken;
use App\Entity\ProjectAuthSession;
use App\Entity\Share;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cycle #84 — Multi-Tenant Token and Inactive Session Retention Purge Hardening & Strict I18n Verification.
 */
class Cycle84TokenAndSessionRetentionPurgeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->application = new Application(static::$kernel);
    }

    private function createProject(string $name): Project
    {
        $project = new Project();
        $project->name = $name . '_' . bin2hex(random_bytes(3));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function createEndUser(Project $project, string $email): EndUser
    {
        $user = new EndUser($project, $email);
        $user->passwordHash = 'hash';
        $user->isActive = true;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testDryRunOutputsEligibleCounts(): void
    {
        $command = $this->application->find('app:purge-expired-tokens');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('DRY-RUN MODE', $output);
        $this->assertStringContainsString('Record Category', $output);
        $this->assertStringContainsString('Eligible Records', $output);
        $this->assertStringContainsString('Password reset tokens', $output);
        $this->assertStringContainsString('Personal access tokens (PAT)', $output);
        $this->assertStringContainsString('Project API tokens', $output);
        $this->assertStringContainsString('OAuth refresh tokens', $output);
    }

    public function testNonExistentProjectFails(): void
    {
        $command = $this->application->find('app:purge-expired-tokens');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--project' => 'completely_nonexistent_project_xyz_' . bin2hex(random_bytes(4)),
        ]);

        $this->assertSame(1, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('was not found', $output);
    }

    public function testTenantScopedDryRunAndPurgeIsolation(): void
    {
        $projectA = $this->createProject('Project_A_C84');
        $projectB = $this->createProject('Project_B_C84');

        $endUserA = $this->createEndUser($projectA, 'userA_' . bin2hex(random_bytes(3)) . '@test.com');
        $endUserB = $this->createEndUser($projectB, 'userB_' . bin2hex(random_bytes(3)) . '@test.com');

        // Create expired API tokens for both projects
        $tokenA = new ApiToken();
        $tokenA->project = $projectA;
        $tokenA->name = 'Expired Token A';
        $tokenA->tokenHash = hash('sha256', 'token_plain_a_' . bin2hex(random_bytes(4)));
        $tokenA->expiresAt = (new \DateTimeImmutable())->modify('-5 days');
        $this->em->persist($tokenA);

        $tokenB = new ApiToken();
        $tokenB->project = $projectB;
        $tokenB->name = 'Expired Token B';
        $tokenB->tokenHash = hash('sha256', 'token_plain_b_' . bin2hex(random_bytes(4)));
        $tokenB->expiresAt = (new \DateTimeImmutable())->modify('-5 days');
        $this->em->persist($tokenB);

        // Create expired OAuth Refresh Token for EndUser A & B
        $refreshA = new ProjectAuthRefreshToken();
        $refreshA->endUser = $endUserA;
        $refreshA->tokenHash = hash('sha256', 'refresh_plain_a_' . bin2hex(random_bytes(4)));
        $refreshA->expiresAt = (new \DateTimeImmutable())->modify('-2 days');
        $this->em->persist($refreshA);

        $refreshB = new ProjectAuthRefreshToken();
        $refreshB->endUser = $endUserB;
        $refreshB->tokenHash = hash('sha256', 'refresh_plain_b_' . bin2hex(random_bytes(4)));
        $refreshB->expiresAt = (new \DateTimeImmutable())->modify('-2 days');
        $this->em->persist($refreshB);

        // Create inactive session for EndUser A & B
        $sessionA = new ProjectAuthSession();
        $sessionA->endUser = $endUserA;
        $sessionA->lastActiveAt = (new \DateTimeImmutable())->modify('-45 days');
        $this->em->persist($sessionA);

        $sessionB = new ProjectAuthSession();
        $sessionB->endUser = $endUserB;
        $sessionB->lastActiveAt = (new \DateTimeImmutable())->modify('-45 days');
        $this->em->persist($sessionB);

        $this->em->flush();

        $tokenAId = $tokenA->id;
        $tokenBId = $tokenB->id;
        $refreshAId = $refreshA->id;
        $refreshBId = $refreshB->id;
        $sessionAId = $sessionA->id;
        $sessionBId = $sessionB->id;

        $command = $this->application->find('app:purge-expired-tokens');
        $tester = new CommandTester($command);

        // Execute purge strictly scoped to Project A
        $exitCode = $tester->execute([
            '--project'      => $projectA->uuid->toRfc4122(),
            '--session-days' => '30',
        ]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Scoped to project', $output);
        $this->assertStringContainsString('Project API tokens purged', $output);
        $this->assertStringContainsString('OAuth refresh tokens purged', $output);

        // Clear EM to verify DB state
        $this->em->clear();

        // Project A expired tokens and sessions MUST be deleted
        $this->assertNull($this->em->find(ApiToken::class, $tokenAId));
        $this->assertNull($this->em->find(ProjectAuthRefreshToken::class, $refreshAId));
        $this->assertNull($this->em->find(ProjectAuthSession::class, $sessionAId));

        // Project B tokens and sessions MUST remain intact
        $this->assertNotNull($this->em->find(ApiToken::class, $tokenBId));
        $this->assertNotNull($this->em->find(ProjectAuthRefreshToken::class, $refreshBId));
        $this->assertNotNull($this->em->find(ProjectAuthSession::class, $sessionBId));
    }

    public function testCycle84TranslationsParityAcrossAllLocales(): void
    {
        $requiredKeys = [
            'cli.purge_tokens.description',
            'cli.purge_tokens.opt_session_days',
            'cli.purge_tokens.opt_dry_run',
            'cli.purge_tokens.opt_project',
            'cli.purge_tokens.project_not_found',
            'cli.purge_tokens.scoped_to_project',
            'cli.purge_tokens.dry_run_title',
            'cli.purge_tokens.table_header_category',
            'cli.purge_tokens.table_header_count',
            'cli.purge_tokens.cat_reset_tokens',
            'cli.purge_tokens.cat_pat_tokens',
            'cli.purge_tokens.cat_api_tokens',
            'cli.purge_tokens.cat_refresh_tokens',
            'cli.purge_tokens.cat_auth_codes',
            'cli.purge_tokens.cat_inactive_sessions',
            'cli.purge_tokens.cat_shares',
            'cli.purge_tokens.cat_expired_intents',
            'cli.purge_tokens.cat_old_intents',
            'cli.purge_tokens.success_title',
            'cli.purge_tokens.msg_reset_purged',
            'cli.purge_tokens.msg_pat_purged',
            'cli.purge_tokens.msg_api_purged',
            'cli.purge_tokens.msg_refresh_purged',
            'cli.purge_tokens.msg_codes_purged',
            'cli.purge_tokens.msg_sessions_purged',
            'cli.purge_tokens.msg_shares_purged',
            'cli.purge_tokens.msg_intents_cleaned',
            'cli.purge_tokens.msg_intents_purged',
        ];

        $projectDir = static::getContainer()->getParameter('kernel.project_dir');

        foreach (['en', 'fr', 'es', 'ar'] as $locale) {
            $path = $projectDir . '/translations/messages.' . $locale . '.php';
            $this->assertFileExists($path);
            $messages = include $path;
            $this->assertIsArray($messages);

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $messages, sprintf('Key "%s" missing in locale "%s"', $key, $locale));
                $this->assertNotEmpty($messages[$key], sprintf('Key "%s" is empty in locale "%s"', $key, $locale));
            }
        }
    }
}
