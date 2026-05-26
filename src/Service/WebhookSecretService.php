<?php

namespace App\Service;

class WebhookSecretService
{
    private string $key;

    public function __construct(string $webhookSecretKey)
    {
        $this->key = base64_decode($webhookSecretKey);
    }

    public function encrypt(string $plainSecret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plainSecret, $nonce, $this->key);
        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encryptedSecret): string
    {
        $decoded = base64_decode($encryptedSecret);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt webhook secret.');
        }
        return $plain;
    }
}
