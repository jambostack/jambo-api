<?php

namespace App\Message;

class ExecuteSubFlowMessage
{
    public function __construct(
        public readonly string $parentRunId,
        public readonly int $automationId,
        public readonly array $subGraph,
        public readonly array $triggerPayload,
        public readonly array $contextSnapshot,
        public readonly string $entryNodeId,
        public readonly string $projectUuid,
        public readonly bool $debugMode,
    ) {}
}
