<?php
namespace App\Service;

use OTPHP\TOTP;

class TwoFactorService
{
    private const BACKUP_CODE_COUNT = 8;
    private const BACKUP_CODE_BYTES = 4;
    public const MAX_CODE_LENGTH = 64;

    /** Génère un secret TOTP en base32 (26 caractères) */
    public function generateSecret(): string
    {
        $totp = TOTP::create();
        return $totp->getSecret();
    }

    /** Retourne l'URI otpauth:// pour QR code et saisie manuelle */
    public function getProvisioningUri(string $secret, string $email, string $issuer = 'JamboAPI'): string
    {
        $totp = TOTP::create($secret, 30, 'sha1', 6);
        $totp->setLabel($email);
        $totp->setIssuer($issuer);
        return $totp->getProvisioningUri();
    }

    /** Vérifie un code TOTP avec fenêtre ±1 période (tolérance décalage horaire) */
    public function verifyTotp(string $secret, string $code): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code) || $secret === '') {
            return false;
        }
        try {
            $now = time();
            $totp = TOTP::create($secret, 30, 'sha1', 6);
            return $totp->verify($code, $now, 1);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Génère 8 codes de secours (format XXXX-XXXX).
     *  @return array{hashes: array, plaintext: string[]} — hashes for DB storage, plaintext for one-time display */
    public function generateBackupCodes(): array
    {
        $hashes = [];
        $plaintext = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $raw = bin2hex(random_bytes(self::BACKUP_CODE_BYTES));
            $formatted = implode('-', str_split(strtoupper($raw), 4));
            $hashes[] = [
                'hash' => hash('sha256', $formatted),
                'used' => false,
            ];
            $plaintext[] = $formatted;
        }
        return ['hashes' => $hashes, 'plaintext' => $plaintext];
    }

    /** Vérifie et consomme un code de secours en temps constant (résistant aux attaques temporelles). Retourne true si valide, false sinon. */
    public function verifyAndConsumeBackupCode(array &$storedCodes, string $code): bool
    {
        if (strlen($code) > self::MAX_CODE_LENGTH) {
            return false;
        }

        $clean = strtoupper(str_replace(['-', ' '], '', $code));
        if ($clean === '' || !ctype_alnum($clean)) {
            return false;
        }

        $formatted = implode('-', str_split($clean, 4));
        $hash = hash('sha256', $formatted);

        $matchedKey = null;
        foreach ($storedCodes as $k => $entry) {
            if (!is_array($entry) || empty($entry['hash']) || !is_string($entry['hash'])) {
                continue;
            }
            $isMatch = hash_equals($entry['hash'], $hash);
            if ($isMatch && empty($entry['used']) && $matchedKey === null) {
                $matchedKey = $k;
            }
        }

        if ($matchedKey !== null) {
            $storedCodes[$matchedKey]['used'] = true;
            return true;
        }

        return false;
    }

    /** Vérification timing-safe d'un code email */
    public function verifyEmailCode(?string $storedCode, int $expiresAt, string $code): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }
        if ($storedCode === null || $storedCode === '' || time() > $expiresAt) {
            return false;
        }
        return hash_equals($storedCode, $code);
    }

    /** Vérification timing-safe d'un hash de code email (ex: stocké dans un JWT éphémère) */
    public function verifyEmailHash(?string $storedHash, string $code): bool
    {
        if (strlen($code) > self::MAX_CODE_LENGTH || $code === '') {
            return false;
        }
        if ($storedHash === null || $storedHash === '') {
            return false;
        }
        return hash_equals($storedHash, hash('sha256', $code));
    }

    /** Formate les codes pour affichage (uniquement si jamais montrés) */
    public function formatBackupCodesForDisplay(array $codes): array
    {
        return array_map(fn ($c) => (is_array($c) && !empty($c['used'])) ? 'USED' : '****-****', $codes);
    }
}
