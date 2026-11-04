<?php

namespace App\Tests\Command;

use App\Command\PurgeExpiredTokensCommand;
use App\Repository\ApiTokenRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectAuthAuthorizationCodeRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeExpiredTokensCommandTest extends TestCase
{
    public function testExecutePurgesAllTokenCategoriesAndSessions(): void
    {
        $resetRepo = $this->createMock(PasswordResetTokenRepository::class);
        $resetRepo->expects($this->once())->method('deleteExpired')->willReturn(5);

        $patRepo = $this->createMock(PersonalAccessTokenRepository::class);
        $patRepo->expects($this->once())->method('deleteExpired')->willReturn(3);

        $apiRepo = $this->createMock(ApiTokenRepository::class);
        $apiRepo->expects($this->once())->method('deleteExpired')->willReturn(2);

        $refreshRepo = $this->createMock(ProjectAuthRefreshTokenRepository::class);
        $refreshRepo->expects($this->once())->method('deleteExpired')->willReturn(7);

        $codeRepo = $this->createMock(ProjectAuthAuthorizationCodeRepository::class);
        $codeRepo->expects($this->once())->method('deleteExpired')->willReturn(4);

        $sessionRepo = $this->createMock(ProjectAuthSessionRepository::class);
        $sessionRepo->expects($this->once())
            ->method('deleteInactive')
            ->with($this->callback(function (\DateTimeImmutable $date) {
                // Must be roughly 15 days ago
                $expected = (new \DateTimeImmutable())->modify('-15 days');
                return abs($date->getTimestamp() - $expected->getTimestamp()) < 10;
            }))
            ->willReturn(12);

        $command = new PurgeExpiredTokensCommand(
            $resetRepo,
            $patRepo,
            $apiRepo,
            $refreshRepo,
            $codeRepo,
            $sessionRepo
        );

        $tester = new CommandTester($command);
        $status = $tester->execute(['--session-days' => '15']);

        $this->assertSame(0, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Password reset tokens purged: 5', $output);
        $this->assertStringContainsString('Personal access tokens (PAT) purged: 3', $output);
        $this->assertStringContainsString('Project API tokens purged: 2', $output);
        $this->assertStringContainsString('OAuth refresh tokens purged: 7', $output);
        $this->assertStringContainsString('OAuth authorization codes purged: 4', $output);
        $this->assertStringContainsString('Inactive sessions (> 15d) purged: 12', $output);
    }
}
