<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle 32 — I18N migration: InstallController & ProjectController
 *
 * Tests verify:
 * - InstallController uses translator for all responses
 * - ProjectController uses translator for all error/success responses
 * - Translation keys exist in all 4 locales (en, fr, es, ar)
 */
class Cycle32HardeningTest extends WebTestCase
{
    // -----------------------------------------------------------------
    // A. Translation key parity checks
    // -----------------------------------------------------------------

    public function testInstallTranslationKeysExistInAllLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $keys = [
            'install.already_installed_error',
            'install.too_many_attempts',
            'install.super_admin_exists',
            'install.invalid_json',
            'install.email_required',
            'install.super_admin_created',
            'install.diagnostic_title',
            'install.diagnostic_heading',
            'install.diagnostic_intro',
            'install.diagnostic_ready',
            'install.diagnostic_not_ready',
            'install.check_ok',
            'install.check_error',
            'install.php_version_check',
            'install.extension_check',
            'install.writable_dir_check',
        ];

        foreach ($locales as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file, "Translation file for locale '$locale' must exist.");
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Key '$key' missing in locale '$locale'.");
                self::assertNotEmpty($catalog[$key], "Key '$key' in locale '$locale' must not be empty.");
            }
        }
    }

    public function testProjectTranslationKeysExistInAllLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $keys = [
            'project.invalid_json',
            'project.name_required',
            'project.name_empty',
            'project.preview_url_invalid',
            'project.preview_url_scheme_invalid',
            'project.jwt_access_ttl_min',
            'project.jwt_access_ttl_max',
            'project.jwt_refresh_ttl_min',
            'project.jwt_refresh_ttl_max',
            'project.jwt_refresh_lt_access',
            'project.permission_denied_members',
            'project.role_not_found',
            'project.user_not_found',
            'project.user_already_member',
            'project.email_invalid',
            'project.email_already_member',
            'project.user_id_or_email_required',
            'project.member_not_found',
            'project.last_active_member',
            'project.last_manage_member',
            'project.not_found',
            'project.access_denied',
        ];

        foreach ($locales as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file);
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Key '$key' missing in locale '$locale'.");
                self::assertNotEmpty($catalog[$key], "Key '$key' in locale '$locale' must not be empty.");
            }
        }
    }

    // -----------------------------------------------------------------
    // B. Translation key count parity (all 4 locales must match)
    // -----------------------------------------------------------------

    public function testAllLocalesHaveSameKeyCount(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $counts  = [];
        foreach ($locales as $locale) {
            $file     = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            $catalog  = require $file;
            $counts[$locale] = count($catalog);
        }

        $enCount = $counts['en'];
        foreach ($locales as $locale) {
            self::assertSame(
                $enCount,
                $counts[$locale],
                "Locale '$locale' has {$counts[$locale]} keys but 'en' has $enCount. Parity broken."
            );
        }
    }

    // -----------------------------------------------------------------
    // C. InstallController HTTP behaviour (unauthenticated)
    // -----------------------------------------------------------------

    public function testInstallCheckReturnsDiagnosticsOrAlreadyInstalled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/install/check');
        $status = $client->getResponse()->getStatusCode();
        // 200 (not installed yet in test env) or 403 (already installed)
        self::assertContains($status, [200, 403]);

        if ($status === 200) {
            $data = json_decode($client->getResponse()->getContent(), true);
            self::assertArrayHasKey('ready', $data);
            self::assertArrayHasKey('checks', $data);
            self::assertIsArray($data['checks']);
        } elseif ($status === 403) {
            $data = json_decode($client->getResponse()->getContent(), true);
            self::assertArrayHasKey('error', $data);
            // Must NOT be a raw French hardcoded string — key should be translated
            self::assertStringNotContainsString("déjà installée", $data['error']);
        }
    }

    public function testSetupAdminRejectsInvalidEmail(): void
    {
        $client = static::createClient();

        // If already installed, skip test gracefully
        $client->request('GET', '/install/check');
        if ($client->getResponse()->getStatusCode() === 403) {
            self::markTestSkipped('Application already installed — setup-admin test skipped.');
        }

        $client->request('POST', '/install/setup-admin', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => 'not-an-email', 'password' => 'Password123!']));
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        // Should not be raw hardcoded French
        self::assertStringNotContainsString("adresse e-mail", strtolower($data['error']));
    }

    // -----------------------------------------------------------------
    // D. ProjectController I18N — anonymous access returns structured JSON
    // -----------------------------------------------------------------

    public function testProjectListRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/projects');
        $status = $client->getResponse()->getStatusCode();
        // Must be 401 (unauthenticated) — never 500
        self::assertNotSame(500, $status, 'GET /api/projects must not return 500 for anonymous users.');
        self::assertContains($status, [401, 403, 302], "Expected 401/403/302, got $status.");
    }

    public function testProjectShowReturnsJsonOnUnknownUuid(): void
    {
        $client = static::createClient();
        // Log in with test credentials if available, otherwise anonymous request
        $client->request('GET', '/api/projects/00000000-0000-0000-0000-000000000000', [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $status = $client->getResponse()->getStatusCode();
        // Should be 401, 403, or 404 — never 500
        self::assertContains($status, [401, 403, 404, 302]);
    }

    // -----------------------------------------------------------------
    // E. InstallController source code has no hardcoded French strings
    // -----------------------------------------------------------------

    public function testInstallControllerHasNoHardcodedFrenchStrings(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Controller/InstallController.php');
        self::assertStringNotContainsString('déjà installée', $source, 'Hardcoded French string "déjà installée" found in InstallController.');
        self::assertStringNotContainsString('Vérification de l\'environnement', $source, 'Hardcoded French diagnostic intro found.');
        self::assertStringNotContainsString('prêt pour Jambo API', $source, 'Hardcoded French "ready" string found.');
        self::assertStringNotContainsString('corriger les points', $source, 'Hardcoded French "not ready" string found.');
        self::assertStringNotContainsString('Super admin already initialized', $source, 'Hardcoded English string found in InstallController.');
        self::assertStringNotContainsString('Too many setup attempts', $source, 'Hardcoded English rate limit string found.');
        self::assertStringNotContainsString('Super admin created successfully', $source, 'Hardcoded English success string found.');
    }

    public function testProjectControllerHasNoHardcodedErrorStrings(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Controller/ProjectController.php');
        // These strings should now come from the translator
        self::assertStringNotContainsString("'Invalid JSON body'", $source, 'Hardcoded "Invalid JSON body" found in ProjectController.');
        self::assertStringNotContainsString("'name is required'", $source, 'Hardcoded "name is required" found.');
        self::assertStringNotContainsString("'name cannot be empty'", $source, 'Hardcoded "name cannot be empty" found.');
        self::assertStringNotContainsString("'Project not found'", $source, 'Hardcoded "Project not found" found.');
        self::assertStringNotContainsString("'Access denied'", $source, 'Hardcoded "Access denied" found.');
        self::assertStringNotContainsString("'Role not found.'", $source, 'Hardcoded "Role not found" found.');
        self::assertStringNotContainsString("'User not found.'", $source, 'Hardcoded "User not found" found.');
        self::assertStringNotContainsString("'Member not found.'", $source, 'Hardcoded "Member not found" found.');
    }
}
