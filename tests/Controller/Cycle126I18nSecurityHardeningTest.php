<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle126I18nSecurityHardeningTest extends TestCase
{
    public function testTranslationSymmetryAcrossAllFourLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $dicts = [];
        foreach ($locales as $loc) {
            $path = __DIR__ . "/../../translations/messages.{$loc}.php";
            $this->assertFileExists($path);
            $dicts[$loc] = require $path;
            $this->assertIsArray($dicts[$loc]);
        }

        $enCount = count($dicts['en']);
        $this->assertGreaterThanOrEqual(3789, $enCount, 'Must have at least 3,789 keys');

        foreach ($locales as $loc) {
            $this->assertCount(
                $enCount,
                $dicts[$loc],
                sprintf('Locale %s must have exactly %d keys, got %d', $loc, $enCount, count($dicts[$loc]))
            );
        }

        $enKeys = array_keys($dicts['en']);
        foreach (['fr', 'es', 'ar'] as $otherLoc) {
            $diff = array_diff($enKeys, array_keys($dicts[$otherLoc]));
            $this->assertEmpty($diff, sprintf('Locale %s is missing keys: %s', $otherLoc, implode(', ', array_slice($diff, 0, 5))));
        }
    }

    public function testCycle126KeysExistInAllLocales(): void
    {
        $criticalKeys = [
            'auth.welcome_back',
            'auth.sign_in_subtitle',
            'auth.email_address',
            'auth.sign_in',
            'auth.brand_hero_badge',
            'auth.brand_hero_title',
            'auth.brand_hero_desc',
            'auth.brand_stat_collections',
            'auth.brand_stat_api_ready',
            'auth.brand_stat_headless',
            'auth.client_id',
            'auth.client_secret',
            'email.two_factor_title',
            'email.two_factor_body',
            'email.two_factor_expires',
            'email.two_factor_ignore',
            'health.db_connection_failed',
            'webhook.test_ping_message',
            'common.enabled',
            'common.column_settings',
            'common.show_all',
            'common.reset_table',
            'common.reset_table_tooltip',
            'common.selected_count',
            'common.clear_selection',
            'nav.navigation_menu',
            'nav.toggle_sidebar',
            'studio.schema.validation_rules',
            'studio.schema.configured',
            'studio.schema.min_length',
            'studio.schema.max_length',
            'studio.schema.regex_invalid_message',
            'studio.schema.regex_invalid_ph',
            'studio.schema.min_value',
            'studio.schema.max_value',
            'studio.schema.unique_in_collection',
            'studio.schema.ai_chat_tab',
            'studio.schema.default_language',
            'studio.schema.define_data_model',
            'studio.schema.choose_collection_sidebar',
            'studio.schema.hide_in_lists',
            'studio.schema.read_only',
            'studio.schema.press_new_or_use_ai',
            'studio.code_exporter.target_language',
            'automations.cron_expression',
            'automations.cron_hint',
            'fields.options.slug_title',
            'fields.options.slug_attach',
            'fields.options.slug_hint',
            'fields.options.readonly',
            'fields.options.readonly_hint',
            'fields.options.editor_title',
            'fields.options.tinymce_editor',
            'fields.options.enum_title',
            'fields.options.enum_list',
            'fields.options.enum_ph',
            'fields.options.enum_add',
            'fields.options.enum_required',
            'fields.options.allow_multiple',
            'fields.options.allow_multiple_hint',
            'fields.options.date_title',
            'fields.options.include_time',
            'fields.options.include_time_hint',
            'fields.options.allow_range',
            'fields.options.allow_range_hint',
            'fields.options.media_title',
            'fields.options.single_file',
            'fields.options.multiple_files',
            'fields.options.relation_title',
            'fields.options.relation_collection',
            'fields.options.relation_collection_ph',
            'fields.options.relation_system_end_users',
            'fields.options.one_to_one',
            'fields.options.one_to_many',
            'fields.options.include_draft',
            'fields.options.include_draft_hint',
            'fields.options.custom_error_ph',
            'fields.options.unique_desc_alt',
            'fields.options.int_limitations',
            'fields.options.char_count',
            'fields.options.num_min_max_hint',
            'fields.options.char_min_max_hint',
            'fields.options.between',
            'fields.options.min',
            'fields.options.max',
            'fields.options.other_title',
            'fields.options.repeatable',
            'fields.options.repeatable_hint',
            'fields.options.hide_in_content_list',
            'fields.options.hide_in_content_list_hint',
            'fields.options.hidden_in_api',
            'fields.options.hidden_in_api_hint',
        ];

        foreach (['en', 'fr', 'es', 'ar'] as $loc) {
            $dict = require __DIR__ . "/../../translations/messages.{$loc}.php";
            foreach ($criticalKeys as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale {$loc} missing key {$key}");
                $this->assertNotEmpty($dict[$key], "Locale {$loc} key {$key} must not be empty");
            }
        }
    }

    public function testHealthControllerUsesTranslatorAndHidesSensitiveDetails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('Sensitive DB credentials host=secret-db.internal user=admin'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->with('health.db_connection_failed')
            ->willReturn('La connexion à la base de données a échoué');

        $controller = new class($connection, $translator) extends HealthController {
            protected function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
            {
                return sys_get_temp_dir();
            }
        };

        $response = $controller->check();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(503, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('fail', $data['status']);
        $this->assertSame('fail', $data['checks']['database']['status']);
        $this->assertSame('La connexion à la base de données a échoué', $data['checks']['database']['message']);

        // Assert sensitive exception details are NOT leaked to the response
        $this->assertStringNotContainsString('secret-db.internal', (string) $response->getContent());
        $this->assertStringNotContainsString('credentials', (string) $response->getContent());
    }

    public function testLoginTemplateUsesTranslationFilters(): void
    {
        $loginTemplate = (string) file_get_contents(__DIR__ . '/../../templates/security/login.html.twig');

        $this->assertStringContainsString("{{ 'auth.welcome_back'|trans }}", $loginTemplate);
        $this->assertStringContainsString("{{ 'auth.sign_in_subtitle'|trans }}", $loginTemplate);
        $this->assertStringContainsString("{{ 'auth.email_address'|trans }}", $loginTemplate);
        $this->assertStringContainsString("{{ 'auth.sign_in'|trans }}", $loginTemplate);
        $this->assertStringContainsString("{{ 'auth.brand_hero_badge'|trans }}", $loginTemplate);
        $this->assertStringContainsString("{{ 'auth.brand_stat_collections'|trans }}", $loginTemplate);
        $this->assertStringContainsString("{{ 'auth.brand_stat_api_ready'|trans }}", $loginTemplate);
        $this->assertStringContainsString("{{ 'auth.brand_stat_headless'|trans }}", $loginTemplate);
    }

    public function testTwoFactorEmailTemplateUsesTranslationFilters(): void
    {
        $emailTemplate = (string) file_get_contents(__DIR__ . '/../../templates/email/two_factor_code.html.twig');

        $this->assertStringContainsString("{{ 'email.two_factor_title'|trans }}", $emailTemplate);
        $this->assertStringContainsString("{{ 'email.two_factor_body'|trans }}", $emailTemplate);
        $this->assertStringContainsString("{{ 'email.two_factor_expires'|trans }}", $emailTemplate);
        $this->assertStringContainsString("{{ 'email.two_factor_ignore'|trans }}", $emailTemplate);
    }
}
