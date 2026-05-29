<?php
// src/Service/Deploy/VercelDeployProvider.php
namespace App\Service\Deploy;

use App\Entity\DeployToken;
use App\Entity\WorkbenchProject;
use App\Repository\AppSettingsRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class VercelDeployProvider implements DeployProviderInterface
{
    private const OAUTH_URL      = 'https://vercel.com/oauth/authorize';
    private const TOKEN_URL      = 'https://api.vercel.com/v2/oauth/access_token';
    private const DEPLOY_API_URL = 'https://api.vercel.com/v13/deployments';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AppSettingsRepository $appSettingsRepository,
    ) {}

    public function getId(): string { return 'vercel'; }
    public function getLabel(): string { return 'Vercel'; }

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
            (string) ($config['vercel']['client_id'] ?? ''),
            (string) ($config['vercel']['client_secret'] ?? ''),
        ];
    }

    public function getOAuthUrl(string $callbackUrl, string $state): string
    {
        [$clientId] = $this->credentials();
        return self::OAUTH_URL . '?' . http_build_query([
            'client_id'    => $clientId,
            'redirect_uri' => $callbackUrl,
            'state'        => $state,
        ]);
    }

    public function exchangeCode(string $code, string $callbackUrl): array
    {
        [$clientId, $clientSecret] = $this->credentials();
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'body' => [
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

        $vercelFiles = [];
        foreach ($project->files as $path => $content) {
            $vercelFiles[] = [
                'file' => $path,
                'data' => $content,
            ];
        }

        try {
            $response = $this->httpClient->request('POST', self::DEPLOY_API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $plainToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'name'      => strtolower(str_replace(' ', '-', $project->name)),
                    'files'     => $vercelFiles,
                    'projectSettings' => [
                        'framework' => $this->mapFramework($project->framework),
                    ],
                ],
            ]);

            $data = $response->toArray(false);

            if ($response->getStatusCode() >= 400) {
                return DeployResult::fail($data['error']['message'] ?? 'Vercel deploy failed', $data);
            }

            $url = 'https://' . ($data['url'] ?? $data['alias'][0] ?? 'vercel.app');
            return DeployResult::ok($url, $data);
        } catch (\Throwable $e) {
            return DeployResult::fail('Vercel API error: ' . $e->getMessage());
        }
    }

    private function mapFramework(string $framework): ?string
    {
        return match($framework) {
            'nextjs'    => 'nextjs',
            'nuxt'      => 'nuxtjs',
            'astro'     => 'astro',
            'sveltekit' => 'sveltekit',
            default     => null,
        };
    }
}
