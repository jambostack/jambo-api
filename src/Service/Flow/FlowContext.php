<?php

namespace App\Service\Flow;

class FlowContext
{
    public const MAX_VARIABLES = 50;
    public const MAX_VARIABLE_VALUE_LENGTH = 65536; // 64 KB

    public const FORBIDDEN_VARIABLE_PATTERNS = [
        '/^_/i',
        '/^(?:_?env|_?server|globals|env|server)$/i',
        '/(?:secret|password|token|credential|key|database|mailer|mercure|jwt|auth|session|cookie|hash|salt|bearer)/i',
        '/^(?:app_env|app_secret|database_url|mailer_dsn|jwt_secret|mercure_|http_|path|shell|php_|sys_|system|document_root)/i',
    ];

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

    /**
     * Vérifie si un nom de variable est sûr et ne tente pas d'échapper vers l'environnement.
     */
    public function isSafeVariableKey(string $key): bool
    {
        if (strlen($key) < 1 || strlen($key) > 64) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $key)) {
            return false;
        }

        foreach (self::FORBIDDEN_VARIABLE_PATTERNS as $pattern) {
            if (preg_match($pattern, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Assainit récursivement les valeurs complexes de variables.
     */
    public function sanitizeVariableValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return null;
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                $strKey = strtolower((string) $k);
                if (preg_match('/(?:password|secret|token|credential|key|auth|bearer|hash|salt)/i', $strKey)) {
                    continue; // Supprime les sous-clés sensibles
                }
                if (is_scalar($v) || $v === null) {
                    if (is_string($v) && strlen($v) > self::MAX_VARIABLE_VALUE_LENGTH) {
                        $v = substr($v, 0, self::MAX_VARIABLE_VALUE_LENGTH);
                    }
                    $clean[$k] = $v;
                } elseif (is_array($v)) {
                    $clean[$k] = $this->sanitizeVariableValue($v, $depth + 1);
                }
            }
            return $clean;
        }

        if (is_string($value)) {
            return strlen($value) > self::MAX_VARIABLE_VALUE_LENGTH
                ? substr($value, 0, self::MAX_VARIABLE_VALUE_LENGTH)
                : $value;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }

    /**
     * Définit une variable utilisateur avec validation et cloisonnement strict.
     */
    public function setVariable(string $key, mixed $value): bool
    {
        if (!$this->isSafeVariableKey($key)) {
            return false;
        }

        if (count($this->variables) >= self::MAX_VARIABLES && !array_key_exists($key, $this->variables)) {
            return false;
        }

        if (!is_scalar($value) && !is_array($value) && $value !== null) {
            return false;
        }

        $sanitizedValue = $this->sanitizeVariableValue($value);
        if ($sanitizedValue === null && $value !== null) {
            return false;
        }

        $this->variables[$key] = $sanitizedValue;
        return true;
    }

    public function getVariable(string $key, mixed $default = null): mixed
    {
        return $this->variables[$key] ?? $default;
    }

    public function setCurrentNodeConfig(array $config): void
    {
        $this->variables['_node_config'] = $config;
    }

    public function getCurrentNodeConfig(): array
    {
        return $this->variables['_node_config'] ?? [];
    }

    /**
     * Retourne les variables utilisateur assainies (sans configuration interne ni secrets).
     */
    public function getSafeVariables(): array
    {
        $safe = [];
        foreach ($this->variables as $k => $v) {
            if (is_string($k) && $this->isSafeVariableKey($k)) {
                $safe[$k] = $v;
            }
        }
        return $safe;
    }

    /**
     * Retourne le stepLog en masquant automatiquement les données sensibles (mots de passe, jetons, etc.).
     */
    public function getSanitizedStepLog(): array
    {
        return array_map(function (array $step) {
            $step['input'] = $this->sanitizePayload($step['input']);
            $step['output'] = $this->sanitizePayload($step['output']);
            return $step;
        }, $this->stepLog);
    }

    private function sanitizePayload(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return '[MAX_DEPTH]';
        }

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $k => $v) {
                $strKey = strtolower((string) $k);
                if (preg_match('/(?:password|secret|token|credential|auth|api_?key|hash|salt|bearer)/i', $strKey)) {
                    $sanitized[$k] = '***REDACTED***';
                } else {
                    $sanitized[$k] = $this->sanitizePayload($v, $depth + 1);
                }
            }
            return $sanitized;
        }

        if (is_string($data) && strlen($data) > 2048) {
            return substr($data, 0, 2048) . '... [TRUNCATED]';
        }

        return $data;
    }
}
