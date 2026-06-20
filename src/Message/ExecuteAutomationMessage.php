<?php

namespace App\Message;

/**
 * Message Messenger pour exécution asynchrone d'automatisation.
 *
 * Transporte l'identité de l'automatisation et le payload.
 * Le FlowInterpreter est appelé dans le handler.
 */
class ExecuteAutomationMessage
{
    public function __construct(
        public readonly int $automationId,
        public readonly int $runId,
        public readonly array $triggerPayload,
        public readonly string $projectUuid,
        public readonly bool $debugMode,
    ) {}
}
