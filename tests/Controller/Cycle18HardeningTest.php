<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\Form\AbTestManager;
use App\Entity\Form;
use PHPUnit\Framework\TestCase;

/**
 * Cycle 18 — Hardening Tests
 *
 * Covers:
 *  1. AbTestManager uses CSPRNG (random_int) instead of mt_rand
 *  2. DeploymentController::systemInfo() does not expose PHP version or environment
 *  3. InstallController::setupAdmin() is rate-limited (429 on exhaustion) — source check
 *  4. framework.yaml trusted_proxies configuration is set
 *  5. rate_limiter.yaml has install_setup_limiter definition
 */
class Cycle18HardeningTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // 1. AbTestManager CSPRNG
    // ─────────────────────────────────────────────────────────────────

    public function testAbTestManagerDoesNotUseMtRand(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Service/Form/AbTestManager.php');
        $this->assertStringNotContainsString('mt_rand', $src, 'AbTestManager must not use mt_rand (weak PRNG).');
        $this->assertStringContainsString('random_int', $src, 'AbTestManager must use random_int (CSPRNG).');
    }

    public function testAbTestManagerResolveVariantDistribution(): void
    {
        $manager = new AbTestManager();
        $form = new Form();
        $form->settings = [];
        $manager->enable($form, ['field' => 'A'], ['field' => 'B'], [0.5, 0.5]);

        $counts = [0, 0];
        for ($i = 0; $i < 200; $i++) {
            $result = $manager->resolveVariant($form);
            $this->assertNotNull($result);
            $counts[$result['variantIndex']]++;
        }

        // With 200 draws each should appear at least 50 times (~25% min tolerance)
        $this->assertGreaterThan(50, $counts[0], 'Variant A should appear frequently with 50/50 weights.');
        $this->assertGreaterThan(50, $counts[1], 'Variant B should appear frequently with 50/50 weights.');
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. DeploymentController::systemInfo() info disclosure
    // ─────────────────────────────────────────────────────────────────

    public function testSystemInfoDoesNotExposePhpVersion(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Controller/DeploymentController.php');

        // Verify the removed sensitive keys are not present as active array key => value pairs.
        // The comment may mention them, so we check for the actual assignment patterns.
        $this->assertStringNotContainsString("'php'          => PHP_VERSION", $src,
            'systemInfo() must not expose PHP_VERSION.');
        $this->assertStringNotContainsString("'php'       => PHP_VERSION", $src,
            'systemInfo() must not expose PHP_VERSION.');
        // Also check that the kernel.environment parameter reference is gone from systemInfo
        $this->assertStringNotContainsString(
            "'environment' => \$this->getParameter('kernel.environment')",
            $src,
            'systemInfo() must not expose the runtime environment to anonymous callers.'
        );
    }

    public function testSystemInfoCommentDocumentsOmittedFields(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Controller/DeploymentController.php');
        $this->assertStringContainsString('intentionally omitted', $src,
            'systemInfo() should have a comment explaining why environment and php are omitted.');
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. InstallController rate limiting on setup-admin
    // ─────────────────────────────────────────────────────────────────

    public function testInstallControllerInjectsRateLimiter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Controller/InstallController.php');
        $this->assertStringContainsString('install_setup_limiter', $src,
            'InstallController must inject the install_setup_limiter.');
        $this->assertStringContainsString('RateLimiterFactory', $src,
            'InstallController must use RateLimiterFactory.');
    }

    public function testInstallSetupAdminReturns429WhenRateLimitExhausted(): void
    {
        // RateLimiterFactory is final and cannot be mocked directly.
        // Verify via source code that the setupAdmin method correctly applies rate limiting.
        $src = file_get_contents(__DIR__ . '/../../src/Controller/InstallController.php');

        $this->assertStringContainsString('->consume(1)->isAccepted()', $src,
            'setupAdmin() must check the rate limit via consume()->isAccepted().');
        $this->assertStringContainsString('429', $src,
            'setupAdmin() must return HTTP 429 when the rate limit is exhausted.');

        // Extract just the setupAdmin() method body to validate ordering
        preg_match('/function setupAdmin\(.*?\}(?=\s*(?:private|public|protected|\}))/s', $src, $match);
        $methodBody = $match[0] ?? '';
        $this->assertNotEmpty($methodBody, 'Could not find setupAdmin() method body.');

        $rateLimitPos = strpos($methodBody, 'isAccepted()');
        $userCountPos = strpos($methodBody, '->userRepository->count');
        $this->assertLessThan($userCountPos, $rateLimitPos,
            'Rate-limit check must be performed before the user-count guard in setupAdmin().');
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. Trusted proxies configuration is present
    // ─────────────────────────────────────────────────────────────────

    public function testFrameworkYamlHasTrustedProxiesConfig(): void
    {
        $yaml = file_get_contents(__DIR__ . '/../../config/packages/framework.yaml');
        $this->assertStringContainsString('trusted_proxies', $yaml,
            'framework.yaml must configure trusted_proxies to prevent IP spoofing via X-Forwarded-For.');
        $this->assertStringContainsString('SYMFONY_TRUSTED_PROXIES', $yaml,
            'trusted_proxies should reference the SYMFONY_TRUSTED_PROXIES env variable.');
    }

    public function testRateLimiterYamlHasInstallSetupLimiter(): void
    {
        $yaml = file_get_contents(__DIR__ . '/../../config/packages/rate_limiter.yaml');
        $this->assertStringContainsString('install_setup_limiter', $yaml,
            'rate_limiter.yaml must define install_setup_limiter.');
    }
}
