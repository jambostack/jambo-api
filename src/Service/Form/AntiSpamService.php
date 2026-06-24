<?php

namespace App\Service\Form;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AntiSpamService
{
    private const BLOCKLISTED_DOMAINS = [
        'mailinator.com', 'tempmail.com', 'guerrillamail.com', '10minutemail.com',
        'yopmail.com', 'throwaway.email', 'sharklasers.com', 'trashmail.com',
    ];

    public function __construct(
        private RateLimiterFactory $formSubmitLimiter,
        private ?HttpClientInterface $httpClient = null,
    ) {}

    public function checkHoneypot(array $data, string $honeypotField = '_website'): bool
    {
        return !empty($data[$honeypotField] ?? null); // true = spam detected
    }

    public function checkRateLimit(string $ip): bool
    {
        $limiter = $this->formSubmitLimiter->create($ip);
        $limit = $limiter->consume();
        return !$limit->isAccepted(); // true = rate limited
    }

    public function verifyCaptcha(string $token, array $captchaConfig): bool
    {
        $provider = $captchaConfig['provider'] ?? 'turnstile';
        $secret = $captchaConfig['secret'] ?? '';
        if (empty($secret) || empty($token)) return false;

        return match ($provider) {
            'turnstile' => $this->verifyTurnstile($token, $secret),
            'recaptcha' => $this->verifyRecaptcha($token, $secret),
            'hcaptcha' => $this->verifyHcaptcha($token, $secret),
            default => false,
        };
    }

    private function verifyTurnstile(string $token, string $secret): bool
    {
        if (!$this->httpClient) return false; // pas de client HTTP => verification impossible
        $response = $this->httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token],
        ]);
        $data = $response->toArray();
        return $data['success'] ?? false;
    }

    private function verifyRecaptcha(string $token, string $secret): bool
    {
        if (!$this->httpClient) return false;
        $response = $this->httpClient->request('POST', 'https://www.google.com/recaptcha/api/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token],
        ]);
        $data = $response->toArray();
        return $data['success'] ?? false;
    }

    private function verifyHcaptcha(string $token, string $secret): bool
    {
        if (!$this->httpClient) return false;
        $response = $this->httpClient->request('POST', 'https://hcaptcha.com/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token],
        ]);
        $data = $response->toArray();
        return $data['success'] ?? false;
    }

    public function checkBlocklistedDomain(string $email): bool
    {
        $domain = substr(strrchr($email, '@'), 1);
        return in_array(strtolower($domain ?: ''), self::BLOCKLISTED_DOMAINS, true);
    }

    /** @return float 0-1, > 0.7 = probable spam */
    public function detectSpamPatterns(array $data): float
    {
        $score = 0.0;
        $allText = strtolower(implode(' ', array_filter($data, 'is_string')));

        // Patterns de spam communs
        if (str_contains($allText, 'buy now')) $score += 0.2;
        if (str_contains($allText, 'click here')) $score += 0.1;
        if (str_contains($allText, 'casino')) $score += 0.3;
        if (str_contains($allText, 'viagra')) $score += 0.4;
        if (preg_match('/(http|www\.)\S+/i', $allText)) $score += 0.2;
        if (substr_count($allText, '<a ') > 3) $score += 0.3;

        return min(1.0, $score);
    }
}
