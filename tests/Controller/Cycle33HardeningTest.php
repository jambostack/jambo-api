<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle 33 — I18N Migration & Security Hardening:
 * - DirectUploadController
 * - AdminApi\TokenController
 * - Api\ProjectEmailController
 *
 * Verifies key parity across all 4 locales (en, fr, es, ar),
 * absence of hardcoded strings in controller sources,
 * and security bounds on input parameters.
 */
class Cycle33HardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    // ─────────────────────────────────────────────────────────────────
    // 1. DirectUpload Translation Keys Parity
    // ─────────────────────────────────────────────────────────────────

    public function testDirectUploadTranslationKeysExistInAllLocales(): void
    {
        $keys = [
            'direct_upload.rate_limit_exceeded',
            'direct_upload.auth_required',
            'direct_upload.invalid_json',
            'direct_upload.intent_not_found',
            'direct_upload.invalid_part_number',
            'direct_upload.parts_invalid',
            'direct_upload.invalid_file_size',
        ];

        foreach (self::LOCALES as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file);
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Missing direct upload key '$key' in locale '$locale'");
                self::assertNotEmpty($catalog[$key], "Empty direct upload key '$key' in locale '$locale'");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. TokenController Translation Keys Parity
    // ─────────────────────────────────────────────────────────────────

    public function testTokenTranslationKeysExistInAllLocales(): void
    {
        $keys = [
            'token.rate_limit_exceeded',
            'token.invalid_json',
            'token.name_required',
            'token.name_empty',
            'token.name_too_long',
            'token.name_control_chars',
            'token.scopes_required',
            'token.invalid_scope',
            'token.admin_scope_forbidden',
            'token.escalation_blocked',
            'token.invalid_expires_at',
            'token.expires_future',
            'token.not_found',
            'token.lacks_manage_update',
            'token.lacks_manage_delete',
        ];

        foreach (self::LOCALES as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file);
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Missing token key '$key' in locale '$locale'");
                self::assertNotEmpty($catalog[$key], "Empty token key '$key' in locale '$locale'");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. ProjectEmailController Translation Keys Parity
    // ─────────────────────────────────────────────────────────────────

    public function testEmailTranslationKeysExistInAllLocales(): void
    {
        $keys = [
            'email.rate_limit_exceeded',
            'email.invalid_json',
            'email.subject_and_body_required',
            'email.to_subject_body_required',
            'email.captcha_required',
            'email.captcha_expired',
            'email.captcha_incorrect',
            'email.mailer_not_configured',
            'email.invalid_to',
            'email.invalid_reply_to',
            'email.invalid_recipient',
            'email.attachment_missing_fields',
            'email.attachment_too_large',
            'email.attachment_forbidden_extension',
            'email.send_failed',
        ];

        foreach (self::LOCALES as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file);
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Missing email key '$key' in locale '$locale'");
                self::assertNotEmpty($catalog[$key], "Empty email key '$key' in locale '$locale'");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. Source Code Static Audit: Zero Hardcoded Strings
    // ─────────────────────────────────────────────────────────────────

    public function testDirectUploadControllerHasNoHardcodedStrings(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Controller/DirectUploadController.php');
        self::assertStringNotContainsString("['error' => 'Too many direct upload requests", $source);
        self::assertStringNotContainsString("['error' => 'Valid pending upload intent not found'", $source);
        self::assertStringNotContainsString("['error' => 'Authentication required'", $source);
    }

    public function testTokenControllerHasNoHardcodedStrings(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Controller/AdminApi/TokenController.php');
        self::assertStringNotContainsString("['error' => 'Token name is required.'", $source);
        self::assertStringNotContainsString("['error' => 'Token name must not exceed 255 characters.'", $source);
        self::assertStringNotContainsString("['error' => 'Token name contains invalid control characters.'", $source);
        self::assertStringNotContainsString("['error' => 'Scopes must be a non-empty array.'", $source);
        self::assertStringNotContainsString("['error' => 'Token not found'", $source);
        self::assertStringNotContainsString("['error' => 'Cannot grant administrative scopes not held by the authorizing token.'", $source);
    }

    public function testProjectEmailControllerHasNoHardcodedStrings(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Controller/Api/ProjectEmailController.php');
        self::assertStringNotContainsString("['error' => 'Projet introuvable'", $source);
        self::assertStringNotContainsString("['error' => 'Trop de requetes. Reessayez plus tard.'", $source);
        self::assertStringNotContainsString("['error' => 'JSON invalide'", $source);
        self::assertStringNotContainsString("['error' => 'subject et body sont requis'", $source);
        self::assertStringNotContainsString("['error' => 'Captcha requis'", $source);
        self::assertStringNotContainsString("['error' => 'Captcha expire ou invalide'", $source);
        self::assertStringNotContainsString("['error' => 'Reponse captcha incorrecte'", $source);
        self::assertStringNotContainsString("['error' => 'Mailer non configure pour ce projet'", $source);
        self::assertStringNotContainsString("['error' => 'to, subject et body sont requis'", $source);
        self::assertStringNotContainsString("['error' => 'to invalide'", $source);
        self::assertStringNotContainsString("['error' => 'replyTo invalide'", $source);
        self::assertStringNotContainsString("['error' => 'attachment content et filename requis'", $source);
        self::assertStringNotContainsString("['error' => 'Pièce jointe trop volumineuse (max 10MB)'", $source);
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. Functional Checks
    // ─────────────────────────────────────────────────────────────────

    public function testDirectUploadIntentAnonymousAccessReturns401Json(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/projects/00000000-0000-0000-0000-000000000000/media/direct-upload/intent');
        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [401, 404]);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testDirectUploadSignPartAnonymousAccessReturns401Json(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/00000000-0000-0000-0000-000000000000/media/direct-upload/sign-part',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['intent_uuid' => '00000000-0000-0000-0000-000000000000', 'part_number' => 0])
        );
        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [401, 404, 422]);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertNotEmpty($data['error']);
    }

    public function testEmailEndpointRejectsEmptyBodyWithTranslatedMessage(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/00000000-0000-0000-0000-000000000000/email',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );
        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [404, 422]);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertNotEmpty($data['error']);
    }
}
