<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Message\Attachment;
use App\Message\SendProjectEmailMessage;
use App\Repository\ProjectMailerSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ProjectMailerService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ProjectMailerSettingsRepository $settingsRepository,
        private readonly string $appSecret,
    ) {}

    /**
     * Envoie un email via le SMTP configuré pour le projet, de manière asynchrone.
     *
     * @param string[]      $cc          Adresses en copie
     * @param string[]      $bcc         Adresses en copie cachée
     * @param Attachment[]  $attachments Pièces jointes
     *
     * @throws \RuntimeException si le mailer n'est pas configuré ou désactivé
     */
    public function send(
        Project $project,
        string $to,
        string $subject,
        string $body,
        ?string $htmlBody = null,
        ?string $replyTo = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
    ): void {
        $settings = $this->getSettings($project);
        if ($settings === null || !$settings->enabled) {
            throw new \RuntimeException('Mailer not configured or disabled for this project.');
        }

        $this->bus->dispatch(new SendProjectEmailMessage(
            host: $settings->host,
            port: $settings->port,
            username: $settings->username,
            encryptedPassword: $settings->encryptedPassword,
            encryption: $settings->encryption,
            fromEmail: $settings->fromEmail,
            fromName: $settings->fromName,
            to: $to,
            subject: $subject,
            body: $body,
            htmlBody: $htmlBody,
            replyTo: $replyTo,
            cc: $cc,
            bcc: $bcc,
            attachments: $attachments,
            projectId: $project->id,
        ));
    }

    /**
     * Chiffre le mot de passe SMTP avec XSalsa20-Poly1305 (secretbox).
     */
    public function encryptPassword(string $plaintext): string
    {
        return $this->encrypt($plaintext);
    }

    /**
     * Déchiffre le mot de passe SMTP.
     */
    public function decryptPassword(string $encrypted): string
    {
        return $this->decrypt($encrypted);
    }

    public function getSettings(Project $project): ?ProjectMailerSettings
    {
        return $this->settingsRepository->findByProject($project);
    }

    /**
     * Déchiffre une valeur chiffrée avec XSalsa20-Poly1305 (secretbox).
     */
    private function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            throw new \RuntimeException('SMTP password not configured.');
        }

        $decoded = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $key = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, $key);
    }

    /**
     * Chiffre une valeur avec XSalsa20-Poly1305 (secretbox).
     */
    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
