<?php

namespace App\Service;

use App\Entity\ProjectStorageProfile;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

class StorageDriverFactory
{
    public function __construct(
        private readonly string $appSecret,
        private readonly string $projectDir,
    ) {}

    /** Construit le FilesystemOperator pour un profil donné. */
    public function create(ProjectStorageProfile $profile): FilesystemOperator
    {
        return match ($profile->driver) {
            'local' => $this->createLocal($profile),
            's3'    => $this->createS3($profile),
            default => throw new \InvalidArgumentException("Unknown storage driver: {$profile->driver}"),
        };
    }

    private function createLocal(ProjectStorageProfile $profile): FilesystemOperator
    {
        $rootPath = $profile->rootPath
            ?? $this->projectDir . '/public/uploads/media/' . $profile->project->uuid;

        // Comparaison normalisée en slashes « / » : sur Windows, realpath() renvoie des
        // antislashes alors que $allowedBase contient le littéral « /public/uploads/media/ »,
        // ce qui ferait échouer str_starts_with() pour un chemin pourtant légitime.
        $allowedBase = str_replace('\\', '/', $this->projectDir . '/public/uploads/media/');
        $resolved = realpath($rootPath);
        if ($resolved === false) {
            // Dossier n'existe pas encore — on vérifie que le chemin normalisé
            // ne sort pas du répertoire autorisé
            $normalized = str_replace('\\', '/', $rootPath);
            if (str_contains($normalized, '..') || !str_starts_with($normalized, $allowedBase)) {
                throw new \RuntimeException('Local storage rootPath must be within the allowed uploads directory.');
            }
        } elseif (!str_starts_with(str_replace('\\', '/', $resolved), $allowedBase)) {
            throw new \RuntimeException('Local storage rootPath must be within the allowed uploads directory.');
        }

        return new Filesystem(new LocalFilesystemAdapter($rootPath));
    }

    /**
     * Valide qu'un endpoint S3 ne pointe pas vers une IP privée/interne (SSRF).
     */
    private function validateS3Endpoint(string $endpoint): void
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if ($host === null || $host === false) {
            throw new \RuntimeException("Invalid S3 endpoint URL: could not parse host from \"$endpoint\". Use a valid URL like \"https://xxx.r2.cloudflarestorage.com\".");
        }
        $ip = gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \RuntimeException("S3 endpoint \"$endpoint\" resolves to a private/internal IP — rejected for security.");
        }
    }

    private function createS3(ProjectStorageProfile $profile): FilesystemOperator
    {
        $secret = $this->decrypt($profile->s3Secret ?? '');

        $clientConfig = [
            'version'     => 'latest',
            'region'      => $profile->s3Region,
            'credentials' => [
                'key'    => $profile->s3Key,
                'secret' => $secret,
            ],
        ];

        if ($profile->s3Endpoint !== null && $profile->s3Endpoint !== '') {
            $this->validateS3Endpoint($profile->s3Endpoint);
            $clientConfig['endpoint'] = $profile->s3Endpoint;
        }

        if ($profile->s3UsePathStyle) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        $client = new S3Client($clientConfig);

        return new Filesystem(new AwsS3V3Adapter(
            $client,
            $profile->s3Bucket,
            visibilityConverter: new PortableVisibilityConverter(),
        ));
    }

    // ─── Chiffrement sodium (identique au SMTP) ──────────────────────────

    private function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            throw new \RuntimeException('S3 secret not configured.');
        }
        $decoded = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
        if ($decoded === '') {
            throw new \RuntimeException('Failed to decode S3 secret — ciphertext may be corrupted.');
        }
        $nonce   = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher  = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $key     = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt S3 secret — ciphertext may be corrupted or APP_SECRET changed.');
        }
        return $plaintext;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key    = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
