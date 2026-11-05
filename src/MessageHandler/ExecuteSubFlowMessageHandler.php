<?php

namespace App\MessageHandler;

use App\Message\ExecuteSubFlowMessage;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExecuteSubFlowMessageHandler
{
    public function __construct(
        private readonly FlowInterpreter $interpreter,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(ExecuteSubFlowMessage $msg): void
    {
        // Vérification d'expiration TTL
        if ($msg->dispatchedAt > 0 && (time() - $msg->dispatchedAt) > $msg->ttlSeconds) {
            $this->logger->warning('Subflow execution skipped due to expired TTL', [
                'automation_id' => $msg->automationId,
                'parent_run_id' => $msg->parentRunId,
            ]);
            return;
        }

        // Vérification de limite de récursion
        if ($msg->depth > 5) {
            $this->logger->error('Subflow recursion limit exceeded', [
                'automation_id' => $msg->automationId,
                'depth' => $msg->depth,
            ]);
            return;
        }

        if (empty($msg->subGraph) || !isset($msg->subGraph['nodes'])) {
            return;
        }

        $ctx = new FlowContext(
            automationId: $msg->automationId,
            projectUuid: $msg->projectUuid,
            debugMode: $msg->debugMode,
        );
        $ctx->variables = is_array($msg->contextSnapshot['variables'] ?? null) ? $msg->contextSnapshot['variables'] : [];
        $ctx->nodeResults = is_array($msg->contextSnapshot['nodeResults'] ?? null) ? $msg->contextSnapshot['nodeResults'] : [];

        $this->interpreter->executeSubFlow($msg->subGraph, $msg->triggerPayload, $ctx);
    }
}

