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
use Symfony\Component\Mime\Part\DataPart;

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
            return;
        }

        $password = $this->decrypt($message->encryptedPassword);

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
            ->subject($message->subject);

        // Body texte (toujours présent, fallback pour clients sans HTML)
        $email->text($message->body);

        // Body HTML (optionnel)
        if ($message->htmlBody !== null && $message->htmlBody !== '') {
            $email->html($message->htmlBody);
        }

        // Reply-To
        if ($message->replyTo !== null && $message->replyTo !== '') {
            $email->replyTo($message->replyTo);
        }

        // CC
        foreach ($message->cc as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $email->addCc($cc);
            }
        }

        // BCC
        foreach ($message->bcc as $bcc) {
            if (filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                $email->addBcc($bcc);
            }
        }

        // Pièces jointes
        foreach ($message->attachments as $attachment) {
            $email->addPart(new DataPart(
                $attachment->content,
                $attachment->filename,
                $attachment->mimeType,
            ));
        }

        $log = new EmailLog($project, $message->to, $message->subject);

        try {
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);
            $mailer->send($email);
        } catch (\Throwable $e) {
            $log->error = $e->getMessage();
            $this->em->persist($log);
            $this->em->flush();
            throw $e;
        }

        $this->em->persist($log);
        $this->em->flush();
    }

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
