<?php

namespace App\Tests\Controller;

use App\Controller\Admin\ContentAiStreamController;
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
}
