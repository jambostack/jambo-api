<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Backup\BackupManager;
use PHPUnit\Framework\TestCase;

class BackupManagerTest extends TestCase
{
    private string $tempDir;
    private BackupManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/jambo_backup_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
        $this->manager = new BackupManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        $backupDir = $this->tempDir . '/var/backups';
        if (is_dir($backupDir)) {
            $files = glob($backupDir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($backupDir);
        }
        if (is_dir($this->tempDir . '/var')) {
            @rmdir($this->tempDir . '/var');
        }
        @rmdir($this->tempDir);
    }

    public function testCreateBackupGeneratesValidFile(): void
    {
        $backup = $this->manager->createBackup('daily-test');

        $this->assertArrayHasKey('filename', $backup);
        $this->assertArrayHasKey('path', $backup);
        $this->assertArrayHasKey('size', $backup);
        $this->assertArrayHasKey('sha256', $backup);

        $this->assertMatchesRegularExpression(BackupManager::FILENAME_REGEX, $backup['filename']);
        $this->assertFileExists($backup['path']);
        $this->assertGreaterThan(0, $backup['size']);

        $content = file_get_contents($backup['path']);
        $this->assertStringContainsString('-- Jambo CMS Database Backup', $content);
        $this->assertStringContainsString('-- Label: daily-test', $content);
    }

    public function testCreateBackupSanitizesLabel(): void
    {
        $backup = $this->manager->createBackup('malicious/../../hack!@#$');

        $this->assertMatchesRegularExpression(BackupManager::FILENAME_REGEX, $backup['filename']);
        $this->assertStringNotContainsString('/', $backup['filename']);
        $this->assertStringNotContainsString('..', $backup['filename']);
    }

    public function testListBackupsReturnsCreatedFilesSorted(): void
    {
        $b1 = $this->manager->createBackup('first');
        sleep(1);
        $b2 = $this->manager->createBackup('second');

        $list = $this->manager->listBackups();
        $this->assertGreaterThanOrEqual(2, count($list));

        $filenames = array_column($list, 'filename');
        $this->assertContains($b1['filename'], $filenames);
        $this->assertContains($b2['filename'], $filenames);

        // Should be sorted newest first
        $this->assertSame($b2['filename'], $list[0]['filename']);
    }

    public function testResolveBackupFilePreventsDirectoryTraversal(): void
    {
        $this->assertNull($this->manager->resolveBackupFile('../../../etc/passwd'));
        $this->assertNull($this->manager->resolveBackupFile('..\\..\\windows\\system32'));
        $this->assertNull($this->manager->resolveBackupFile("backup-safe\x00.sql"));
        $this->assertNull($this->manager->resolveBackupFile('non-existent-file.sql'));
    }

    public function testVerifySqlDumpSafetyDetectsForbiddenCommands(): void
    {
        $backupDir = $this->manager->getBackupDir();
        $dangerousFile = $backupDir . '/backup-dangerous-test-12345678.sql';

        file_put_contents($dangerousFile, "SELECT 1;\nSELECT * INTO OUTFILE '/tmp/malicious.txt' FROM users;");
        $check = $this->manager->verifySqlDumpSafety($dangerousFile);
        $this->assertFalse($check['safe']);
        $this->assertNotNull($check['error']);
        unlink($dangerousFile);

        file_put_contents($dangerousFile, "SELECT 1;\nLOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE test;");
        $check = $this->manager->verifySqlDumpSafety($dangerousFile);
        $this->assertFalse($check['safe']);
        unlink($dangerousFile);

        file_put_contents($dangerousFile, "SELECT 1;\nATTACH DATABASE '/tmp/pwn.db' AS pwn;");
        $check = $this->manager->verifySqlDumpSafety($dangerousFile);
        $this->assertFalse($check['safe']);
        unlink($dangerousFile);

        file_put_contents($dangerousFile, "SELECT 1;\nGRANT ALL PRIVILEGES ON *.* TO 'attacker'@'%';");
        $check = $this->manager->verifySqlDumpSafety($dangerousFile);
        $this->assertFalse($check['safe']);
        unlink($dangerousFile);
    }

    public function testRestoreBackupRequiresExplicitConfirmation(): void
    {
        $backup = $this->manager->createBackup('restore-test');

        $resultNoConfirm = $this->manager->restoreBackup($backup['path'], false);
        $this->assertFalse($resultNoConfirm['success']);

        $resultConfirmed = $this->manager->restoreBackup($backup['path'], true);
        $this->assertTrue($resultConfirmed['success']);
    }

    public function testDeleteBackupRemovesFile(): void
    {
        $backup = $this->manager->createBackup('delete-test');
        $this->assertFileExists($backup['path']);

        $deleted = $this->manager->deleteBackup($backup['filename']);
        $this->assertTrue($deleted);
        $this->assertFileDoesNotExist($backup['path']);

        $deleteAgain = $this->manager->deleteBackup($backup['filename']);
        $this->assertFalse($deleteAgain);
    }
}
