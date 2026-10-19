<?php

namespace App\Tests\Controller;

use App\Controller\Admin\ContentAiStreamController;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\AiContentService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ContentAiStreamControllerTest extends TestCase
{
    public function testStreamResponseHeaders(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $aiService = $this->createMock(AiContentService::class);

        $controller = new ContentAiStreamController($projectRepo, $aiService);

        $request = new Request([], [], [], [], [], [], json_encode([
            'action' => 'summarize',
            'text'   => 'Ceci est un texte de test pour vérifier le streaming Server-Sent Events.',
        ]));

        $response = $controller->streamAction($request);

        $this->assertEquals('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertEquals('no', $response->headers->get('X-Accel-Buffering'));
    }

    public function testStreamEmptyTextReturns400(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $aiService = $this->createMock(AiContentService::class);

        $controller = new ContentAiStreamController($projectRepo, $aiService);

        $request = new Request([], [], [], [], [], [], json_encode([
            'action' => 'rewrite',
            'text'   => '',
        ]));

        $response = $controller->streamAction($request);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testStreamOutputsRealSseChunksFromAiService(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $aiService = $this->createMock(AiContentService::class);

        $aiService->expects($this->once())
            ->method('streamPrompt')
            ->willReturnCallback(function (string $prompt, callable $onChunk) {
                $onChunk('Hello ');
                $onChunk('world!');
            });

        $controller = new ContentAiStreamController($projectRepo, $aiService);

        $request = new Request([], [], [], [], [], [], json_encode([
            'action' => 'summarize',
            'text'   => 'Original input text',
        ]));

        $response = $controller->streamAction($request);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertStringContainsString('event: start', $output);
        $this->assertStringContainsString('event: chunk', $output);
        $this->assertStringContainsString('data: {"chunk":"Hello "}', $output);
        $this->assertStringContainsString('data: {"chunk":"world!"}', $output);
        $this->assertStringContainsString('event: done', $output);
        $this->assertStringContainsString('data: {"done":true}', $output);
    }

    public function testStreamHandlesAiServiceExceptionGracefully(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $aiService = $this->createMock(AiContentService::class);

        $aiService->expects($this->once())
            ->method('streamPrompt')
            ->willThrowException(new \RuntimeException('Fournisseur IA non joignable'));

        $controller = new ContentAiStreamController($projectRepo, $aiService);

        $request = new Request([], [], [], [], [], [], json_encode([
            'action' => 'rewrite',
            'text'   => 'Original input text',
        ]));

        $response = $controller->streamAction($request);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertStringContainsString('event: start', $output);
        $this->assertStringContainsString('event: error', $output);
        $this->assertStringContainsString('Fournisseur IA non joignable', $output);
        $this->assertStringContainsString('event: done', $output);
    }

    public function testStreamWithInvalidProjectReturns404(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['uuid' => 'non-existent-uuid'])
            ->willReturn(null);

        $aiService = $this->createMock(AiContentService::class);
        $controller = new ContentAiStreamController($projectRepo, $aiService);

        $request = new Request([], [], [], [], [], [], json_encode([
            'action' => 'rewrite',
            'text'   => 'Some text',
        ]));

        $response = $controller->streamAction($request, 'non-existent-uuid');
        $this->assertEquals(404, $response->getStatusCode());
    }
}
