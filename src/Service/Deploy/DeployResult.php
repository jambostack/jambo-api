<?php
// src/Service/Deploy/DeployResult.php
namespace App\Service\Deploy;

final class DeployResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly string $deployUrl = '',
        public readonly string $errorMessage = '',
        public readonly array  $raw = [],
    ) {}

    public static function ok(string $deployUrl, array $raw = []): self
    {
        return new self(success: true, deployUrl: $deployUrl, raw: $raw);
    }

    public static function fail(string $errorMessage, array $raw = []): self
    {
        return new self(success: false, errorMessage: $errorMessage, raw: $raw);
    }
}
