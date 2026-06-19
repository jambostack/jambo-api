<?php

namespace App\Service\Flow;

class FlowResult
{
    /** @param NodeOutput[] $finalOutputs */
    public function __construct(
        public readonly string $status,        // 'success' | 'partial' | 'failed'
        public readonly array $finalOutputs = [],
        public readonly array $stepLog = [],
        public readonly array $parallelBranches = [],
        public readonly int $totalDurationMs = 0,
        public readonly ?string $error = null,
    ) {}

    public static function fromContext(FlowContext $ctx, int $totalDurationMs, string $status = 'success', ?string $error = null): self
    {
        return new self(
            status: $status,
            finalOutputs: $ctx->nodeResults,
            stepLog: $ctx->stepLog,
            totalDurationMs: $totalDurationMs,
            error: $error,
        );
    }
}
