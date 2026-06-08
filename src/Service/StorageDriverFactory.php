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

        return new Filesystem(new LocalFilesystemAdapter($rootPath));
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
        $nonce   = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher  = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $key     = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, $key);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key    = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
