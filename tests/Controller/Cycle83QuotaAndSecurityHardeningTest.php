<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\Share;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Enum\ShareDuration;
use App\Service\Automation\AutomationQuotaService;
use App\Service\PreviewTokenService;
use App\Service\Share\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle #83 — Automation Run Quotas by Tier, Public Share / Preview CSP & HSTS Hardening,
 * and 100% Strict I18n Verification (en, fr, es, ar).
 */
class Cycle83QuotaAndSecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    private function createSuperAdmin(): User
    {
        $user = new User();
        $user->email = 'c83_admin_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->name = 'Super Admin C83';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $user->password = 'hashed_pass_c83';
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function createProjectWithMember(string $tier = 'free', ?int $customQuota = null): array
    {
        $user = new User();
        $user->email = 'c83_member_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->name = 'Member C83';
        $user->roles = ['ROLE_USER'];
        $user->password = 'hashed_pass_c83';
        $this->em->persist($user);

        $project = new Project();
        $project->name = 'C83 Project ' . bin2hex(random_bytes(3));
        $project->previewEnabled = true;
        $settings = ['tier' => $tier];
        if ($customQuota !== null) {
            $settings['automation_runs_limit_daily'] = $customQuota;
        }
        $project->settings = $settings;
        $this->em->persist($project);

        $permManage = $this->em->getRepository(Permission::class)->findOneBy(['name' => 'project.manage']);
        if (!$permManage) {
            $permManage = new Permission();
            $permManage->name = 'project.manage';
            $permManage->label = 'Manage Project';
            $permManage->group = 'project';
            $this->em->persist($permManage);
        }

        $permView = $this->em->getRepository(Permission::class)->findOneBy(['name' => 'project.view']);
        if (!$permView) {
            $permView = new Permission();
            $permView->name = 'project.view';
            $permView->label = 'View Project';
            $permView->group = 'project';
            $this->em->persist($permView);
        }

        $permUpdate = $this->em->getRepository(Permission::class)->findOneBy(['name' => 'content.update']);
        if (!$permUpdate) {
            $permUpdate = new Permission();
            $permUpdate->name = 'content.update';
            $permUpdate->label = 'Update Content';
            $permUpdate->group = 'content';
            $this->em->persist($permUpdate);
        }

        $role = new Role();
        $role->name = 'Role_C83_' . bin2hex(random_bytes(3));
        $role->permissions->add($permManage);
        $role->permissions->add($permView);
        $role->permissions->add($permUpdate);
        $this->em->persist($role);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $member->role = $role;
        $this->em->persist($member);

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;
        $this->em->persist($collection);

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->status = 'published';
        $this->em->persist($entry);

        $this->em->flush();

        return [$user, $project, $collection, $entry];
    }

    public function testPublicShareSecurityHeadersAndCSP(): void
    {
        [, , , $entry] = $this->createProjectWithMember();
        $shareService = $this->client->getContainer()->get(ShareService::class);
        $res = $shareService->create($entry, ShareDuration::D7, null);
        $token = $res['plainToken'];

        // HTML endpoint
        $this->client->request('GET', "/share/{$token}");
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        // CSP
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);

        // HSTS & Headers
        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));

        // JSON endpoint
        $this->client->request('GET', "/share/{$token}.json");
        $jsonResponse = $this->client->getResponse();
        $this->assertSame(200, $jsonResponse->getStatusCode());
        $this->assertSame('max-age=31536000; includeSubDomains', $jsonResponse->headers->get('Strict-Transport-Security'));
        $this->assertSame('DENY', $jsonResponse->headers->get('X-Frame-Options'));
        $this->assertNotEmpty($jsonResponse->headers->get('Content-Security-Policy'));
    }

    public function testPublicShareHtmlI18nRendering(): void
    {
        [, , , $entry] = $this->createProjectWithMember();
        $shareService = $this->client->getContainer()->get(ShareService::class);
        $res = $shareService->create($entry, ShareDuration::D7, null);
        $token = $res['plainToken'];

        // FR request
        $this->client->request('GET', "/share/{$token}", server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        $contentFr = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Aperçu partagé', $contentFr);
        $this->assertStringContainsString('Propulsé par Jambo', $contentFr);
        $this->assertStringContainsString('Statut :', $contentFr);

        // EN request
        $this->client->request('GET', "/share/{$token}", server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        $contentEn = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Shared Preview', $contentEn);
        $this->assertStringContainsString('Powered by Jambo', $contentEn);
        $this->assertStringContainsString('Status:', $contentEn);
    }

    public function testPublicShareErrorSecurityHeadersAndI18n(): void
    {
        // HTML 404
        $this->client->request('GET', '/share/invalid_share_token_c83');
        $resp = $this->client->getResponse();
        $this->assertSame(404, $resp->getStatusCode());
        $this->assertSame('max-age=31536000; includeSubDomains', $resp->headers->get('Strict-Transport-Security'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertNotEmpty($resp->headers->get('Content-Security-Policy'));

        // JSON 404 with translated message
        $this->client->request('GET', '/share/invalid_share_token_c83.json');
        $jsonResp = $this->client->getResponse();
        $this->assertSame(404, $jsonResp->getStatusCode());
        $data = json_decode((string) $jsonResp->getContent(), true);
        $this->assertSame('invalid', $data['error']);
        $this->assertNotEmpty($data['message']);
    }

    public function testPreviewControllerSecurityHeaders(): void
    {
        [$user, $project, $collection, $entry] = $this->createProjectWithMember();
        $tokenService = $this->client->getContainer()->get(PreviewTokenService::class);
        $token = $tokenService->createToken($entry, 300);

        $projectUuid = $project->uuid->toRfc4122();
        $entryUuid = $entry->uuid->toRfc4122();
        $colSlug = $collection->slug;

        $this->client->request('GET', "/api/projects/{$projectUuid}/preview/content/{$colSlug}/{$entryUuid}?token={$token}");
        $resp = $this->client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());

        $this->assertSame('max-age=31536000; includeSubDomains', $resp->headers->get('Strict-Transport-Security'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $resp->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $resp->headers->get('Content-Security-Policy'));
    }

    public function testAutomationQuotaServiceTierLimitsAndUsage(): void
    {
        $runRepo = $this->em->getRepository(AutomationRun::class);
        $quotaService = new AutomationQuotaService($runRepo);

        // Free tier project
        $freeProject = new Project();
        $freeProject->name = 'Free Project';
        $freeProject->settings = ['tier' => 'free'];
        $this->assertSame(100, $quotaService->getDailyLimit($freeProject));
        $this->assertSame('free', $quotaService->getProjectTier($freeProject));

        // Starter tier project
        $starterProject = new Project();
        $starterProject->name = 'Starter Project';
        $starterProject->settings = ['tier' => 'starter'];
        $this->assertSame(500, $quotaService->getDailyLimit($starterProject));

        // Pro tier project
        $proProject = new Project();
        $proProject->name = 'Pro Project';
        $proProject->settings = ['tier' => 'pro'];
        $this->assertSame(2500, $quotaService->getDailyLimit($proProject));

        // Enterprise tier project
        $entProject = new Project();
        $entProject->name = 'Enterprise Project';
        $entProject->settings = ['tier' => 'enterprise'];
        $this->assertSame(50000, $quotaService->getDailyLimit($entProject));

        // Custom override
        $customProject = new Project();
        $customProject->name = 'Custom Project';
        $customProject->settings = [
            'tier' => 'free',
            'automation_runs_limit_daily' => 42,
        ];
        $this->assertSame(42, $quotaService->getDailyLimit($customProject));

        // Check quota array structure
        $res = $quotaService->checkQuota($customProject);
        $this->assertTrue($res['allowed']);
        $this->assertSame(42, $res['limit']);
        $this->assertSame(0, $res['used']);
        $this->assertSame('free', $res['tier']);
        $this->assertGreaterThan(0, $res['resetInSeconds']);
    }

    public function testAutomationRunQuotaEnforcementInController(): void
    {
        [$user, $project] = $this->createProjectWithMember('free', 2);
        $this->client->loginUser($user);

        $automation = new Automation();
        $automation->project = $project;
        $automation->name = 'C83 Quota Flow';
        $automation->isActive = true;
        $automation->flowGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.schedule.cron', 'data' => []],
                ['id' => 'a1', 'type' => 'util.noop', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
        $this->em->persist($automation);
        $this->em->flush();

        $projectUuid = $project->uuid->toRfc4122();
        $automationId = $automation->id;

        // Run 1 -> Allowed (used: 0, limit: 2)
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automationId}/run");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Run 2 -> Allowed (used: 1, limit: 2)
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automationId}/run");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Run 3 -> Quota Exceeded (used: 2, limit: 2) -> 429
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automationId}/run");
        $resp = $this->client->getResponse();
        $this->assertSame(429, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('AUTOMATION_QUOTA_EXCEEDED', $data['code']);
        $this->assertSame(2, $data['limit']);
        $this->assertSame(2, $data['used']);
        $this->assertNotEmpty($resp->headers->get('Retry-After'));
    }

    public function testWebhookInboundQuotaEnforcement(): void
    {
        [, $project] = $this->createProjectWithMember('free', 1);

        $automation = new Automation();
        $automation->project = $project;
        $automation->name = 'Inbound Webhook Quota Flow';
        $automation->isActive = true;
        $automation->flowGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.webhook.inbound', 'data' => []],
                ['id' => 'a1', 'type' => 'util.noop', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
        $this->em->persist($automation);
        $this->em->flush();

        $automationUuid = $automation->uuid->toRfc4122();

        // 1st request -> executes and succeeds
        $this->client->request('POST', "/api/webhooks/inbound/{$automationUuid}", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['event' => 'ping_1']));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // 2nd request -> quota exceeded -> 429
        $this->client->request('POST', "/api/webhooks/inbound/{$automationUuid}", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['event' => 'ping_2']));
        $resp = $this->client->getResponse();
        $this->assertSame(429, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true);
        $this->assertSame('AUTOMATION_QUOTA_EXCEEDED', $data['code']);
        $this->assertNotEmpty($resp->headers->get('Retry-After'));
    }

    public function testStrictI18nAcrossAllFourLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $requiredKeys = [
            'public_share.show.title',
            'public_share.show.badge',
            'public_share.show.status',
            'public_share.show.footer',
            'public_share.error.title',
            'public_share.error.heading',
            'public_share.error.invalid',
            'public_share.error.expired',
            'public_share.error.too_many_requests',
            'public_share.error.unsupported_format',
            'automation.quota.exceeded',
            'automation.quota.daily_limit_reached',
        ];

        foreach ($locales as $locale) {
            $path = self::getContainer()->getParameter('kernel.project_dir') . "/translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation file missing for locale: {$locale}");
            $messages = require $path;
            $this->assertIsArray($messages, "Translation messages must be an array for locale: {$locale}");

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $messages, "Missing translation key '{$key}' in locale '{$locale}'");
                $this->assertNotEmpty($messages[$key], "Translation key '{$key}' must not be empty in locale '{$locale}'");
            }
        }
    }
}
