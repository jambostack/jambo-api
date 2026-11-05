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

    public function testAdminPasswordRejectsCommonPasswords(): void
    {
        $res = $this->validator->validateAdminPassword('12345678', 'admin@example.com', 'Administrator');
        $this->assertFalse($res['valid']);
        $this->assertContains('Password is too common or easily guessable.', $res['errors']);

        $resAdmin = $this->validator->validateAdminPassword('admin1234', 'super@example.com', 'SuperUser');
        $this->assertFalse($resAdmin['valid']);
        $this->assertContains('Password is too common or easily guessable.', $resAdmin['errors']);
    }

    public function testAdminPasswordRejectsEmailPrefixOrName(): void
    {
        $resEmail = $this->validator->validateAdminPassword('jamboteam2026!', 'jamboteam@example.com', 'Admin');
        $this->assertFalse($resEmail['valid']);
        $this->assertContains('Password cannot contain your email prefix.', $resEmail['errors']);

        $resName = $this->validator->validateAdminPassword('AliceSuperSafe2026', 'random@example.com', 'Alice');
        $this->assertFalse($resName['valid']);
        $this->assertContains('Password cannot contain your name.', $resName['errors']);
    }

    public function testAdminPasswordComplexityRequirement(): void
    {
        $resMissingDigit = $this->validator->validateAdminPassword('LowercaseAndUppercase', null, null, true);
        $this->assertFalse($resMissingDigit['valid']);
        $this->assertContains('Password must include at least one number.', $resMissingDigit['errors']);

        $resValid = $this->validator->validateAdminPassword('ValidStrongPass2026#', 'john@example.com', 'Johnny', true);
        $this->assertTrue($resValid['valid']);
        $this->assertEmpty($resValid['errors']);
    }

    public function testRotationWorksWithAdminUser(): void
    {
        $admin = new \App\Entity\User();
        $admin->name = 'Super Admin';
        $admin->email = 'admin@example.com';
        $admin->password = '$2y$13$hashedadminpassword';

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->exactly(2))
            ->method('isPasswordValid')
            ->willReturnCallback(fn ($user, $pwd) => $pwd === 'CurrentAdminPass123');

        // Rejection when new matches current
        $resSame = $this->validator->validateRotation('CurrentAdminPass123', 'CurrentAdminPass123', $admin, $hasher);
        $this->assertFalse($resSame['valid']);
        $this->assertSame('New password must be different from current password.', $resSame['error']);
    }

    public function testPasswordLifecycleAndAgeCalculations(): void
    {
        $this->assertNull($this->validator->getPasswordAgeDays(null));
        $this->assertFalse($this->validator->isPasswordExpired(null));

        $fresh = new \DateTimeImmutable('-10 days');
        $this->assertSame(10, $this->validator->getPasswordAgeDays($fresh));
        $this->assertFalse($this->validator->isPasswordExpired($fresh, 90));

        $old = new \DateTimeImmutable('-95 days');
        $this->assertSame(95, $this->validator->getPasswordAgeDays($old));
        $this->assertTrue($this->validator->isPasswordExpired($old, 90));
    }

    public function testTranslatorReturnsLocalizedMessages(): void
    {
        $translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            if ($id === 'password.policy.min_length') {
                return 'Le mot de passe doit comporter au moins ' . ($params['%min%'] ?? '8') . ' caractères.';
            }
            if ($id === 'password.policy.current_incorrect') {
                return 'Le mot de passe actuel est incorrect.';
            }
            return $id;
        });

        $validatorWithTranslator = new PasswordPolicyValidator($translator);

        $res = $validatorWithTranslator->validate('short');
        $this->assertFalse($res['valid']);
        $this->assertContains('Le mot de passe doit comporter au moins 8 caractères.', $res['errors']);

        $user = new EndUser(new Project(), 'test@example.com');
        $user->password = '$2y$13$hashed';
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);

        $rot = $validatorWithTranslator->validateRotation('wrong', 'NewPass123!', $user, $hasher);
        $this->assertFalse($rot['valid']);
        $this->assertSame('Le mot de passe actuel est incorrect.', $rot['error']);
    }
}
