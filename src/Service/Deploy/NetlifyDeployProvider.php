<?php
// src/Service/Deploy/NetlifyDeployProvider.php
namespace App\Service\Deploy;

use App\Entity\DeployToken;
use App\Entity\WorkbenchProject;
use App\Repository\AppSettingsRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NetlifyDeployProvider implements DeployProviderInterface
{
    private const OAUTH_URL  = 'https://app.netlify.com/authorize';
    private const TOKEN_URL  = 'https://api.netlify.com/oauth/token';
    private const SITES_URL  = 'https://api.netlify.com/api/v1/sites';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AppSettingsRepository $appSettingsRepository,
    ) {}

    public function getId(): string { return 'netlify'; }
    public function getLabel(): string { return 'Netlify'; }

    /** Reads OAuth credentials configured via the Jambo settings UI. */
    public function isConfigured(): bool
    {
        [$id, $secret] = $this->credentials();
        return $id !== '' && $secret !== '';
    }

    /** @return array{0: string, 1: string} [clientId, clientSecret] */
    private function credentials(): array
    {
        $config = $this->appSettingsRepository->getOrCreate()->deployIntegrations ?? [];
        return [
            (string) ($config['netlify']['client_id'] ?? ''),
            (string) ($config['netlify']['client_secret'] ?? ''),
        ];
    }

    public function getOAuthUrl(string $callbackUrl, string $state): string
    {
        [$clientId] = $this->credentials();
        return self::OAUTH_URL . '?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $callbackUrl,
            'response_type' => 'code',
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code, string $callbackUrl): array
    {
        [$clientId, $clientSecret] = $this->credentials();
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'redirect_uri'  => $callbackUrl,
            ],
        ]);

        return $response->toArray();
    }

    public function deploy(WorkbenchProject $project, DeployToken $token, string $plainToken): DeployResult
    {
        if (empty($project->files)) {
            return DeployResult::fail('No files to deploy. Generate your app first.');
        }

        try {
            $siteName = strtolower(str_replace([' ', '_'], '-', $project->name)) . '-' . substr($project->uuid->toRfc4122(), 0, 8);

            $siteResponse = $this->httpClient->request('POST', self::SITES_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $plainToken],
                'json'    => ['name' => $siteName],
            ]);
            $siteData = $siteResponse->toArray(false);

            if ($siteResponse->getStatusCode() >= 400) {
                return DeployResult::fail($siteData['message'] ?? 'Site creation failed', $siteData);
            }

            $siteId = $siteData['id'];
            $zipContent = $this->buildZip($project->files);

            $deployResponse = $this->httpClient->request('POST', self::SITES_URL . "/{$siteId}/deploys", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $plainToken,
                    'Content-Type'  => 'application/zip',
                ],
                'body' => $zipContent,
            ]);

            $deployData = $deployResponse->toArray(false);

            if ($deployResponse->getStatusCode() >= 400) {
                return DeployResult::fail($deployData['message'] ?? 'Deploy upload failed', $deployData);
            }

            $url = 'https://' . ($siteData['default_domain'] ?? $siteName . '.netlify.app');
            return DeployResult::ok($url, $deployData);
        } catch (\Throwable $e) {
            return DeployResult::fail('Netlify API error: ' . $e->getMessage());
        }
    }

    private function buildZip(array $files): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'netlify_') . '.zip';
        $zip     = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();

        $data = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $data;
    }
}
