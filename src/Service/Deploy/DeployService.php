<?php
// src/Service/Deploy/DeployService.php
namespace App\Service\Deploy;

use App\Entity\DeployToken;
use App\Entity\User;
use App\Entity\WorkbenchProject;
use App\Repository\DeployTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class DeployService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    /** @param DeployProviderInterface[] $providers */
    public function __construct(
        private readonly string $appSecret,
        private readonly iterable $providers,
        private readonly ?DeployTokenRepository $tokenRepository = null,
        private readonly ?EntityManagerInterface $em = null,
    ) {}

    /**
     * AES-256-GCM encrypt. Returns base64(iv[12] . tag[16] . ciphertext).
     */
    public function encryptToken(string $plainToken): string
    {
        $key = substr(hash('sha256', $this->appSecret, true), 0, 32);
        $iv  = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt($plainToken, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($ciphertext === false) {
            throw new \RuntimeException('Token encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * AES-256-GCM decrypt.
     */
    public function decryptToken(string $encrypted): string
    {
        $key  = substr(hash('sha256', $this->appSecret, true), 0, 32);
        $raw  = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) < 12 + self::TAG_LENGTH) {
            throw new \RuntimeException('Malformed encrypted token');
        }

        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, self::TAG_LENGTH);
        $ciphertext = substr($raw, 12 + self::TAG_LENGTH);

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Token decryption failed — wrong key or corrupted data');
        }

        return $plain;
    }

    public function findProvider(string $id): ?DeployProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getId() === $id) return $provider;
        }
        return null;
    }

    /** @return array<string, array{label: string, connected: bool, configured: bool}> */
    public function getProvidersStatus(User $user): array
    {
        $status = [];
        foreach ($this->providers as $provider) {
            $token = $this->tokenRepository?->findForUser($user, $provider->getId());
            $status[$provider->getId()] = [
                'label'      => $provider->getLabel(),
                'connected'  => $token !== null && !$token->isExpired(),
                'configured' => $provider->isConfigured(),
            ];
        }
        return $status;
    }

    public function storeToken(User $user, string $providerId, string $plainToken, ?int $expiresIn = null): DeployToken
    {
        $existing = $this->tokenRepository?->findForUser($user, $providerId);

        $token = $existing ?? new DeployToken();
        $token->user           = $user;
        $token->provider       = $providerId;
        $token->encryptedToken = $this->encryptToken($plainToken);
        $token->updatedAt      = new \DateTimeImmutable();
        $token->expiresAt      = $expiresIn !== null
            ? new \DateTimeImmutable("+{$expiresIn} seconds")
            : null;

        $this->em?->persist($token);
        $this->em?->flush();

        return $token;
    }

    public function deployWith(string $providerId, WorkbenchProject $project, User $user): DeployResult
    {
        $provider = $this->findProvider($providerId);
        if ($provider === null) {
            return DeployResult::fail("Provider '{$providerId}' not found");
        }

        $token = $this->tokenRepository?->findForUser($user, $providerId);
        if ($token === null || $token->isExpired()) {
            return DeployResult::fail("Not connected to {$provider->getLabel()}. Please authenticate first.");
        }

        $plainToken = $this->decryptToken($token->encryptedToken);

        try {
            return $provider->deploy($project, $token, $plainToken);
        } catch (\Throwable $e) {
            return DeployResult::fail($e->getMessage());
        }
    }
}
