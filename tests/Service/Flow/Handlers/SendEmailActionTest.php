<?php

namespace App\Tests\Service\Flow\Handlers;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\Handlers\Action\SendEmailHandler;
use App\Service\Flow\NodeOutput;
use App\Service\ProjectMailerService;
use PHPUnit\Framework\TestCase;

class SendEmailActionTest extends TestCase
{
    public function testGetCategoryIsAction(): void
    {
        $this->assertSame('action', SendEmailHandler::getCategory());
    }

    public function testGetTypeIsSendEmail(): void
    {
        $this->assertSame('send_email', SendEmailHandler::getType());
    }

    public function testGetFullType(): void
    {
        $this->assertSame('action.send_email', SendEmailHandler::getFullType());
    }

    public function testGetLabelAndDescriptionNotEmpty(): void
    {
        $this->assertNotEmpty(SendEmailHandler::getLabel());
        $this->assertNotEmpty(SendEmailHandler::getDescription());
    }

    public function testGetOutputPorts(): void
    {
        $ports = SendEmailHandler::getOutputPorts();
        $this->assertContains('default', $ports);
    }

    public function testExecuteWithValidConfigReturnsSuccess(): void
    {
        $project = $this->createMock(Project::class);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['uuid' => 'project-uuid'])
            ->willReturn($project);

        $mailerService = $this->createMock(ProjectMailerService::class);
        $mailerService->expects($this->once())
            ->method('send')
            ->with(
                project: $project,
                to: 'test@example.com',
                subject: 'Test Subject',
                body: 'Test Body',
            );

        $handler = new SendEmailHandler($projectRepo, $mailerService);
        $ctx = new FlowContext(1, 'project-uuid');
        $ctx->variables['_node_config'] = [
            'to' => 'test@example.com',
            'subject' => 'Test Subject',
            'body' => 'Test Body',
        ];

        $output = $handler->execute([], $ctx);

        $this->assertInstanceOf(NodeOutput::class, $output);
        $this->assertTrue($output->data['sent']);
        $this->assertSame('test@example.com', $output->data['to']);
    }
}
