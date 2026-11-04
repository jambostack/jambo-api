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

        $parts = parse_url($webhook->url);
        $host = strtolower($parts['host'] ?? '');
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        if ($appEnv !== 'test') {
            $isPrivate = false;
            if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.lan')) {
                $isPrivate = true;
            } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
                if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $isPrivate = true;
                }
            } else {
                $ips = @dns_get_record($host, DNS_A + DNS_AAAA);
                if (!empty($ips)) {
                    foreach ($ips as $record) {
                        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                            $isPrivate = true;
                            break;
                        }
                    }
                }
            }
            if ($isPrivate) {
                $log = new WebhookLog();
                $log->webhook        = $webhook;
                $log->event          = $message->event;
                $log->requestPayload = $payloadJson;
                $log->status         = 'failed';
                $log->errorMessage   = 'Blocked outbound request to private/internal IP';
                $this->em->persist($log);
                $this->em->flush();
                return;
            }
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
