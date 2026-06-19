<?php

namespace App\Message;

class ExecuteAutomationMessage
{
    public function __construct(
        public readonly int $automationId,
        public readonly int $runId,
        public readonly string $actionType,
        public readonly array $actionInput,
        public readonly string $projectUuid,
        public readonly bool $debugMode,
    ) {}
}
