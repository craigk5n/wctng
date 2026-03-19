<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\TenantDatabaseManager;
use App\Tenant\TenantExportService;
use App\Tenant\TenantProvisioner;
use App\Tenant\TenantRepository;
use PHPUnit\Framework\TestCase;

final class TenantExportServiceTest extends TestCase
{
    private const APP_SECRET = 'export_test_secret_32_chars!!!!';

    private TenantDatabaseManager $dbManager;
    private TenantProvisioner $provisioner;
    private TenantRepository $repo;
    private TenantExportService $exportService;

    #[\Override]
    protected function setUp(): void
    {
        $controlPdo = new \PDO('sqlite::memory:');
        $controlPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $controlPdo->exec(TenantRepository::SCHEMA_SQL);

        $this->repo = new TenantRepository($controlPdo);
        $this->dbManager = new TenantDatabaseManager(self::APP_SECRET);
        $this->provisioner = new TenantProvisioner($this->repo, $this->dbManager, 'sqlite');
        $this->exportService = new TenantExportService($this->dbManager);
    }

    public function testExportReturnsZipContent(): void
    {
        $result = $this->provisioner->provision('export-co', 'Export Corp', 'admin@export.com');
        $this->assertTrue($result->success, $result->error);

        $tenant = $this->repo->findBySlug('export-co');
        $this->assertNotNull($tenant);

        $zipContent = $this->exportService->export($tenant);

        // Verify it's a valid ZIP
        $this->assertNotEmpty($zipContent);
        // ZIP magic bytes: PK (0x50 0x4B)
        $this->assertSame('PK', substr($zipContent, 0, 2));
    }

    public function testExportContainsExpectedFiles(): void
    {
        $result = $this->provisioner->provision('files-co', 'Files Corp', 'admin@files.com');
        $this->assertTrue($result->success, $result->error);

        $tenant = $this->repo->findBySlug('files-co');
        $this->assertNotNull($tenant);

        $zipContent = $this->exportService->export($tenant);

        // Write to temp file and inspect
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_zip_');
        $this->assertIsString($tmpFile);
        file_put_contents($tmpFile, $zipContent);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmpFile) === true);

        $fileNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $fileNames[] = $stat['name'];
            }
        }

        $zip->close();
        unlink($tmpFile);

        $this->assertContains('users.json', $fileNames);
        $this->assertContains('events.json', $fileNames);
        $this->assertContains('categories.json', $fileNames);
        $this->assertContains('groups.json', $fileNames);
        $this->assertContains('events.ics', $fileNames);
    }

    public function testExportIncludesAdminUser(): void
    {
        $result = $this->provisioner->provision('admin-co', 'Admin Corp', 'admin@admin.com');
        $this->assertTrue($result->success, $result->error);

        $tenant = $this->repo->findBySlug('admin-co');
        $this->assertNotNull($tenant);

        $zipContent = $this->exportService->export($tenant);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_zip_');
        $this->assertIsString($tmpFile);
        file_put_contents($tmpFile, $zipContent);

        $zip = new \ZipArchive();
        $zip->open($tmpFile);
        $usersJson = $zip->getFromName('users.json');
        $zip->close();
        unlink($tmpFile);

        $this->assertIsString($usersJson);
        $users = json_decode($usersJson, true);
        $this->assertIsArray($users);
        $this->assertGreaterThanOrEqual(1, \count($users));
    }
}
