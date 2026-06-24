<?php

namespace App\Security\Oidc;

use App\Dto\OidcConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OidcProviderManager
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private RequestStack $requestStack,
        private string $appSecret,
        private ?CacheInterface $cache = null,
    ) {}

    // ─── Discovery ──────────────────────────────────────────────────────

    public function discover(string $issuer): OidcConfig
    {
        $cacheKey = 'oidc_discovery_'.md5($issuer);

        if ($this->cache) {
            $cached = $this->cache->get($cacheKey, function (ItemInterface $item) use ($issuer) {
                $item->expiresAfter(3600);
                return $this->doDiscover($issuer);
            });
            if ($cached instanceof OidcConfig) {
                return $cached;
            }
        }

        return $this->doDiscover($issuer);
    }

    private function doDiscover(string $issuer): OidcConfig
    {
        // SSRF protection: validate URL, enforce HTTPS, reject loopback/private/link-local
        $wellKnownUrl = rtrim($issuer, '/').'/.well-known/openid-configuration';
        $this->validateDiscoveryUrl($wellKnownUrl);

        $response = $this->httpClient->request('GET', $wellKnownUrl, [
            'max_redirects' => 0,
        ]);
        $data = $response->toArray();

        // OIDC Discovery spec (RFC 8414): issuer in response must match requested issuer
        if (isset($data['issuer']) && $data['issuer'] !== rtrim($issuer, '/')) {
            throw new \RuntimeException('Discovery issuer mismatch.');
        }

        return new OidcConfig(
            issuer: $data['issuer'] ?? $issuer,
            authorizationEndpoint: $data['authorization_endpoint'],
            tokenEndpoint: $data['token_endpoint'],
            userinfoEndpoint: $data['userinfo_endpoint'],
            jwksUri: $data['jwks_uri'] ?? null,
            scopesSupported: $data['scopes_supported'] ?? ['openid', 'email', 'profile'],
            idTokenSigningAlg: $data['id_token_signing_alg_values_supported'][0] ?? 'RS256',
        );
    }

    // ─── State + PKCE + Nonce ───────────────────────────────────────────

    /** @return array{state: string, nonce: string, codeVerifier: string} */
    public function generateState(string $type, ?string $providerId, ?string $projectUuid): array
    {
        $nonce = bin2hex(random_bytes(32));
        $codeVerifier = $this->generateCodeVerifier();

        $payload = [
            'type' => $type,
            'providerId' => $providerId,
            'projectUuid' => $projectUuid,
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 300, // 5 minutes
        ];

        $state = $this->signPayload($payload);

        // Stocker code_verifier + nonce en session pour vérification au callback
        $session = $this->requestStack->getSession();
        $session->set('oidc_code_verifier', $codeVerifier);
        $session->set('oidc_nonce', $nonce);

        return [
            'state' => $state,
            'nonce' => $nonce,
            'codeVerifier' => $codeVerifier,
        ];
    }

    /** @return array{type: string, providerId: ?string, projectUuid: ?string, nonce: string} */
    public function validateState(string $stateJwt): array
    {
        $parts = explode('.', $stateJwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid state format.');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload || !isset($payload['exp'])) {
            throw new \RuntimeException('Invalid state payload.');
        }

        if ($payload['exp'] < time()) {
            throw new \RuntimeException('State expired.');
        }

        // Vérifier la signature
        $expectedSig = hash_hmac('sha256', $parts[0].'.'.$parts[1], $this->appSecret);
        if (!hash_equals($expectedSig, $parts[2])) {
            throw new \RuntimeException('State signature mismatch.');
        }

        return $payload;
    }

    public function signPayload(array $payload): string
    {
        $header = $this->base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $this->base64url_encode(json_encode($payload));
        $sig = hash_hmac('sha256', $header.'.'.$body, $this->appSecret);

        return $header.'.'.$body.'.'.$sig;
    }

    // ─── PKCE ───────────────────────────────────────────────────────────

    private function generateCodeVerifier(): string
    {
        return $this->base64url_encode(random_bytes(64));
    }

    public function computeCodeChallenge(string $codeVerifier): string
    {
        return $this->base64url_encode(hash('sha256', $codeVerifier, true));
    }

    // ─── Authorization URL ──────────────────────────────────────────────

    public function buildAuthorizationUrl(
        OidcConfig $config,
        string $redirectUri,
        string $codeChallenge,
        string $state,
        string $nonce,
    ): string {
        $params = [
            'response_type' => 'code',
            'client_id' => '', // sera rempli par l'appelant
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $config->scopesSupported),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $config->authorizationEndpoint.'?'.http_build_query($params);
    }

    // ─── Token Exchange ─────────────────────────────────────────────────

    /** @return array{idToken: string, accessToken: string, refreshToken: ?string} */
    public function exchangeCode(
        OidcConfig $config,
        string $code,
        string $redirectUri,
        string $clientId,
        string $clientSecret,
        string $codeVerifier,
    ): array {
        $response = $this->httpClient->request('POST', $config->tokenEndpoint, [
            'auth_basic' => $clientId.':'.$clientSecret,
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code_verifier' => $codeVerifier,
            ],
        ]);

        $data = $response->toArray();

        if (!isset($data['id_token'])) {
            throw new \RuntimeException('No id_token in token response.');
        }

        return [
            'idToken' => $data['id_token'],
            'accessToken' => $data['access_token'] ?? '',
            'refreshToken' => $data['refresh_token'] ?? null,
        ];
    }

    // ─── ID Token Validation ────────────────────────────────────────────

    /** @return array{sub: string, email: string, name: string, picture: ?string} */
    public function validateIdToken(
        string $idToken,
        OidcConfig $config,
        string $clientId,
        string $clientSecret,
        string $expectedNonce,
    ): array {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid id_token format.');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload) {
            throw new \RuntimeException('Invalid id_token payload.');
        }

        // 1. Vérifier issuer
        if (($payload['iss'] ?? '') !== $config->issuer) {
            throw new \RuntimeException('id_token issuer mismatch.');
        }

        // 2. Vérifier audience
        $aud = $payload['aud'] ?? [];
        if (is_string($aud)) {
            $aud = [$aud];
        }
        if (!in_array($clientId, $aud, true)) {
            throw new \RuntimeException('id_token audience mismatch.');
        }

        // 3. Vérifier expiration (30s skew)
        if (!isset($payload['exp']) || $payload['exp'] < (time() - 30)) {
            throw new \RuntimeException('id_token expired.');
        }

        // 4. Vérifier nonce
        if (($payload['nonce'] ?? '') !== $expectedNonce) {
            throw new \RuntimeException('id_token nonce mismatch.');
        }

        // 5. Vérifier signature
        if ($config->idTokenSigningAlg === 'HS256') {
            $expectedSig = $this->base64url_encode(
                hash_hmac('sha256', $parts[0].'.'.$parts[1], $clientSecret, true),
            );
            if (!hash_equals($expectedSig, $parts[2])) {
                throw new \RuntimeException('id_token signature mismatch.');
            }
        } else {
            throw new \RuntimeException('Unsupported id_token signing algorithm: '.$config->idTokenSigningAlg.'. Only HS256 is supported in v1.');
        }

        return [
            'sub' => (string) ($payload['sub'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'name' => (string) ($payload['name'] ?? ($payload['preferred_username'] ?? '')),
            'picture' => $payload['picture'] ?? null,
        ];
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function validateDiscoveryUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Invalid discovery URL.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new \RuntimeException('Discovery URL must use HTTPS.');
        }

        $host = $parts['host'];

        // Resolve hostname to IP addresses
        $ips = [];
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || count($records) === 0) {
            throw new \RuntimeException('Discovery hostname could not be resolved.');
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? ($record['ipv6'] ?? null);
            if ($ip !== null) {
                $ips[] = $ip;
            }
        }

        if (empty($ips)) {
            throw new \RuntimeException('Discovery hostname could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                // Check loopback (127.0.0.0/8)
                if (ip2long($ip) >= ip2long('127.0.0.0') && ip2long($ip) <= ip2long('127.255.255.255')) {
                    throw new \RuntimeException('Discovery URL must not point to loopback address.');
                }
                // Check RFC1918 private ranges
                if (
                    (ip2long($ip) >= ip2long('10.0.0.0') && ip2long($ip) <= ip2long('10.255.255.255'))
                    || (ip2long($ip) >= ip2long('172.16.0.0') && ip2long($ip) <= ip2long('172.31.255.255'))
                    || (ip2long($ip) >= ip2long('192.168.0.0') && ip2long($ip) <= ip2long('192.168.255.255'))
                ) {
                    throw new \RuntimeException('Discovery URL must not point to a private IP address.');
                }
                // Check link-local (169.254.0.0/16)
                if (ip2long($ip) >= ip2long('169.254.0.0') && ip2long($ip) <= ip2long('169.254.255.255')) {
                    throw new \RuntimeException('Discovery URL must not point to a link-local address.');
                }
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                // Reject IPv6 loopback (::1)
                if (strtolower($ip) === '::1') {
                    throw new \RuntimeException('Discovery URL must not point to loopback address.');
                }
            }
        }
    }
}
