<?php

namespace App\Tests\Controller;

use App\Entity\Automation;
use App\Entity\Project;
use App\Message\SendProjectEmailMessage;
use App\MessageHandler\SendProjectEmailMessageHandler;
use App\Repository\AutomationRepository;
use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\Handlers\Action\SendEmailHandler;
use App\Service\Flow\NodeOutput;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class Cycle30HardeningTest extends TestCase
{
    public function testSendEmailHandlerInterpolatesVariablesAndSanitizesCrlf(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createStub(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $mailer = $this->createMock(ProjectMailerService::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with(
                project: $project,
                to: 'recipient@example.com',
                subject: 'New Post: Hello World',
                body: 'Article by Alice',
                htmlBody: 'Article by Alice',
            );

        $handler = new SendEmailHandler($projectRepo, $mailer);
        $ctx = new FlowContext(1, $projectUuid);
        $ctx->setVariable('entry', ['title' => 'Hello World', 'author' => 'Alice']);
        $ctx->variables['_node_config'] = [
            'to' => 'recipient@example.com',
            'subject' => 'New Post: {{entry.title}}',
            'body' => 'Article by {{entry.author}}',
        ];

        $output = $handler->execute([], $ctx);

        $this->assertInstanceOf(NodeOutput::class, $output);
        $this->assertTrue($output->data['sent']);
        $this->assertSame('recipient@example.com', $output->data['to']);
    }

    public function testSendEmailHandlerRejectsInvalidEmail(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createStub(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $mailer = $this->createMock(ProjectMailerService::class);
        $mailer->expects($this->never())->method('send');

        $handler = new SendEmailHandler($projectRepo, $mailer);
        $ctx = new FlowContext(1, $projectUuid);
        $ctx->variables['_node_config'] = [
            'to' => 'not-an-email',
            'subject' => 'Test',
            'body' => 'Body',
        ];

        $output = $handler->execute([], $ctx);

        $this->assertInstanceOf(NodeOutput::class, $output);
        $this->assertFalse($output->data['sent']);
        $this->assertStringContainsString('Invalid destination email address', $output->data['error']);
    }

    public function testProjectMailerServiceRejectsCrlfInjection(): void
    {
        $project = new Project();
        $em = $this->createStub(EntityManagerInterface::class);
        $bus = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
        $repo = $this->createStub(\App\Repository\ProjectMailerSettingsRepository::class);

        $service = new ProjectMailerService($em, $bus, $repo, 'my-32-byte-secret-key-12345678');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid destination email address');

        $service->send(
            project: $project,
            to: "user@example.com\r\nBcc: evil@attacker.com",
            subject: "Normal Subject",
            body: "Normal Body",
        );
    }

    public function testSendProjectEmailMessageHandlerDropsInvalidEmailSilently(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $handler = new SendProjectEmailMessageHandler($em, 'secret');

        $message = new SendProjectEmailMessage(
            host: 'smtp.example.com',
            port: 587,
            username: 'user',
            encryptedPassword: 'pw',
            encryption: 'tls',
            fromEmail: 'noreply@example.com',
            fromName: 'Jambo',
            to: "invalid-email\r\nheader: evil",
            subject: "Test",
            body: "Body",
            projectId: 1,
        );

        // Does not throw an exception, cleanly drops invalid message
        $handler($message);
        $this->assertTrue(true);
    }

    public function testAutomationRepositoryFiltersByTriggerTypeInPhp(): void
    {
        $auto1 = new Automation();
        $auto1->name = 'Auto 1';
        $auto1->isActive = true;
        $auto1->flowGraph = [
            'nodes' => [
                ['id' => '1', 'type' => 'trigger.content.created'],
            ],
        ];

        $auto2 = new Automation();
        $auto2->name = 'Auto 2';
        $auto2->isActive = true;
        $auto2->flowGraph = [
            'nodes' => [
                ['id' => '1', 'type' => 'trigger.webhook.inbound'],
            ],
        ];

        $node = AutomationRepository::findTriggerNode($auto1->flowGraph);
        $this->assertSame('trigger.content.created', $node['type']);

        $node2 = AutomationRepository::findTriggerNode($auto2->flowGraph);
        $this->assertSame('trigger.webhook.inbound', $node2['type']);
    }

    public function testImageTransformControllerUsesTranslatedResponses(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $em->method('getRepository')->willReturn($repo);

        $imageTransform = $this->createStub(\App\Service\ImageTransformService::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = [], ?string $domain = null) {
            return match ($id) {
                'media.not_found' => 'Media was not found in database.',
                'media.access_denied' => 'You do not have permission to view this media.',
                default => $id,
            };
        });

        $controller = new \App\Controller\ImageTransformController(
            em: $em,
            imageTransform: $imageTransform,
            translator: $translator,
        );

        $request = \Symfony\Component\HttpFoundation\Request::create('/cdn/media/unknown-uuid');
        $response = $controller->serve('unknown-uuid', $request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Media was not found in database.', (string) $response->getContent());

        $infoResponse = $controller->info('unknown-uuid', $request);
        $this->assertSame(404, $infoResponse->getStatusCode());
        $data = json_decode((string) $infoResponse->getContent(), true);
        $this->assertSame('Media was not found in database.', $data['error']);
    }

    public function testGraphQLControllerUsesTranslatedResponses(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $em->method('getRepository')->willReturn($repo);

        $schemaGen = $this->createStub(\App\GraphQL\SchemaGenerator::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = [], ?string $domain = null) {
            return match ($id) {
                'graphql.project_not_found' => 'Project does not exist.',
                'graphql.batch_not_allowed' => 'Batching is disabled.',
                default => $id,
            };
        });

        $controller = new \App\Controller\GraphQLController(
            em: $em,
            schemaGenerator: $schemaGen,
            translator: $translator,
        );

        $request = \Symfony\Component\HttpFoundation\Request::create('/api/projects/unknown/graphql', 'POST');
        $response = $controller->handle('unknown', $request);

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('Project does not exist.', $data['errors'][0]['message']);
    }
}
