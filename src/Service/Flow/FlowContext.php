<?php

namespace App\Service\Flow;

class FlowContext
{
    public readonly string $runId;

    /** @var array<string, NodeOutput> nodeId → output */
    public array $nodeResults = [];

    /** @var array<array{nodeId: string, type: string, label: string, status: string, durationMs: int, input: array, output: array, error: ?string}> */
    public array $stepLog = [];

    /** Variables partagées entre nodes */
    public array $variables = [];

    public function __construct(
        public readonly int $automationId,
        public readonly string $projectUuid,
        public readonly bool $debugMode = false,
    ) {
        $this->runId = bin2hex(random_bytes(16));
    }

    public function logStep(string $nodeId, string $type, string $label, string $status, int $durationMs, array $input, array $output, ?string $error = null): void
    {
        $this->stepLog[] = [
            'nodeId' => $nodeId,
            'type' => $type,
            'label' => $label,
            'status' => $status,
            'durationMs' => $durationMs,
            'input' => $input,
            'output' => $output,
            'error' => $error,
            'timestamp' => time(),
        ];
    }
}
