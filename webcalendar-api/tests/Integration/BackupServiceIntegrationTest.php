<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\BackupService;

final class BackupServiceIntegrationTest extends IntegrationTestCase
{
    private BackupService $service;
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a temp directory for backups
        $this->backupDir = sys_get_temp_dir() . '/wctng-backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->backupDir, 0o750, true);

        // SQLite in-memory can't be backed up via file copy, so we test the service methods
        // that don't depend on actual file paths
        $this->service = new BackupService($this->pdo, 'sqlite::memory:', $this->backupDir . '/..');
    }

    protected function tearDown(): void
    {
        // Clean up temp backup files
        $files = glob($this->backupDir . '/../var/backups/*');
        if (\is_array($files)) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        @rmdir($this->backupDir . '/../var/backups');
        @rmdir($this->backupDir);

        parent::tearDown();
    }

    public function testIsValidFilename(): void
    {
        $this->assertTrue(BackupService::isValidFilename('webcalendar-backup-2026-03-18_120000.sql'));
        $this->assertTrue(BackupService::isValidFilename('backup.db'));
        $this->assertFalse(BackupService::isValidFilename('../../../etc/passwd'));
        $this->assertFalse(BackupService::isValidFilename('/absolute/path'));
        $this->assertFalse(BackupService::isValidFilename('file with spaces.sql'));
        $this->assertFalse(BackupService::isValidFilename(''));
    }

    public function testListBackupsEmptyDir(): void
    {
        $this->assertSame([], $this->service->listBackups());
    }

    public function testListBackupsFindsFiles(): void
    {
        $dir = $this->service->getBackupDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        // Create fake backup files
        file_put_contents($dir . '/webcalendar-backup-2026-03-18.sql', 'fake sql');
        file_put_contents($dir . '/webcalendar-backup-2026-03-17.sql', 'older sql');

        $backups = $this->service->listBackups();
        $this->assertCount(2, $backups);
        $filenames = array_column($backups, 'filename');
        $this->assertContains('webcalendar-backup-2026-03-18.sql', $filenames);
        $this->assertContains('webcalendar-backup-2026-03-17.sql', $filenames);
    }

    public function testCleanupRemovesOldFiles(): void
    {
        $dir = $this->service->getBackupDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        // Create a file and set its mtime to 10 days ago
        $oldFile = $dir . '/webcalendar-backup-old.sql';
        file_put_contents($oldFile, 'old');
        touch($oldFile, time() - (10 * 86400));

        // Create a recent file
        file_put_contents($dir . '/webcalendar-backup-new.sql', 'new');

        $deleted = $this->service->cleanupOld(7);
        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($dir . '/webcalendar-backup-new.sql');
    }
}
