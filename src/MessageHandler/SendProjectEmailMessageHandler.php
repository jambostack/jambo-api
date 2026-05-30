<?php

namespace App\MessageHandler;

use App\Entity\EmailLog;
use App\Entity\Project;
use App\Message\SendProjectEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendProjectEmailMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret = '',
    ) {}

    public function __invoke(SendProjectEmailMessage $message): void
    {
        $project = $this->em->getRepository(Project::class)->find($message->projectId);
        if (!$project) {
            return; // projet supprimé entre-temps
        }

        // Déchiffrer le mot de passe SMTP (arrive chiffré du ProjectMailerService).
        $password = $this->decrypt($message->encryptedPassword);

        // Construire le DSN dynamiquement — urlencode sur toutes les parties sensibles
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s',
            urlencode($message->username),
            urlencode($password),
            urlencode($message->host),
            $message->port,
            $message->encryption,
        );

        $email = (new Email())
            ->from(sprintf('%s <%s>', $message->fromName, $message->fromEmail))
            ->to($message->to)
            ->subject($message->subject)
            ->text($message->body);

        if ($message->replyTo) {
            $email->replyTo($message->replyTo);
        }

        $log = new EmailLog($project, $message->to, $message->subject);

        try {
            // Transport dynamique : on crée un transport SMTP à la volée
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);
            $mailer->send($email);
        } catch (\Throwable $e) {
            // Logguer l'erreur pour audit
            $log->error = $e->getMessage();
            $this->em->persist($log);
            $this->em->flush();

            // Rethrow pour activer le retry Messenger (max_retries: 3)
            throw $e;
        }

        // Succès : log sans erreur
        $this->em->persist($log);
        $this->em->flush();
    }

    /** Déchiffre le mot de passe SMTP (même algorithme que ProjectMailerService). */
    private function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            throw new \RuntimeException('SMTP password not configured.');
        }
        $decoded = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonce   = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher  = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $key     = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, $key);
    }
}
