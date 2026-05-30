<?php

namespace App\MessageHandler;

use App\Entity\EmailLog;
use App\Entity\Project;
use App\Message\SendProjectEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendProjectEmailMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(SendProjectEmailMessage $message): void
    {
        $project = $this->em->getRepository(Project::class)->find($message->projectId);
        if (!$project) {
            return; // projet supprimé entre-temps
        }

        // Construire le DSN dynamiquement
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s',
            urlencode($message->username),
            urlencode($message->password),
            $message->host,
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
}
