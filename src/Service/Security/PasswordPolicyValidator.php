<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\EndUser;
use App\Entity\Project;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Validateur de politique de complexité et de rotation des mots de passe EndUser.
 * Conforme aux directives OWASP ASVS :
 * - Longueur minimale configurable (défaut 8)
 * - Longueur maximale bornée (4096 octets, protection DoS hashing)
 * - Exigence de diversité de caractères activable ou stricte
 * - Blocage des mots de passe faibles/usuels
 * - Vérification de la rotation de mot de passe (interdiction de réutilisation du mot de passe en cours)
 * - Contrôle de l'ancien mot de passe lors de la mise à jour par l'utilisateur.
 */
class PasswordPolicyValidator
{
    public const DEFAULT_MIN_LENGTH = 8;
    public const MAX_LENGTH = 4096;

    /**
     * Mots de passe extrêmement fréquents / faibles à rejeter lorsque la complexité est active.
     */
    private const COMMON_PASSWORDS = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890',
        'qwerty123', 'qwertyuiop', 'azerty123', 'admin123', 'admin1234', 'welcome1',
        'welcome123', 'letmein1', 'changeme1', 'iloveyou1', 'secret123',
    ];

    /**
     * Valide un mot de passe vis-à-vis des critères de sécurité.
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
            $errors[] = sprintf('Password must be at least %d characters.', $minLength);
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('Password must not exceed %d characters.', self::MAX_LENGTH);
        }

        // 2. Diversité de caractères : si explicitement demandée par les options du projet
        $requireComplexity = (bool) ($securityConfig['requirePasswordComplexity'] ?? false);
        if ($requireComplexity && $length >= $minLength) {
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = 'Password must include at least one lowercase letter.';
            }
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must include at least one uppercase letter.';
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must include at least one number.';
            }

            $normalized = strtolower(trim($password));
            if (in_array($normalized, self::COMMON_PASSWORDS, true)) {
                $errors[] = 'Password is too common or easily guessable.';
            }

            if ($email !== null && $email !== '') {
                $emailParts = explode('@', strtolower($email));
                $localPart = $emailParts[0] ?? '';
                if (strlen($localPart) >= 4 && str_contains($normalized, $localPart)) {
                    $errors[] = 'Password cannot contain your email prefix.';
                }
            }

            if ($username !== null && strlen($username) >= 4) {
                $normalizedUser = strtolower(trim($username));
                if (str_contains($normalized, $normalizedUser)) {
                    $errors[] = 'Password cannot contain your username.';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Valide les règles de rotation lors d'un changement de mot de passe par l'utilisateur connecté.
     *
     * @return array{valid: bool, error: ?string}
     */
    public function validateRotation(
        ?string $currentPassword,
        string $newPassword,
        EndUser $endUser,
        UserPasswordHasherInterface $hasher
    ): array {
        // Si l'utilisateur possède déjà un mot de passe
        if ($endUser->password !== null && $endUser->password !== '') {
            if ($currentPassword === null || $currentPassword === '') {
                return ['valid' => false, 'error' => 'Current password is required to change password.'];
            }

            if (!$hasher->isPasswordValid($endUser, $currentPassword)) {
                return ['valid' => false, 'error' => 'Current password is incorrect.'];
            }

            if ($hasher->isPasswordValid($endUser, $newPassword)) {
                return ['valid' => false, 'error' => 'New password must be different from current password.'];
            }
        }

        return ['valid' => true, 'error' => null];
    }
}
