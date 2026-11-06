<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Form;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle #116 — Inbound Forms, Webhooks & Administration Controllers Security Hardening:
 * - Content-Type validation (415 UNSUPPORTED_MEDIA_TYPE) on public forms, webhooks, api-keys, mailer, storage, jwt-ttl
 * - Pre-flight Content-Length check (413 PAYLOAD_TOO_LARGE)
 * - Strict JSON payload validation (400 INVALID_JSON_PAYLOAD)
 * - Anti-tampering and security headers on responses
 * - Complete 4-locale i18n parity ({ar, en, es, fr}) for all new translation keys
 */
class Cycle116InboundSecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Project $project;
    private Form $form;
    private string $patPlain;
    private string $projectUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->user = new User();
        $this->user->email = "inbound_c116_{$suffix}@example.com";
        $this->user->password = 'hashed_pass_123';
        $this->user->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->user);

        $this->project = new Project();
        $this->project->uuid = Uuid::v4();
        $this->project->name = "Inbound Hardened Project C116 {$suffix}";
        $this->project->publicApi = true;
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->user;
        $member->email = $this->user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->form = new Form();
        $this->form->project = $this->project;
        $this->form->name = 'Feedback Form';
        $this->form->slug = 'feedback';
        $this->form->fields = [
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false],
        ];
        $this->form->steps = [];
        $this->form->settings = ['active' => true];
        $this->em->persist($this->form);

        $this->patPlain = 'jbo_pat_' . bin2hex(random_bytes(24));
        $pat = new PersonalAccessToken();
        $pat->user = $this->user;
        $pat->name = 'Cycle 116 Admin Token';
        $pat->tokenHash = hash('sha256', $this->patPlain);
        $pat->scopes = ['*'];
        $this->em->persist($pat);

        $this->em->flush();
        $this->projectUuid = (string) $this->project->uuid;
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->patPlain,
            'HTTP_ACCEPT'        => 'application/json',
        ], $extra);
    }

    public function testFormSubmitRejectsUnsupportedContentTypeWith415(): void
    {
        $this->client->request(
            'POST',
            "/{$this->projectUuid}/forms/feedback/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'email=user@example.com'
        );

        $this->assertResponseStatusCodeSame(415);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $res['code']);
        $this->assertNotEmpty($res['error']);
        $this->assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testFormSubmitRejectsMalformedJsonWith400(): void
    {
        $this->client->request(
            'POST',
            "/{$this->projectUuid}/forms/feedback/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"email": "broken-json'
        );

        $this->assertResponseStatusCodeSame(400);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_JSON_PAYLOAD', $res['code']);
        $this->assertNotEmpty($res['error']);
    }

    public function testFormSubmitRejectsExcessiveContentLengthHeaderWith413(): void
    {
        $this->client->request(
            'POST',
            "/{$this->projectUuid}/forms/feedback/submit",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_CONTENT_LENGTH' => '2097152', // 2 MB > 1 MB limit
            ],
            json_encode(['email' => 'valid@example.com'])
        );

        $this->assertResponseStatusCodeSame(413);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $res['code']);
    }

    public function testFormSubmitAcceptsValidJsonAndFormUrlEncoded(): void
    {
        // 1. JSON
        $this->client->request(
            'POST',
            "/{$this->projectUuid}/forms/feedback/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user1@example.com', 'message' => 'Super service!'])
        );
        $this->assertResponseStatusCodeSame(201);
        $jsonRes = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($jsonRes['success']);

        // 2. URL-encoded form
        $this->client->request(
            'POST',
            "/{$this->projectUuid}/forms/feedback/submit",
            ['email' => 'user2@example.com', 'message' => 'Merci!'],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']
        );
        $this->assertResponseStatusCodeSame(201);
        $formRes = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($formRes['success']);
    }

    public function testWebhookCreateRejectsUnsupportedContentType(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/webhooks",
            [],
            [],
            ['CONTENT_TYPE' => 'application/xml'],
            '<xml></xml>'
        );

        $this->assertResponseStatusCodeSame(415);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $res['code']);
    }

    public function testApiKeyStoreRejectsUnsupportedContentType(): void
    {
        $this->client->request(
            'POST',
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            [],
            [],
            $this->authHeaders(['CONTENT_TYPE' => 'text/html']),
            '<h1>bad</h1>'
        );

        $this->assertResponseStatusCodeSame(415);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $res['code']);
    }

    public function testProjectMailerUpdateRejectsUnsupportedContentType(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request(
            'PUT',
            "/api/admin/projects/{$this->projectUuid}/mailer",
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'host=smtp.example.com'
        );

        $this->assertResponseStatusCodeSame(415);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $res['code']);
    }

    public function testProjectStorageUpdateConfigRejectsUnsupportedContentType(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request(
            'PUT',
            "/api/admin/projects/{$this->projectUuid}/storage",
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'strategy=rules'
        );

        $this->assertResponseStatusCodeSame(415);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $res['code']);
    }

    public function testProjectJwtTtlUpdateRejectsUnsupportedContentType(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request(
            'PATCH',
            "/api/admin/projects/{$this->projectUuid}/jwt-ttl",
            [],
            [],
            ['CONTENT_TYPE' => 'application/xml'],
            '<ttl>3600</ttl>'
        );

        $this->assertResponseStatusCodeSame(415);
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $res['code']);
    }

    public function testTranslationsParityAcrossAllFourLocales(): void
    {
        $translator = $this->client->getContainer()->get(TranslatorInterface::class);

        $keys = [
            'form.unsupported_content_type',
            'form.invalid_json_payload',
            'webhook.unsupported_content_type',
            'api_key.unsupported_content_type',
            'token.unsupported_content_type',
            'project.mailer.unsupported_content_type',
            'project.storage.unsupported_content_type',
            'project.jwt_ttl.unsupported_content_type',
        ];

        $locales = ['en', 'fr', 'es', 'ar'];

        foreach ($locales as $locale) {
            foreach ($keys as $key) {
                $translation = $translator->trans($key, [], 'messages', $locale);
                $this->assertNotEmpty($translation, "Missing translation for key '{$key}' in locale '{$locale}'");
                $this->assertNotSame($key, $translation, "Key '{$key}' was not translated for locale '{$locale}'");
            }
        }
    }
}
