<?php

namespace App\MessageHandler;

use App\Message\ExecuteSubFlowMessage;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExecuteSubFlowMessageHandler
{
    public function __construct(
        private readonly FlowInterpreter $interpreter,
    ) {}

    public function __invoke(ExecuteSubFlowMessage $msg): void
    {
        $ctx = new FlowContext(
            automationId: $msg->automationId,
            projectUuid: $msg->projectUuid,
            debugMode: $msg->debugMode,
        );
        $ctx->variables = $msg->contextSnapshot['variables'] ?? [];
        $ctx->nodeResults = $msg->contextSnapshot['nodeResults'] ?? [];

        $this->interpreter->executeSubFlow($msg->subGraph, $msg->triggerPayload, $ctx);
    }
}
