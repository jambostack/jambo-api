<?php
namespace App\Service;

use OTPHP\TOTP;

class TwoFactorService
{
    private const BACKUP_CODE_COUNT = 8;
    private const BACKUP_CODE_BYTES = 4;

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
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }
        $now = time();
        $totp = TOTP::create($secret, 30, 'sha1', 6);
        return $totp->verify($code, $now, 1);
    }

    /** Génère 8 codes de secours (format XXXX-XXXX-XXXX-XXXX) */
    public function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $raw = bin2hex(random_bytes(self::BACKUP_CODE_BYTES));
            $formatted = implode('-', str_split(strtoupper($raw), 4));
            $codes[] = [
                'hash' => hash('sha256', $formatted),
                'used' => false,
            ];
        }
        return $codes;
    }

    /** Vérifie et consomme un code de secours. Retourne true si valide, false sinon. */
    public function verifyAndConsumeBackupCode(array &$storedCodes, string $code): bool
    {
        $clean = strtoupper(str_replace('-', '', $code));
        $formatted = implode('-', str_split($clean, 4));
        $hash = hash('sha256', $formatted);

        foreach ($storedCodes as &$entry) {
            if ($entry['hash'] === $hash && !$entry['used']) {
                $entry['used'] = true;
                return true;
            }
        }
        return false;
    }

    /** Formate les codes pour affichage (uniquement si jamais montrés) */
    public function formatBackupCodesForDisplay(array $codes): array
    {
        return array_map(fn ($c) => $c['used'] ? 'USED' : '****-****-****-****', $codes);
    }
}
