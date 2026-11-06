<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class Cycle119McpHardeningTest extends WebTestCase
{
    public function testMcpContentTypeValidationUnsupportedReturns415(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'text/plain'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        $response = $client->getResponse();
        self::assertSame(415, $response->getStatusCode());
        self::assertTrue($response->headers->has('X-Content-Type-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertSame(-32700, $data['error']['code']);
        self::assertStringContainsString('Content-Type must be application/json', $data['error']['message']);
    }

    public function testMcpProjectEndpointContentTypeValidationUnsupportedReturns415(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'mcp-ct-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $client->request('POST', '/api/projects/00000000-0000-0000-0000-000000000001/mcp', [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        $response = $client->getResponse();
        self::assertSame(415, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32700, $data['error']['code']);
        self::assertStringContainsString('Content-Type must be application/json', $data['error']['message']);
    }

    public function testMcpSecurityHeadersPresentOnRpcResponse(): void
    {
        $client = static::createClient();
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 101,
            'method' => 'ping',
        ]);
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(['pong' => true], $data['result']);
    }

    public function testMcpNullBytePayloadRejectedWith400(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json'], "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"\0}");

        $response = $client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32600, $data['error']['code']);
        self::assertStringContainsString('invalid control characters', $data['error']['message']);
    }

    public function testMcpToolMissingRequiredArgumentReturns32602(): void
    {
        $client = static::createClient();
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 202,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_collections',
                'arguments' => [
                    'include_fields' => true,
                    // 'project_uuid' missing!
                ],
            ],
        ]);
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32602, $data['error']['code']);
        self::assertStringContainsString('Missing required argument: project_uuid', $data['error']['message']);
    }

    public function testMcpToolArgumentDepthExceededReturns32602(): void
    {
        $client = static::createClient();
        // Create an argument structure nested 8 levels deep
        $deepNested = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'too_deep']]]]]]];
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 203,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_projects',
                'arguments' => [
                    'search' => 'test',
                    'extra_data' => $deepNested,
                ],
            ],
        ]);
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32602, $data['error']['code']);
        self::assertStringContainsString('exceeds maximum nesting depth', $data['error']['message']);
    }

    public function testMcpToolTooManyArgumentsReturns32602(): void
    {
        $client = static::createClient();
        $manyArgs = [];
        for ($i = 0; $i < 55; $i++) {
            $manyArgs['arg_' . $i] = 'val_' . $i;
        }
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 204,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_projects',
                'arguments' => $manyArgs,
            ],
        ]);
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32602, $data['error']['code']);
        self::assertStringContainsString('Too many arguments provided', $data['error']['message']);
    }

    public function testMcpToolInvalidProjectUuidDoesNotThrow500(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'mcp-uuid-test-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 205,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_collections',
                'arguments' => [
                    'project_uuid' => 'malformed-not-valid-uuid',
                ],
            ],
        ]);
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        // Should return a clean error without crashing with 500
        self::assertTrue(isset($data['error']) || isset($data['result']['isError']));
        $errorText = $data['error']['message'] ?? ($data['result']['content'][0]['text'] ?? '');
        self::assertStringContainsString('Invalid project UUID format', $errorText);
    }

    public function testMcpTransformImageInvalidDimensionsReturns422(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'mcp-img-test-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);

        $project = new Project();
        $project->name = 'Img Project ' . bin2hex(random_bytes(3));
        $em->persist($project);

        $media = new \App\Entity\Media();
        $media->project = $project;
        $media->fileName = 'test.jpg';
        $media->originalName = 'test.jpg';
        $media->mimeType = 'image/jpeg';
        $media->fileSize = 1024;
        $media->path = 'projects/' . $project->uuid->toRfc4122() . '/test.jpg';
        $em->persist($media);
        $em->flush();

        $client->loginUser($user);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 206,
            'method' => 'tools/call',
            'params' => [
                'name' => 'transform_image',
                'arguments' => [
                    'media_uuid' => $media->uuid->toRfc4122(),
                    'width' => 50000, // Exceeds 4000 limit
                ],
            ],
        ]);
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $errorText = $data['error']['message'] ?? ($data['result']['content'][0]['text'] ?? '');
        self::assertStringContainsString('Invalid image transform dimensions', $errorText);
    }

    public function testTranslationParityForMcpKeysAcrossAllLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $dictionaries = [];
        foreach ($locales as $l) {
            $dictionaries[$l] = require __DIR__ . "/../../translations/messages.{$l}.php";
        }

        $enKeys = array_keys($dictionaries['en']);
        foreach ($locales as $l) {
            self::assertSame(count($enKeys), count($dictionaries[$l]), "Mismatch in key count for locale {$l}");
            $diff = array_diff($enKeys, array_keys($dictionaries[$l]));
            self::assertEmpty($diff, "Missing keys in locale {$l}: " . implode(', ', $diff));
        }

        // Verify specific new MCP keys are present and non-empty in all 4 locales
        $newMcpKeys = [
            'mcp.unsupported_content_type',
            'mcp.invalid_project_uuid',
            'mcp.missing_argument',
            'mcp.argument_too_large',
            'mcp.argument_depth_exceeded',
            'mcp.too_many_arguments',
            'mcp.invalid_transform_dimensions',
            'mcp.invalid_control_characters',
        ];

        foreach ($newMcpKeys as $key) {
            foreach ($locales as $l) {
                self::assertArrayHasKey($key, $dictionaries[$l], "Key {$key} missing in locale {$l}");
                self::assertNotEmpty($dictionaries[$l][$key], "Key {$key} is empty in locale {$l}");
            }
        }
    }
}
