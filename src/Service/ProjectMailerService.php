<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Message\Attachment;
use App\Message\SendProjectEmailMessage;
use App\Repository\ProjectMailerSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

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
     * Envoie un email de manière synchrone — utilisé par le endpoint de test
     * pour valider la configuration SMTP en temps réel.
     *
     * @param string[]      $cc          Adresses en copie
     * @param string[]      $bcc         Adresses en copie cachée
     * @param Attachment[]  $attachments Pièces jointes
     *
     * @throws \RuntimeException si le mailer n'est pas configuré, désactivé,
     *                           ou si l'envoi SMTP échoue
     */
    public function sendSync(
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

        $password = $this->decrypt($settings->encryptedPassword);

        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s&timeout=10',
            urlencode($settings->username),
            urlencode($password),
            urlencode($settings->host),
            $settings->port,
            $settings->encryption,
        );

        $email = (new Email())
            ->from(sprintf('%s <%s>', $settings->fromName, $settings->fromEmail))
            ->to($to)
            ->subject($subject);

        $email->text($body);

        if ($htmlBody !== null && $htmlBody !== '') {
            $email->html($htmlBody);
        }

        if ($replyTo !== null && $replyTo !== '') {
            $email->replyTo($replyTo);
        }

        foreach ($cc as $c) {
            if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
                $email->addCc($c);
            }
        }

        foreach ($bcc as $b) {
            if (filter_var($b, FILTER_VALIDATE_EMAIL)) {
                $email->addBcc($b);
            }
        }

        foreach ($attachments as $attachment) {
            $email->addPart(new DataPart(
                $attachment->content,
                $attachment->filename,
                $attachment->mimeType,
            ));
        }

        try {
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);
            $mailer->send($email);
        } catch (\Throwable $e) {
            throw new \RuntimeException('SMTP connection failed: ' . $e->getMessage(), 0, $e);
        }
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
