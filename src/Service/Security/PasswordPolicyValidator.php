<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\EndUser;
use App\Entity\Project;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validateur de politique de complexité, rotation et cycle de vie des mots de passe.
 * Conforme aux directives OWASP ASVS & NIST SP 800-63B :
 * - Longueur minimale configurable (défaut 8)
 * - Longueur maximale bornée (4096 octets, protection DoS hashing)
 * - Exigence de diversité de caractères activable ou stricte
 * - Blocage systématique des mots de passe faibles/usuels
 * - Vérification de la rotation de mot de passe (interdiction de réutilisation du mot de passe en cours)
 * - Contrôle de l'ancien mot de passe lors de la mise à jour par l'utilisateur
 * - Suivi du cycle de vie et expiration des identifiants d'administrateurs et membres.
 */
class PasswordPolicyValidator
{
    public const DEFAULT_MIN_LENGTH = 8;
    public const MAX_LENGTH = 4096;

    /**
     * Mots de passe extrêmement fréquents / faibles à rejeter.
     */
    public const COMMON_PASSWORDS = [
        'password', 'password1', 'password123', 'password1234', 'passw0rd', 'passw0rd1', 'passw0rd123',
        '12345678', '123456789', '1234567890', '00000000', '11111111',
        'qwerty123', 'qwertyuiop', 'azerty123', 'azertyuiop',
        'admin123', 'admin1234', 'admin12345', 'administrator', 'adminadmin', 'jamboadmin',
        'welcome1', 'welcome123', 'welcome1234', 'letmein1', 'letmein123',
        'changeme1', 'changeme123', 'iloveyou1', 'iloveyou123',
        'secret123', 'secret1234', 'root1234', 'rootadmin',
        'dragon123', 'football1', 'master123', 'monkey123',
        'sunshine1', 'princess1', 'shadow123', 'superman1', 'trustno1',
        'testing123', 'default123', 'server123', 'database1',
    ];

    public function __construct(
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($key, $params);
            if ($translated !== $key) {
                return $translated;
            }
        }

