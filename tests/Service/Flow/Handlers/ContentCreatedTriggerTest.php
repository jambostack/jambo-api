<?php

namespace App\Tests\Service\Flow\Handlers;

use App\Service\Flow\FlowContext;
use App\Service\Flow\Handlers\Trigger\ContentCreatedHandler;
use App\Service\Flow\NodeOutput;
use PHPUnit\Framework\TestCase;

class ContentCreatedTriggerTest extends TestCase
{
    public function testGetCategoryIsTrigger(): void
    {
        $handler = new ContentCreatedHandler();
        $this->assertSame('trigger', $handler::getCategory());
    }

    public function testGetTypeIsContentCreated(): void
    {
        $handler = new ContentCreatedHandler();
        $this->assertSame('content.created', $handler::getType());
    }

    public function testGetFullType(): void
    {
        $handler = new ContentCreatedHandler();
        $this->assertSame('trigger.content.created', $handler::getFullType());
    }

    public function testGetLabelAndDescriptionNotEmpty(): void
    {
        $handler = new ContentCreatedHandler();
        $this->assertNotEmpty($handler::getLabel());
        $this->assertNotEmpty($handler::getDescription());
    }

    public function testGetOutputPorts(): void
    {
        $handler = new ContentCreatedHandler();
        $ports = $handler::getOutputPorts();
        $this->assertContains('default', $ports);
    }

    public function testExecuteReturnsNodeOutput(): void
    {
        $handler = new ContentCreatedHandler();
        $ctx = new FlowContext(1, 'project-uuid');

        $input = ['trigger' => ['entry_uuid' => 'test-uuid', 'action' => 'created']];
        $output = $handler->execute($input, $ctx);

        $this->assertInstanceOf(NodeOutput::class, $output);
    }
}
