<?php

namespace App\MessageHandler;

use App\Entity\EmailLog;
use App\Entity\Project;
use App\Message\Attachment;
use App\Message\SendProjectEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
class SendProjectEmailMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret = '',
        private readonly ?TranslatorInterface $translator = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(SendProjectEmailMessage $message): void
    {
        $project = $this->em->getRepository(Project::class)->find($message->projectId);
        if (!$project) {
            return;
        }

        // Vérification de délai d'expiration (TTL)
        if ($message->dispatchedAt > 0 && (time() - $message->dispatchedAt) > $message->ttlSeconds) {
            $this->logger->warning('Email sending skipped due to expired TTL', [
                'project_id' => $message->projectId,
                'to' => $message->to,
                'subject' => $message->subject,
            ]);
            $log = new EmailLog($project, $message->to, $message->subject);
            $log->error = $this->trans('project_mailer.error.expired', 'Email dispatch expired in queue.');
            $this->em->persist($log);
            $this->em->flush();
            return;
        }

        $password = $this->decrypt($message->encryptedPassword);

        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s&timeout=10',
            urlencode($message->username),
            urlencode($password),
            urlencode($message->host),
            $message->port,
            $message->encryption,
        );

        $to = trim($message->to);
        if ($to === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $subject = preg_replace('/[\r\n\x00-\x1F\x7F]/', ' ', trim($message->subject));

        $email = (new Email())
            ->from(sprintf('%s <%s>', $message->fromName, $message->fromEmail))
            ->to($to)
            ->subject($subject);

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
            if (is_array($attachment)) {
                $attachment = Attachment::fromArray($attachment);
            }
            if ($attachment instanceof Attachment) {
                $email->addPart(new DataPart(
                    $attachment->content,
                    $attachment->filename,
                    $attachment->mimeType,
                ));
            }
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
            throw new \RuntimeException($this->trans('project_mailer.error.password_not_configured', 'SMTP password not configured.'));
        }
        $decoded = sodium_base642bin($encrypted, SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonce   = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher  = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $key     = sodium_crypto_generichash($this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        return sodium_crypto_secretbox_open($cipher, $nonce, $key);
    }

    private function trans(string $id, string $default, array $parameters = []): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default;
    }
}

