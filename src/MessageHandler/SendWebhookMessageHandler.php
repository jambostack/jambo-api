<?php

namespace App\MessageHandler;

use App\Message\SendWebhookMessage;
use App\Repository\WebhookRepository;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
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
        $webhook = $this->webhookRepository->find($message->webhookId);

        if ($webhook === null || !$webhook->isActive) {
            return;
        }

        $this->webhookService->send($webhook, $message->event, $message->payload);
    }
}
