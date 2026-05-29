<?php
// src/Service/Deploy/DeployProviderInterface.php
namespace App\Service\Deploy;

use App\Entity\DeployToken;
use App\Entity\WorkbenchProject;

interface DeployProviderInterface
{
    public function getId(): string;   // 'vercel' | 'netlify' | 'railway'
    public function getLabel(): string;
    public function isConfigured(): bool; // true if OAuth credentials are set in Jambo settings
    public function getOAuthUrl(string $callbackUrl, string $state): string;
    public function exchangeCode(string $code, string $callbackUrl): array; // returns ['access_token' => ..., 'expires_in' => ...]
    public function deploy(WorkbenchProject $project, DeployToken $token, string $plainToken): DeployResult;
}
