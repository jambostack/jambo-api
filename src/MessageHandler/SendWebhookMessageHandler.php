<?php

namespace App\MessageHandler;

use App\Entity\WebhookLog;
use App\Message\SendWebhookMessage;
use App\Repository\WebhookRepository;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class SendWebhookMessageHandler
{
    private SsrfProtectionService $ssrfProtection;

    public function __construct(
        private WebhookRepository $webhookRepository,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private WebhookSecretService $secretService,
        ?SsrfProtectionService $ssrfProtection = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

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

        // Contrôle SSRF rigoureux (anti-loopback, RFC1918, metadata cloud, notations évasives)
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test' && !$this->ssrfProtection->isSafeUrl($webhook->url, false, $reason)) {
            $log = new WebhookLog();
            $log->webhook        = $webhook;
            $log->event          = $message->event;
            $log->requestPayload = $payloadJson;
            $log->status         = 'failed';
            $log->errorMessage   = 'Blocked outbound request to private/internal IP: ' . ($reason ?? 'SSRF blocked');
            $this->em->persist($log);
            $this->em->flush();
            return;
        }

        $log = new WebhookLog();
        $log->webhook        = $webhook;
        $log->event          = $message->event;
        $log->requestPayload = $payloadJson;

        try {
            // Options durcies : DNS pinning anti-rebinding, 0 redirect, timeout 5s, max_duration 10s
            $options = $this->ssrfProtection->buildSafeHttpClientOptions($webhook->url, [
                'headers' => $headers,
                'body'    => $payloadJson,
            ], idleTimeout: 5.0, maxDuration: 10.0);

            $response = $this->httpClient->request('POST', $webhook->url, $options);

            $statusCode = $response->getStatusCode();
            $log->statusCode   = $statusCode;
            $log->responseBody = $this->ssrfProtection->readSafeResponseBody($response, 2000);
            $log->status       = $statusCode >= 200 && $statusCode < 300 ? 'succeeded' : 'failed';
        } catch (\Throwable $e) {
            $log->status       = 'failed';
            $log->errorMessage = substr($e->getMessage(), 0, 500);
        }

        $this->em->persist($log);
        $this->em->flush();
    }
}
