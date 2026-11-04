<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Service\Security\PasswordPolicyValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PasswordPolicyValidatorTest extends TestCase
{
    private PasswordPolicyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PasswordPolicyValidator();
    }

    public function testPasswordTooShortReturnsError(): void
    {
        $result = $this->validator->validate('short');
        $this->assertFalse($result['valid']);
        $this->assertContains('Password must be at least 8 characters.', $result['errors']);
    }

    public function testPasswordLengthWithinBounds(): void
    {
        $result = $this->validator->validate('validpassword123');
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testPasswordTooLongReturnsError(): void
    {
        $longPassword = str_repeat('a', 4097);
        $result = $this->validator->validate($longPassword);
        $this->assertFalse($result['valid']);
        $this->assertContains('Password must not exceed 4096 characters.', $result['errors']);
    }

    public function testComplexityRequirementsWhenEnabled(): void
    {
        $project = new Project();
        $project->settings = [
            'security' => [
                'requirePasswordComplexity' => true,
            ],
        ];

        // All lowercase
        $resLower = $this->validator->validate('alllowercaseonly', null, null, $project);
        $this->assertFalse($resLower['valid']);

        // Missing digit
        $resNoDigit = $this->validator->validate('NoDigitHerePassword', null, null, $project);
        $this->assertFalse($resNoDigit['valid']);

        // Common password
        $resCommon = $this->validator->validate('Password123', null, null, $project);
        $this->assertFalse($resCommon['valid']);

        // Compliant password
        $resGood = $this->validator->validate('Strong&Complex99', 'user@example.com', 'username', $project);
        $this->assertTrue($resGood['valid']);
        $this->assertEmpty($resGood['errors']);
    }

    public function testRotationValidationRequiresCurrentPassword(): void
    {
        $project = new Project();
        $endUser = new EndUser($project, 'alice@example.com');
        $endUser->password = '$2y$13$hashedpassword';

        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        // Missing current password
        $res = $this->validator->validateRotation(null, 'NewStrongPwd1', $endUser, $hasher);
        $this->assertFalse($res['valid']);
        $this->assertSame('Current password is required to change password.', $res['error']);
    }

    public function testRotationValidationRejectsIncorrectCurrentPassword(): void
    {
        $project = new Project();
        $endUser = new EndUser($project, 'alice@example.com');
        $endUser->password = '$2y$13$hashedpassword';

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->once())
            ->method('isPasswordValid')
            ->with($endUser, 'WrongCurrentPwd')
            ->willReturn(false);

        $res = $this->validator->validateRotation('WrongCurrentPwd', 'NewStrongPwd1', $endUser, $hasher);
        $this->assertFalse($res['valid']);
        $this->assertSame('Current password is incorrect.', $res['error']);
    }

    public function testRotationValidationRejectsIdenticalPassword(): void
    {
        $project = new Project();
        $endUser = new EndUser($project, 'alice@example.com');
        $endUser->password = '$2y$13$hashedpassword';

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->exactly(2))
            ->method('isPasswordValid')
            ->willReturnCallback(function ($user, $pwd) {
                return $pwd === 'CurrentPwd1';
            });

        $res = $this->validator->validateRotation('CurrentPwd1', 'CurrentPwd1', $endUser, $hasher);
        $this->assertFalse($res['valid']);
        $this->assertSame('New password must be different from current password.', $res['error']);
    }

    public function testRotationValidationPassesWhenValid(): void
    {
        $project = new Project();
        $endUser = new EndUser($project, 'alice@example.com');
        $endUser->password = '$2y$13$hashedpassword';

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->exactly(2))
            ->method('isPasswordValid')
            ->willReturnCallback(function ($user, $pwd) {
                return $pwd === 'CurrentPwd1';
            });

        $res = $this->validator->validateRotation('CurrentPwd1', 'CompletelyNewPwd2', $endUser, $hasher);
        $this->assertTrue($res['valid']);
        $this->assertNull($res['error']);
    }
}
