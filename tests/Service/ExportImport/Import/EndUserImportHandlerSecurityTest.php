<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Repository\EndUserRepository;
use App\Service\EndUserCustomFieldsSanitizer;
use App\Service\ExportImport\Import\EndUserImportHandler;
use PHPUnit\Framework\TestCase;

class EndUserImportHandlerSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/jambo_import_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            @unlink($this->tempDir . '/end_users.json');
            @rmdir($this->tempDir);
        }
    }

    public function testImportSkipsMalformedEmailsAndSanitizesCustomFields(): void
    {
        $payload = [
            'end_users' => [
                [
                    'email' => 'valid@example.com',
                    'password' => '$2y$10$validhashedpasswordhere...',
                    'username' => 'valid_user',
                    'status' => 'active',
                    'custom_fields' => [
                        'department' => 'Engineering',
                    ],
                ],
                [
                    'email' => 'hacker@example.com',
                    'password' => '$2y$10$validhashedpasswordhere...',
                    'username' => 'hacker_user',
                    'status' => 'active',
                    'custom_fields' => [
                        '__proto__' => ['admin' => true],
                        'is_admin' => true,
                    ],
                ],
                [
                    'email' => 'not-an-email',
                    'password' => 'pass',
                    'username' => 'invalid_user',
                ],
                [
                    'email' => '',
                    'password' => 'pass',
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/end_users.json', json_encode($payload));

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneByProjectAndEmail')->willReturn(null);

        $sanitizer = new EndUserCustomFieldsSanitizer();
        $handler = new EndUserImportHandler($endUserRepo, $sanitizer);

        $project = new Project();
        $options = new ImportOptions();
        $options->createNewProject = true;
        $uuidMap = [];

        $handler->import($project, $this->tempDir, $options, $uuidMap);

        $imported = $handler->getImportedUsers();
        // Should only import the 2 valid email users, skipping the malformed email entries
        $this->assertCount(2, $imported);

        $validUser = $imported[0];
        $this->assertSame('valid@example.com', $validUser->email);
        $this->assertSame('valid_user', $validUser->username);
        $this->assertArrayHasKey('department', $validUser->customFields);
        $this->assertSame('Engineering', $validUser->customFields['department']);

        $hackerUser = $imported[1];
        $this->assertSame('hacker@example.com', $hackerUser->email);
        // Custom fields must have had malicious payload neutralized to empty array
        $this->assertEmpty($hackerUser->customFields);
    }
}
