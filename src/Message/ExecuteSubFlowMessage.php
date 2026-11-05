<?php

namespace App\Message;

class ExecuteSubFlowMessage
{
    public readonly int $dispatchedAt;

    public function __construct(
        public readonly string $parentRunId,
        public readonly int $automationId,
        public readonly array $subGraph,
        public readonly array $triggerPayload,
        public readonly array $contextSnapshot,
        public readonly string $entryNodeId,
        public readonly string $projectUuid,
        public readonly bool $debugMode,
        public readonly int $depth = 1,
        ?int $dispatchedAt = null,
        public readonly int $ttlSeconds = 86400,
    ) {
        if ($automationId <= 0) {
            throw new \InvalidArgumentException('Invalid automation ID.');
        }
        if ($depth > 5) {
            throw new \InvalidArgumentException('Maximum subflow recursion depth exceeded.');
        }
        $this->dispatchedAt = ($dispatchedAt !== null && $dispatchedAt > 0) ? $dispatchedAt : time();
    }
}