        return strtr($default !== '' ? $default : $key, $params);
    }

    /**
     * Valide un mot de passe vis-à-vis des critères de sécurité EndUser.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(string $password, ?string $email = null, ?string $username = null, ?Project $project = null): array
    {
        $errors = [];
        $securityConfig = ($project?->settings ?? [])['security'] ?? [];
        $minLength = (int) ($securityConfig['passwordMinLength'] ?? self::DEFAULT_MIN_LENGTH);
        if ($minLength < 8) {
            $minLength = 8;
        }

        $length = mb_strlen($password, '8bit');

        // 1. Longueur minimale & maximale
        if ($length < $minLength) {
            $errors[] = $this->trans('password.policy.min_length', ['%min%' => (string) $minLength], 'Password must be at least %min% characters.');
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = $this->trans('password.policy.max_length', ['%max%' => (string) self::MAX_LENGTH], 'Password must not exceed %max% characters.');
        }

        // 1b. Interdiction des caractères de contrôle ASCII
        if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
            $errors[] = $this->trans('password.policy.invalid_control_chars', [], 'Password cannot contain control characters.');
        }

        $requireComplexity = (bool) ($securityConfig['requirePasswordComplexity'] ?? false);
        $blockCommon = (bool) ($securityConfig['blockCommonPasswords'] ?? false) || $requireComplexity;
        $requireSpecial = (bool) ($securityConfig['requireSpecialChar'] ?? false);

        // 2. Contrôle des dictionnaires faibles et répétitions
        $normalized = strtolower(trim($password));
        $strippedPunct = rtrim($normalized, " !@#$%^&*()_+-=[]{}|;':\",./<>?`~");
        if ($blockCommon && (in_array($normalized, self::COMMON_PASSWORDS, true) || in_array($strippedPunct, self::COMMON_PASSWORDS, true))) {
            $errors[] = $this->trans('password.policy.too_common', [], 'Password is too common or easily guessable.');
        }

        if ($blockCommon && (preg_match('/(.)\1{3,}/', $password) || preg_match('/(?:0123|1234|2345|3456|4567|5678|6789|7890|abcd|bcde|cdef|defg|efgh|fghi|ghij|hijk|ijkl|jklm|klmn|lmno|mnop|nopq|opqr|pqrs|qrst|rstu|stuv|tuvw|uvwx|vwxy|wxyz)/i', $password))) {
            $errors[] = $this->trans('password.policy.repetitive_chars', [], 'Password cannot contain 4 or more repetitive or sequential characters.');
        }

        // 2b. Rejet du nom de projet si configuré ou pertinent
        $projectName = null;
        if ($project !== null) {
            try {
                $projectName = $project->name;
            } catch (\Error) {
                $projectName = null;
            }
        }
        if ($projectName !== null && mb_strlen($projectName) >= 4) {
            $normalizedProject = strtolower(trim($projectName));
            if (str_contains($normalized, $normalizedProject)) {
                $errors[] = $this->trans('password.policy.contains_project', [], 'Password cannot contain the project name.');
            }
        }

        // 3. Diversité de caractères
        if ($requireComplexity && $length >= $minLength) {
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = $this->trans('password.policy.require_lowercase', [], 'Password must include at least one lowercase letter.');
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = $this->trans('password.policy.require_uppercase', [], 'Password must include at least one uppercase letter.');
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = $this->trans('password.policy.require_number', [], 'Password must include at least one number.');
            }

            if ($email !== null && $email !== '') {
                $emailParts = explode('@', strtolower($email));
                $localPart = $emailParts[0] ?? '';
                if (strlen($localPart) >= 4 && str_contains($normalized, $localPart)) {
                    $errors[] = $this->trans('password.policy.contains_email', [], 'Password cannot contain your email prefix.');
                }
            }

            if ($username !== null && strlen($username) >= 4) {
                $normalizedUser = strtolower(trim($username));
                if (str_contains($normalized, $normalizedUser)) {
                    $errors[] = $this->trans('password.policy.contains_username', [], 'Password cannot contain your username.');
                }
            }
        }

        if ($requireSpecial && $length >= $minLength && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = $this->trans('password.policy.require_special', [], 'Password must include at least one special character.');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Valide le mot de passe d'un administrateur ou membre d'équipe (User).
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateAdminPassword(
        string $password,
        ?string $email = null,
        ?string $name = null,
        bool $requireComplexity = false,
        int $minLength = self::DEFAULT_MIN_LENGTH
    ): array {
        $errors = [];
        $length = mb_strlen($password, '8bit');

        if ($minLength < 8) {
            $minLength = 8;
        }

        if ($length < $minLength) {
            $errors[] = $this->trans('password.policy.min_length', ['%min%' => (string) $minLength], 'Password must be at least %min% characters.');
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = $this->trans('password.policy.max_length', ['%max%' => (string) self::MAX_LENGTH], 'Password must not exceed %max% characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
            $errors[] = $this->trans('password.policy.invalid_control_chars', [], 'Password cannot contain control characters.');
        }

        $normalized = strtolower(trim($password));
        $strippedPunct = rtrim($normalized, " !@#$%^&*()_+-=[]{}|;':\",./<>?`~");
        if (in_array($normalized, self::COMMON_PASSWORDS, true) || in_array($strippedPunct, self::COMMON_PASSWORDS, true)) {
            $errors[] = $this->trans('password.policy.too_common', [], 'Password is too common or easily guessable.');
        }

        if ($email !== null && $email !== '') {
            $emailParts = explode('@', strtolower($email));
            $localPart = $emailParts[0] ?? '';
            if (strlen($localPart) >= 4 && str_contains($normalized, $localPart)) {
                $errors[] = $this->trans('password.policy.contains_email', [], 'Password cannot contain your email prefix.');
            }
        }

        if ($name !== null && strlen($name) >= 4) {
            $normalizedName = strtolower(trim($name));
            if (str_contains($normalized, $normalizedName)) {
                $errors[] = $this->trans('password.policy.contains_name', [], 'Password cannot contain your name.');
            }
        }

        if ($requireComplexity && $length >= $minLength) {
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = $this->trans('password.policy.require_lowercase', [], 'Password must include at least one lowercase letter.');
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = $this->trans('password.policy.require_uppercase', [], 'Password must include at least one uppercase letter.');
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = $this->trans('password.policy.require_number', [], 'Password must include at least one number.');
            }
            if (preg_match('/(.)\1{3,}/', $password) || preg_match('/(?:0123|1234|2345|3456|4567|5678|6789|7890|abcd|bcde|cdef|defg|efgh|fghi|ghij|hijk|ijkl|jklm|klmn|lmno|mnop|nopq|opqr|pqrs|qrst|rstu|stuv|tuvw|uvwx|vwxy|wxyz)/i', $password)) {
                $errors[] = $this->trans('password.policy.repetitive_chars', [], 'Password cannot contain 4 or more repetitive or sequential characters.');
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Valide les règles de rotation lors d'un changement de mot de passe par l'utilisateur connecté.
     * Compatible avec User et EndUser (PasswordAuthenticatedUserInterface).
     *
     * @return array{valid: bool, error: ?string}
     */
    public function validateRotation(
        ?string $currentPassword,
        string $newPassword,
        PasswordAuthenticatedUserInterface $user,
        UserPasswordHasherInterface $hasher,
        bool $requireCurrentPassword = true
    ): array {
        $existingHash = $user->getPassword();
        if ($existingHash !== null && $existingHash !== '') {
            if ($requireCurrentPassword) {
                if ($currentPassword === null || $currentPassword === '') {
                    return ['valid' => false, 'error' => $this->trans('password.policy.current_required', [], 'Current password is required to change password.')];
                }

                if (!$hasher->isPasswordValid($user, $currentPassword)) {
                    return ['valid' => false, 'error' => $this->trans('password.policy.current_incorrect', [], 'Current password is incorrect.')];
                }
            }

            if ($hasher->isPasswordValid($user, $newPassword)) {
                return ['valid' => false, 'error' => $this->trans('password.policy.must_differ', [], 'New password must be different from current password.')];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Calcule l'ancienneté d'un mot de passe en jours.
     */
    public function getPasswordAgeDays(?\DateTimeImmutable $passwordChangedAt): ?int
    {
        if ($passwordChangedAt === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        return (int) $now->diff($passwordChangedAt)->days;
    }

    /**
     * Vérifie si un mot de passe a dépassé sa durée maximale de rétention.
     */
    public function isPasswordExpired(?\DateTimeImmutable $passwordChangedAt, int $maxAgeDays = 90): bool
    {
        $age = $this->getPasswordAgeDays($passwordChangedAt);
        if ($age === null) {
            return false;
        }

        return $age >= $maxAgeDays;
    }
}

