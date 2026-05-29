<?php
// src/Service/Deploy/RailwayDeployProvider.php
namespace App\Service\Deploy;

use App\Entity\DeployToken;
use App\Entity\WorkbenchProject;
use App\Repository\AppSettingsRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RailwayDeployProvider implements DeployProviderInterface
{
    private const OAUTH_URL  = 'https://railway.app/oauth2/authorize';
    private const TOKEN_URL  = 'https://backboard.railway.app/oauth2/token';
    private const GRAPHQL    = 'https://backboard.railway.app/graphql/v2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AppSettingsRepository $appSettingsRepository,
    ) {}

    public function getId(): string { return 'railway'; }
    public function getLabel(): string { return 'Railway'; }

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
            (string) ($config['railway']['client_id'] ?? ''),
            (string) ($config['railway']['client_secret'] ?? ''),
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
            $mutation = <<<'GQL'
            mutation CreateProject($name: String!) {
              projectCreate(input: { name: $name }) {
                id
                name
              }
            }
            GQL;

            $response = $this->httpClient->request('POST', self::GRAPHQL, [
                'headers' => ['Authorization' => 'Bearer ' . $plainToken],
                'json'    => [
                    'query'     => $mutation,
                    'variables' => ['name' => $project->name . ' (Jambo)'],
                ],
            ]);

            $data = $response->toArray(false);

            if (isset($data['errors'])) {
                return DeployResult::fail($data['errors'][0]['message'] ?? 'Railway project creation failed', $data);
            }

            $projectId = $data['data']['projectCreate']['id'] ?? null;
            $url = $projectId
                ? "https://railway.app/project/{$projectId}"
                : 'https://railway.app/dashboard';

            return DeployResult::ok($url, $data);
        } catch (\Throwable $e) {
            return DeployResult::fail('Railway API error: ' . $e->getMessage());
        }
    }
}
