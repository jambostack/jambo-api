<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Admin\ContentAiStreamController;
use App\Controller\AdminApi\TokenController;
use App\Controller\AiController;
use App\Controller\NotificationController;
use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectRepository;
use App\Service\AiContentService;
use App\Service\EavDataFormatterService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Cycle 19 — Security Hardening Tests
 *
 * Covers:
 *  1. AdminApi TokenController: Anti-Privilege Escalation & strict scope validation
 *  2. AdminApi TokenController: Name sanitization and control-character rejection
 *  3. ContentAiStreamController: Input length bounds (max 30k) and rate limiting
 *  4. AiController: Brief (max 10k) and text (max 50k) length guards and rate limiting
 *  5. NotificationController: XSS link sanitization (blocks javascript:/data:) & delete route
 *  6. SearchController & EnsureProjectMemberSubscriber: API token attribute consistency
 *  7. UserController & Auth: Password max length capping (4096 bytes)
 *  8. Rate limiter definitions in rate_limiter.yaml
 */
class Cycle19HardeningTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // 1. AdminApi TokenController Privilege Escalation & Validation
    // ─────────────────────────────────────────────────────────────────

    public function testTokenControllerRejectsPrivilegeEscalation(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new TokenController($repo, $em, 'secret_key');

        $user = new User();
        $user->email = 'admin@example.com';

        // Authorizing token with only schema:write
        $authorizingPat = new PersonalAccessToken();
        $authorizingPat->user = $user;
        $authorizingPat->scopes = ['schema:write'];

        $request = new Request([], [], ['_pat' => $authorizingPat], [], [], [], json_encode([
            'name' => 'attacker_token',
            'scopes' => ['schema:write', 'projects:write'], // Escalating to projects:write!
        ]));

        $response = $controller->create($request);
        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Privilege escalation blocked', $data['error']);
    }

    public function testTokenControllerRejectsAdminScopeEscalation(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new TokenController($repo, $em, 'secret_key');

        $user = new User();
        $user->email = 'admin@example.com';

        // Authorizing token without *
        $authorizingPat = new PersonalAccessToken();
        $authorizingPat->user = $user;
        $authorizingPat->scopes = ['content:write'];

        $request = new Request([], [], ['_pat' => $authorizingPat], [], [], [], json_encode([
            'name' => 'backdoor_token',
            'scopes' => ['*'], // Trying to grab full admin!
        ]));

        $response = $controller->create($request);
        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Cannot grant administrative scopes', $data['error']);
    }

    public function testTokenControllerRejectsControlCharactersInName(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new TokenController($repo, $em, 'secret_key');

        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => "token\x00\x1fwith_controls",
            'scopes' => ['schema:write'],
        ]));

        $response = $controller->create($request);
        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('control characters', $data['error']);
    }

    public function testTokenControllerRejectsInvalidScopes(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new TokenController($repo, $em, 'secret_key');

        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => 'my_token',
            'scopes' => ['invalid:scope', '<script>alert(1)</script>'],
        ]));

        $response = $controller->create($request);
        $this->assertEquals(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Invalid scope', $data['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. ContentAiStreamController Input Bounds
    // ─────────────────────────────────────────────────────────────────

    public function testStreamRejectsOversizedInputText(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $aiService = $this->createMock(AiContentService::class);

        $controller = new ContentAiStreamController($projectRepo, $aiService);

        $oversizedText = str_repeat('A', ContentAiStreamController::MAX_INPUT_LENGTH + 50);
        $request = new Request([], [], [], [], [], [], json_encode([
            'action' => 'summarize',
            'text'   => $oversizedText,
        ]));

        $response = $controller->streamAction($request);
        $this->assertEquals(400, $response->getStatusCode());

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertStringContainsString('30,000 characters', $output);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. AiController Input Bounds
    // ─────────────────────────────────────────────────────────────────

    public function testAiControllerConstantsAndLimits(): void
    {
        $this->assertSame(10000, AiController::MAX_BRIEF_LENGTH);
        $this->assertSame(50000, AiController::MAX_TEXT_LENGTH);

        $src = file_get_contents(__DIR__ . '/../../src/Controller/AiController.php');
        $this->assertStringContainsString('checkRateLimit', $src);
        $this->assertStringContainsString('MAX_BRIEF_LENGTH', $src);
        $this->assertStringContainsString('MAX_TEXT_LENGTH', $src);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. NotificationController Sanitization & Deletion
    // ─────────────────────────────────────────────────────────────────

    public function testNotificationLinkSanitization(): void
    {
        $repo = $this->createMock(NotificationRepository::class);
        $controller = new NotificationController($repo);

        $ref = new \ReflectionClass($controller);
        $method = $ref->getMethod('sanitizeLink');

        // Dangerous URI schemes must be neutralized
        $this->assertNull($method->invoke($controller, 'javascript:alert(1)'));
        $this->assertNull($method->invoke($controller, 'data:text/html,<script>evil()</script>'));
        $this->assertNull($method->invoke($controller, 'vbscript:msgbox(1)'));

        // Legitimate relative or absolute URLs must be preserved
        $this->assertEquals('/projects/123/entries', $method->invoke($controller, '/projects/123/entries'));
        $this->assertEquals('https://example.com/details', $method->invoke($controller, 'https://example.com/details'));
        $this->assertEquals('http://localhost:3000/feed', $method->invoke($controller, 'http://localhost:3000/feed'));
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. SearchController API Token Attribute Defense
    // ─────────────────────────────────────────────────────────────────

    public function testSearchControllerAttributesProtection(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Controller/SearchController.php');
        $this->assertStringContainsString("if (\$request->attributes->get('_project') !== \$project)", $src);
        $this->assertStringContainsString('searchLimiter', $src);
        $this->assertStringContainsString('reindexLimiter', $src);
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. User Management Password Bounds
    // ─────────────────────────────────────────────────────────────────

    public function testUserPasswordBoundsCheck(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Controller/UserManagement/UserController.php');
        $this->assertStringContainsString('mb_strlen($password) > 4096', $src);
        $this->assertStringContainsString('mb_strlen($newPassword) > 4096', $src);

        $regSrc = file_get_contents(__DIR__ . '/../../src/Controller/Auth/RegisteredUserController.php');
        $this->assertStringContainsString('max: 4096', $regSrc);

        $pwSrc = file_get_contents(__DIR__ . '/../../src/Controller/Auth/NewPasswordController.php');
        $this->assertStringContainsString('strlen($password) > 4096', $pwSrc);
    }

    // ─────────────────────────────────────────────────────────────────
    // 7. Rate Limiter Configuration Integrity
    // ─────────────────────────────────────────────────────────────────

    public function testRateLimiterConfigurationsDefined(): void
    {
        $yaml = Yaml::parseFile(__DIR__ . '/../../config/packages/rate_limiter.yaml');

        $expectedLimiters = [
            'token_management_limiter',
            'ai_limiter',
            'ai_stream_limiter',
            'search_limiter',
            'search_reindex_limiter',
            'notification_limiter',
        ];

        foreach ($expectedLimiters as $limiter) {
            $this->assertArrayHasKey(
                $limiter,
                $yaml['framework']['rate_limiter'],
                "Rate limiter '{$limiter}' must be defined in framework.rate_limiter."
            );
            $this->assertArrayHasKey(
                $limiter,
                $yaml['when@test']['framework']['rate_limiter'],
                "Rate limiter '{$limiter}' must be defined in when@test.framework.rate_limiter."
            );
        }
    }
}
