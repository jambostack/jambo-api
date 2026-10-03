<?php

namespace App\Tests\Command;

use App\Command\CreatePersonalAccessTokenCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class CreatePersonalAccessTokenCommandTest extends TestCase
{
    public function testExecuteCreatesTokenForUser(): void
    {
        $user = new User();
        $user->email = 'admin@example.com';

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'admin@example.com'])
            ->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $params = new ParameterBag(['kernel.secret' => 'test_secret_12345678901234567890123456789012']);

        $command = new CreatePersonalAccessTokenCommand($userRepo, $em, $params);
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'email' => 'admin@example.com',
            '--name' => 'Test Token',
            '--scopes' => 'schema:write,content:read',
        ]);

        $this->assertSame(0, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Personal Access Token created successfully!', $output);
        $this->assertStringContainsString('jbo_pat_', $output);
        $this->assertStringContainsString('admin@example.com', $output);
    }
}
