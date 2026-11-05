<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Form;
use App\Entity\Project;
use App\Entity\Redirect;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class Cycle55PublicAuditTest extends WebTestCase
{
    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Cycle55 Test Project ' . bin2hex(random_bytes(4));
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function createForm(EntityManagerInterface $em, Project $project, string $slug = 'contact'): Form
    {
        $form = new Form();
        $form->project = $project;
        $form->name = 'Contact Us';
        $form->slug = $slug;
        $form->fields = [
            [
                'name' => 'email',
                'label' => 'Email Address',
                'type' => 'email',
                'required' => true,
            ],
            [
                'name' => 'message',
                'label' => 'Your Message',
                'type' => 'textarea',
                'required' => false,
            ],
            [
                'name' => 'subject',
                'label' => 'Subject',
                'type' => 'select',
                'options' => [
                    ['value' => 'support', 'label' => 'Support Request'],
                    ['value' => 'sales', 'label' => 'Sales Inquiry'],
                ],
                'required' => false,
            ],
        ];
        $form->steps = [];
        $form->settings = [];
        $em->persist($form);
        $em->flush();

        return $form;
    }

    private function createRedirect(
        EntityManagerInterface $em,
        Project $project,
        string $from,
        string $to,
        int $httpCode = 301,
        bool $isPattern = false
    ): Redirect {
        if ($project->id !== null && !$em->contains($project)) {
            $project = $em->find(Project::class, $project->id);
        }

        $user = new User();
        $user->email = 'user-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'secret';
        $user->roles = ['ROLE_USER'];
        $em->persist($user);

        $redirect = new Redirect();
        $redirect->project = $project;
        $redirect->createdBy = $user;
        $redirect->fromPath = $from;
        $redirect->toPath = $to;
        $redirect->httpCode = $httpCode;
        $redirect->isPattern = $isPattern;
        $redirect->isEnabled = true;
        $em->persist($redirect);
        $em->flush();

        return $redirect;
    }

    public function testFormPublicShowSuccessAndErrorHarmonization(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'feedback');

        $uuid = (string) $project->uuid;

        // 1. Happy path: show form via /{projectUuid}/forms/{slug}
        $client->request('GET', "/{$uuid}/forms/feedback");
        $this->assertResponseIsSuccessful();
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('Contact Us', $data['name']);
        $this->assertSame('feedback', $data['slug']);
        $this->assertTrue($data['definition_valid']);
        $this->assertCount(3, $data['fields']);

        // 2. Happy path: show form via /api/projects/{projectUuid}/forms/{slug}
        $client->request('GET', "/api/projects/{$uuid}/forms/feedback");
        $this->assertResponseIsSuccessful();

        // 3. Invalid UUID format
        $client->request('GET', "/invalid-uuid-format/forms/feedback");
        $this->assertResponseStatusCodeSame(404);
        $err = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $err['code']);
        $this->assertNotEmpty($err['error']);

        // 4. Invalid slug format
        $client->request('GET', "/{$uuid}/forms/bad!slug@chars");
        $this->assertResponseStatusCodeSame(400);
        $err = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG', $err['code']);
        $this->assertNotEmpty($err['error']);

        // 5. Non-existent form
        $client->request('GET', "/{$uuid}/forms/unknown-form");
        $this->assertResponseStatusCodeSame(404);
        $err = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('FORM_NOT_FOUND', $err['code']);
    }

    public function testFormPublicSubmitCapsAndValidation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'newsletter');
        $uuid = (string) $project->uuid;

        // 1. Happy path: submit valid form
        $client->request(
            'POST',
            "/{$uuid}/forms/newsletter/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['email' => 'subscriber@example.com', 'message' => 'Hello'])
        );
        $this->assertResponseStatusCodeSame(201);
        $res = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($res['success']);
        $this->assertNotEmpty($res['submission_id']);
        $this->assertFalse($res['is_spam']);
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        // 2. Submit via /api/projects/{projectUuid}/forms/{slug}/submit
        $client->request(
            'POST',
            "/api/projects/{$uuid}/forms/newsletter/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['email' => 'another@example.com', 'message' => 'World'])
        );
        $this->assertResponseStatusCodeSame(201);

        // 3. Honeypot triggers spam detection (returns 200, is_spam: true)
        $client->request(
            'POST',
            "/{$uuid}/forms/newsletter/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'bot@spammer.org', '_website' => 'http://spam.org'])
        );
        $this->assertResponseStatusCodeSame(200);
        $spamRes = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($spamRes['is_spam']);

        // 4. Missing required field -> 422 VALIDATION_FAILED
        $client->request(
            'POST',
            "/{$uuid}/forms/newsletter/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'Missing email'])
        );
        $this->assertResponseStatusCodeSame(422);
        $valErr = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $valErr['code']);
        $this->assertNotEmpty($valErr['errors']);
        $this->assertNotEmpty($valErr['validation_errors']);

        // 5. Payload too large (>1MB) -> 413 PAYLOAD_TOO_LARGE
        $giantContent = json_encode(['email' => 'test@example.com', 'message' => str_repeat('X', 1048577)]);
        $client->request(
            'POST',
            "/{$uuid}/forms/newsletter/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $giantContent
        );
        $this->assertResponseStatusCodeSame(413);
        $largeErr = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $largeErr['code']);

        // 6. Too many fields (>100 fields) -> 400 TOO_MANY_FIELDS
        $manyFields = ['email' => 'test@example.com'];
        for ($i = 0; $i < 105; $i++) {
            $manyFields["field_{$i}"] = "value_{$i}";
        }
        $client->request(
            'POST',
            "/{$uuid}/forms/newsletter/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($manyFields)
        );
        $this->assertResponseStatusCodeSame(400);
        $manyErr = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('TOO_MANY_FIELDS', $manyErr['code']);

        // 7. Field value too large (>64KB per field) -> 400 FIELD_VALUE_TOO_LARGE
        $largeField = ['email' => 'test@example.com', 'message' => str_repeat('A', 65540)];
        $client->request(
            'POST',
            "/{$uuid}/forms/newsletter/submit",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($largeField)
        );
        $this->assertResponseStatusCodeSame(400);
        $fErr = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('FIELD_VALUE_TOO_LARGE', $fErr['code']);
    }

    public function testRedirectPublicResolveJsonAndHtml(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $uuid = (string) $project->uuid;

        // Create exact and pattern redirects
        $this->createRedirect($em, $project, '/old-path', '/new-path', 301, false);
        $this->createRedirect($em, $project, '/blog/(.*)', '/articles/$1', 302, true);

        // 1. JSON resolve exact
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/old-path", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['redirected']);
        $this->assertSame('/old-path', $data['from']);
        $this->assertSame('/new-path', $data['to']);
        $this->assertSame(301, $data['httpCode']);

        // 2. JSON resolve pattern: /blog/article-123 should resolve to /articles/article-123
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/blog/article-123", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseIsSuccessful();
        $dataPattern = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($dataPattern['redirected']);
        $this->assertSame('/articles/article-123', $dataPattern['to']);
        $this->assertSame(302, $dataPattern['httpCode']);

        // 3. JSON resolve unmapped path
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/non-existent", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseIsSuccessful();
        $dataNone = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($dataNone['redirected']);
        $this->assertSame('/non-existent', $dataNone['path']);

        // 4. HTML Redirect response (when Accept: text/html)
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/old-path", [], [], ['HTTP_ACCEPT' => 'text/html']);
        $this->assertResponseStatusCodeSame(301);
        $this->assertSame('/new-path', $client->getResponse()->headers->get('Location'));
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        // 5. Path control characters check -> 400 INVALID_CONTROL_CHARS
        $client->request('GET', "/{$uuid}/redirects/resolve?path=" . urlencode("/test\x00path"));
        $this->assertResponseStatusCodeSame(400);
        $ctrlErr = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTROL_CHARS', $ctrlErr['code']);

        // 6. Path too long (>2048 chars) -> 400 PATH_TOO_LONG
        $longPath = '/' . str_repeat('a', 2050);
        $client->request('GET', "/{$uuid}/redirects/resolve?path=" . urlencode($longPath));
        $this->assertResponseStatusCodeSame(400);
        $lenErr = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PATH_TOO_LONG', $lenErr['code']);
    }

    public function testRedirectPublicSsrfAndUnsafeDestinationDefense(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $uuid = (string) $project->uuid;

        // 1. JavaScript scheme
        $this->createRedirect($em, $project, '/trigger-xss', 'javascript:alert(1)');
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/trigger-xss", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(400);
        $err1 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('UNSAFE_DESTINATION', $err1['code']);

        // 2. Protocol-relative open redirect
        $this->createRedirect($em, $project, '/trigger-proto', '//attacker.com/evil');
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/trigger-proto", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(400);
        $err2 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('UNSAFE_DESTINATION', $err2['code']);

        // 3. Backslash evasion
        $this->createRedirect($em, $project, '/trigger-backslash', '/\\evil.com');
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/trigger-backslash", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(400);
        $err3 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('UNSAFE_DESTINATION', $err3['code']);

        // 4. SSRF to Cloud metadata
        $this->createRedirect($em, $project, '/trigger-metadata', 'http://169.254.169.254/latest/meta-data');
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/trigger-metadata", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(400);
        $err4 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('UNSAFE_DESTINATION', $err4['code']);

        // 5. SSRF to localhost
        $this->createRedirect($em, $project, '/trigger-localhost', 'http://127.0.0.1:8000/admin');
        $client->request('GET', "/{$uuid}/redirects/resolve?path=/trigger-localhost", [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(400);
        $err5 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('UNSAFE_DESTINATION', $err5['code']);
    }

    public function testEmbedWidgetAndIframeRoutes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $form = $this->createForm($em, $project, 'survey');
        $uuid = (string) $project->uuid;

        // 1. GET /forms/embed.js
        $client->request('GET', '/forms/embed.js');
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/javascript; charset=utf-8', $client->getResponse()->headers->get('Content-Type'));
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('data-jambo-form', $content);
        $this->assertStringContainsString('jambo-form-height', $content);

        // 2. GET /embed/forms/{projectUuid}/{slug}
        $client->request('GET', "/embed/forms/{$uuid}/survey");
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors *', $csp);

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Contact Us', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertStringContainsString('name="_website"', $html); // honeypot
        $this->assertStringContainsString('jambo-form-height', $html);

        // 3. GET /forms/embed/{projectUuid}/{slug} (alt route)
        $client->request('GET', "/forms/embed/{$uuid}/survey");
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Contact Us', (string) $client->getResponse()->getContent());

        // 4. Non-existent form in embed iframe -> 404 with clean HTML
        $client->request('GET', "/embed/forms/{$uuid}/missing-slug");
        $this->assertResponseStatusCodeSame(404);
        $this->assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('nosniff', (string) $client->getResponse()->headers->get('X-Content-Type-Options'));

        // 5. Invalid UUID in embed iframe -> 404 with clean HTML
        $client->request('GET', '/embed/forms/not-a-uuid/survey');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPublicCorsPreflightHeaders(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $project = $this->createProject($em);
        $uuid = (string) $project->uuid;

        // 1. OPTIONS on form show
        $client->request('OPTIONS', "/{$uuid}/forms/test", [], [], ['HTTP_ORIGIN' => 'https://external-site.com']);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        // 2. OPTIONS on form submit
        $client->request('OPTIONS', "/{$uuid}/forms/test/submit", [], [], ['HTTP_ORIGIN' => 'https://external-site.com']);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        // 3. OPTIONS on redirect resolve
        $client->request('OPTIONS', "/{$uuid}/redirects/resolve", [], [], ['HTTP_ORIGIN' => 'https://external-site.com']);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        // 4. OPTIONS on embed.js
        $client->request('OPTIONS', '/forms/embed.js', [], [], ['HTTP_ORIGIN' => 'https://external-site.com']);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
