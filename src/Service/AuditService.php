<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AuditService
{
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|api_?key|auth|bearer|credential|private_?key|pin|cvv|access_token|refresh_token|session)/i';

    private const JWT_PATTERN = '/^eyJ[a-zA-Z0-9_-]{4,}\.[a-zA-Z0-9_-]{2,}\.[a-zA-Z0-9_-]+$/';

    private const API_KEY_PREFIX_PATTERN = '/^(sk_live_|ghp_|gho_|xoxb-|xoxp-)[a-zA-Z0-9_-]{16,}$/';

    /** @var AuditLog[] */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Enregistre une action dans le journal d'audit avec assainissement des données sensibles.
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
        $entry->output = $this->truncateOutput($this->sanitizeData($output));
        $entry->status = $status;
        $entry->errorMessage = $errorMessage;
        $entry->createdBy = $createdBy;
        $entry->source = $source;
        $entry->durationMs = $durationMs;

        $this->em->persist($entry);
        $this->pending[] = $entry;

        // Flush immédiat seulement si on accumule plus de 10 logs
        if (count($this->pending) >= 10) {
            $this->flush();
        }

        return $entry;
    }

    /**
     * Indique si des logs sont actuellement en attente d'écriture.
     */
    public function hasPending(): bool
    {
        return !empty($this->pending);
    }

    /**
     * Réinitialise le buffer de logs en attente sans les persister.
     */
    public function clearPending(): void
    {
        $this->pending = [];
    }

    /**
     * Vide le buffer de logs en attente. À appeler en fin de requête (subscriber kernel.terminate).
     */
    public function flush(): void
    {
        if (!empty($this->pending)) {
            try {
                $this->em->flush();
                $this->pending = [];
            } catch (\Throwable $e) {
                $this->logger->error('Failed to flush audit logs: ' . $e->getMessage(), [
                    'pending_count' => count($this->pending),
                    'exception'     => $e,
                ]);
            }
        }
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

    /**
     * Assainit récursivement les champs sensibles dans un tableau ou une valeur scalaire.
     */
    public function sanitizeData(mixed $data): mixed
    {
        if ($data === null) {
            return null;
        }

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeData($value);
                } elseif (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                    $sanitized[$key] = '[REDACTED]';
                } elseif (is_string($value)) {
                    $sanitized[$key] = $this->sanitizeScalarString($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }
            return $sanitized;
        }

        if (is_string($data)) {
            return $this->sanitizeScalarString($data);
        }

        if (is_object($data)) {
            if ($data instanceof \JsonSerializable) {
                return $this->sanitizeData($data->jsonSerialize());
            }
            if ($data instanceof \Stringable) {
                return $this->sanitizeScalarString((string) $data);
            }
            $vars = get_object_vars($data);
            return $this->sanitizeData($vars);
        }

        return $data;
    }

    private function sanitizeScalarString(string $val): string
    {
        $trimmed = trim($val);

        if (str_starts_with($trimmed, '-----BEGIN') && str_contains($trimmed, 'PRIVATE KEY-----')) {
            return '[REDACTED_PRIVATE_KEY]';
        }

        if (stripos($trimmed, 'Bearer ') === 0 && strlen($trimmed) > 10) {
            return 'Bearer [REDACTED]';
        }

        if (preg_match(self::JWT_PATTERN, $trimmed)) {
            return '[REDACTED_JWT]';
        }

        if (preg_match(self::API_KEY_PREFIX_PATTERN, $trimmed)) {
            return '[REDACTED_SECRET]';
        }

        return $val;
    }

    private function sanitizeInput(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }

        return $this->sanitizeData($input);
    }

    private function truncateOutput(mixed $output): ?array
    {
        if ($output === null) {
            return null;
        }

        $json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || strlen($json) <= 10000) {
            return is_array($output) ? $output : ['result' => $output];
        }

        return [
            '_truncated'     => true,
            '_original_size' => strlen($json),
            'preview'        => mb_substr($json, 0, 5000) . '...',
        ];
    }
}

