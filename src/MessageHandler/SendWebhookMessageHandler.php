<?php

namespace App\MessageHandler;

use App\Message\SendWebhookMessage;
use App\Repository\WebhookRepository;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class SendWebhookMessageHandler
{
    private SsrfProtectionService $ssrfProtection;
    private WebhookService $webhookService;

    public function __construct(
        private WebhookRepository $webhookRepository,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private WebhookSecretService $secretService,
        ?SsrfProtectionService $ssrfProtection = null,
        ?WebhookService $webhookService = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
        $this->webhookService = $webhookService ?? new WebhookService(
            $this->webhookRepository,
            $this->em,
            $this->httpClient,
            $this->secretService,
            $this->ssrfProtection
        );
    }

    public function __invoke(SendWebhookMessage $message): void
    {
        // Vérification de délai d'expiration (TTL)
        if ($message->dispatchedAt > 0 && (time() - $message->dispatchedAt) > $message->ttlSeconds) {
            $this->logger->warning('Webhook delivery skipped due to expired TTL', [
                'webhook_id' => $message->webhookId,
                'event' => $message->event,
            ]);
            return;
        }

        $webhook = $this->webhookRepository->find($message->webhookId);

        if ($webhook === null || !$webhook->isActive) {
            return;
        }

        $this->webhookService->send($webhook, $message->event, $message->payload);
    }
}

