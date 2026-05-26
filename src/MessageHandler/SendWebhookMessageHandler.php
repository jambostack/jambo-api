<?php

namespace App\MessageHandler;

use App\Entity\WebhookLog;
use App\Message\SendWebhookMessage;
use App\Repository\WebhookRepository;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class SendWebhookMessageHandler
{
    public function __construct(
        private WebhookRepository $webhookRepository,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private WebhookSecretService $secretService,
    ) {}

    public function __invoke(SendWebhookMessage $message): void
    {
        $webhook = $this->webhookRepository->find($message->webhookId);

        if ($webhook === null || !$webhook->isActive) {
            return;
        }

        try {
            $payloadJson = json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            return;
        }

        $headers = [
            'Content-Type'     => 'application/json',
            'X-JamboApi-Event' => $message->event,
        ];

        if ($webhook->secret !== null) {
            $plainSecret = $this->secretService->decrypt($webhook->secret);
            $headers['X-JamboApi-Signature'] = 'sha256=' . hash_hmac('sha256', $payloadJson, $plainSecret);
        }

        $log = new WebhookLog();
        $log->webhook        = $webhook;
        $log->event          = $message->event;
        $log->requestPayload = $payloadJson;

        try {
            $response = $this->httpClient->request('POST', $webhook->url, [
                'headers' => $headers,
                'body'    => $payloadJson,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $log->statusCode   = $statusCode;
            $log->responseBody = substr($response->getContent(false), 0, 2000);
            $log->status       = $statusCode >= 200 && $statusCode < 300 ? 'succeeded' : 'failed';
        } catch (\Throwable $e) {
            $log->status       = 'failed';
            $log->errorMessage = $e->getMessage();
        }

        $this->em->persist($log);
        $this->em->flush();
    }
}
