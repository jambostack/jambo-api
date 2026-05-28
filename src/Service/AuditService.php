<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class AuditService
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Enregistre une action dans le journal d'audit.
     */
    public function log(
        string $toolName,
        ?Project $project,
        ?array $input,
        mixed $output,
        string $status = 'success',
        ?string $errorMessage = null,
        ?string $createdBy = null,
        string $source = 'mcp',
        ?int $durationMs = null,
    ): AuditLog {
        $entry = new AuditLog();
        $entry->toolName = $toolName;
        $entry->project = $project;
        $entry->input = $this->sanitizeInput($input);
        $entry->output = $this->truncateOutput($output);
        $entry->status = $status;
        $entry->errorMessage = $errorMessage;
        $entry->createdBy = $createdBy;
        $entry->source = $source;
        $entry->durationMs = $durationMs;

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * Log an AI action specifically.
     */
    public function logAiAction(
        string $action,
        ?Project $project,
        ?array $input,
        mixed $output,
        ?string $createdBy = null,
        ?int $durationMs = null,
    ): AuditLog {
        return $this->log(
            toolName: "ai_$action",
            project: $project,
            input: $input,
            output: $output,
            status: 'success',
            createdBy: $createdBy,
            source: 'ai',
            durationMs: $durationMs,
        );
    }

    private function sanitizeInput(?array $input): ?array
    {
        if ($input === null) return null;

        // Masquer les champs sensibles
        $sensitive = ['password', 'token', 'secret', 'api_key', 'authorization'];
        foreach ($input as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $input[$key] = '[REDACTED]';
            }
        }

        return $input;
    }

    private function truncateOutput(mixed $output): ?array
    {
        if ($output === null) return null;

        $json = json_encode($output, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) <= 10000) {
            return is_array($output) ? $output : ['result' => $output];
        }

        return [
            '_truncated' => true,
            '_original_size' => strlen($json),
            'preview' => mb_substr($json, 0, 5000) . '...',
        ];
    }
}
