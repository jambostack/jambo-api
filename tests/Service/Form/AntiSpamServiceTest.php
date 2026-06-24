<?php
namespace App\Tests\Service\Form;

use App\Service\Form\AntiSpamService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AntiSpamServiceTest extends TestCase
{
    private AntiSpamService $service;

    protected function setUp(): void
    {
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );

        $this->service = new AntiSpamService($limiterFactory);
    }

    public function testCheckHoneypotDetectsSpam(): void
    {
        $this->assertTrue($this->service->checkHoneypot(['_website' => 'http://spam.com']));
    }

    public function testCheckHoneypotPassesWhenEmpty(): void
    {
        $this->assertFalse($this->service->checkHoneypot(['_website' => '']));
        $this->assertFalse($this->service->checkHoneypot(['name' => 'John']));
    }

    public function testCheckHoneypotWithCustomField(): void
    {
        $this->assertTrue($this->service->checkHoneypot(['my_hp' => 'spam'], 'my_hp'));
        $this->assertFalse($this->service->checkHoneypot(['my_hp' => ''], 'my_hp'));
    }

    public function testCheckRateLimitAllowsFirstRequests(): void
    {
        $this->assertFalse($this->service->checkRateLimit('192.168.1.1'));
    }

    public function testCheckRateLimitBlocksAfterExceeding(): void
    {
        $ip = '192.168.1.100';
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test2', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $restrictiveService = new AntiSpamService($limiterFactory);

        // First 2 requests should pass
        $this->assertFalse($restrictiveService->checkRateLimit($ip));
        $this->assertFalse($restrictiveService->checkRateLimit($ip));

        // Third request should be rate limited
        $this->assertTrue($restrictiveService->checkRateLimit($ip));
    }

    public function testVerifyCaptchaFailsWithEmptyToken(): void
    {
        $this->assertFalse($this->service->verifyCaptcha('', ['provider' => 'turnstile', 'secret' => 'secret']));
    }

    public function testVerifyCaptchaFailsWithEmptySecret(): void
    {
        $this->assertFalse($this->service->verifyCaptcha('token', []));
    }

    public function testVerifyCaptchaFailsWithUnknownProvider(): void
    {
        $this->assertFalse($this->service->verifyCaptcha('token', ['provider' => 'unknown', 'secret' => 's']));
    }

    public function testVerifyCaptchaSkipsWithoutHttpClient(): void
    {
        $this->assertFalse($this->service->verifyCaptcha('token', ['provider' => 'turnstile', 'secret' => 's']));
        $this->assertFalse($this->service->verifyCaptcha('token', ['provider' => 'recaptcha', 'secret' => 's']));
        $this->assertFalse($this->service->verifyCaptcha('token', ['provider' => 'hcaptcha', 'secret' => 's']));
    }

    public function testVerifyCaptchaWithHttpClientSuccess(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('toArray')->willReturn(['success' => true]);
        $httpClient->method('request')->willReturn($response);

        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test3', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $service = new AntiSpamService($limiterFactory, $httpClient);

        $this->assertTrue($service->verifyCaptcha('token', ['provider' => 'turnstile', 'secret' => 's']));
    }

    public function testVerifyCaptchaWithHttpClientFailure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('toArray')->willReturn(['success' => false]);
        $httpClient->method('request')->willReturn($response);

        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test4', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $service = new AntiSpamService($limiterFactory, $httpClient);

        $this->assertFalse($service->verifyCaptcha('token', ['provider' => 'turnstile', 'secret' => 's']));
    }

    public function testCheckBlocklistedDomain(): void
    {
        $this->assertTrue($this->service->checkBlocklistedDomain('test@mailinator.com'));
        $this->assertTrue($this->service->checkBlocklistedDomain('test@yopmail.com'));
        $this->assertFalse($this->service->checkBlocklistedDomain('test@gmail.com'));
        $this->assertFalse($this->service->checkBlocklistedDomain('no-at-sign'));
    }

    public function testDetectSpamPatternsReturnsZeroForCleanData(): void
    {
        $score = $this->service->detectSpamPatterns(['name' => 'John Doe', 'message' => 'Hello, how are you?']);
        $this->assertEquals(0.0, $score);
    }

    public function testDetectSpamPatternsDetectsSpamKeywords(): void
    {
        $score = $this->service->detectSpamPatterns(['message' => 'Buy now! Click here for casino']);
        $this->assertGreaterThanOrEqual(0.6, $score);
    }

    public function testDetectSpamPatternsDetectsLinks(): void
    {
        $score = $this->service->detectSpamPatterns(['message' => 'Check this out http://spam.com']);
        $this->assertGreaterThanOrEqual(0.2, $score);
    }

    public function testDetectSpamPatternsCapsAtOne(): void
    {
        $score = $this->service->detectSpamPatterns([
            'message' => 'Buy now viagra casino click here http://spam.com <a href="x"> <a href="x"> <a href="x"> <a href="x">',
        ]);
        $this->assertEquals(1.0, $score);
    }
}
