<?php

namespace App\Tests\Service;

use App\Entity\AppSettings;
use App\Repository\AppSettingsRepository;
use App\Service\AiContentService;
use App\Service\AuditService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;

class AiContentServiceStreamTest extends TestCase
{
    public function testStreamPromptOpenAiChunks(): void
    {
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $settings = new AppSettings();
        $settings->aiProviders = [
            'openai' => [
                'enabled' => true,
                'key' => 'test-sk-key',
                'model' => 'gpt-4o-mini',
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $chunk1 = $this->createMock(ChunkInterface::class);
        $chunk1->method('getContent')->willReturn("data: {\"choices\":[{\"delta\":{\"content\":\"Bonjour \"}}]}\n\n");

        $chunk2 = $this->createMock(ChunkInterface::class);
        $chunk2->method('getContent')->willReturn("data: {\"choices\":[{\"delta\":{\"content\":\"monde!\"}}]}\n\ndata: [DONE]\n\n");

        $mockStream = new class([$chunk1, $chunk2], $mockResponse) implements ResponseStreamInterface {
            private int $i = 0;
            public function __construct(private array $chunks, private ResponseInterface $response) {}
            public function current(): ChunkInterface { return $this->chunks[$this->i]; }
            public function key(): ResponseInterface { return $this->response; }
            public function next(): void { $this->i++; }
            public function rewind(): void { $this->i = 0; }
            public function valid(): bool { return isset($this->chunks[$this->i]); }
        };

        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.openai.com/v1/chat/completions',
                $this->callback(function (array $options) {
                    return ($options['json']['stream'] ?? false) === true
                        && ($options['headers']['Authorization'] ?? '') === 'Bearer test-sk-key';
                })
            )
            ->willReturn($mockResponse);

        $mockHttpClient->expects($this->once())
            ->method('stream')
            ->with($mockResponse)
            ->willReturn($mockStream);

        $audit = $this->createMock(AuditService::class);
        $security = $this->createMock(Security::class);

        $service = new AiContentService($appSettingsRepo, $mockHttpClient, $audit, $security);

        $collected = [];
        $service->streamPrompt(
            prompt: 'Dis bonjour',
            onChunk: function (string $token) use (&$collected) {
                $collected[] = $token;
            },
            provider: 'openai'
        );

        $this->assertSame(['Bonjour ', 'monde!'], $collected);
    }

    public function testStreamPromptThrowsWhenNoProvidersActive(): void
    {
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $settings = new AppSettings();
        $settings->aiProviders = [];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $audit = $this->createMock(AuditService::class);
        $security = $this->createMock(Security::class);

        $service = new AiContentService($appSettingsRepo, $mockHttpClient, $audit, $security);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Aucun fournisseur IA activé');

        $service->streamPrompt('Test prompt', function () {});
    }
}
