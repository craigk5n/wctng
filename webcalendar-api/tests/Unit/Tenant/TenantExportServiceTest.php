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

    // ------------------------------------------------ the archive's contents

    private function tenantFor(string $slug): \App\Tenant\Tenant
    {
        $result = $this->provisioner->provision($slug, ucfirst($slug), 'admin@' . $slug . '.test');
        self::assertTrue($result->success, $result->error);
        $tenant = $this->repo->findBySlug($slug);
        self::assertNotNull($tenant);

        return $tenant;
    }

    /** @return array{names: list<string>, entries: array<string, string>} */
    private function readArchive(string $zipContent): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_zip_');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, $zipContent);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmpFile) === true);

        $names = [];
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $names[] = (string) $stat['name'];
                $entries[(string) $stat['name']] = (string) $zip->getFromIndex($i);
            }
        }

        $zip->close();
        unlink($tmpFile);

        return ['names' => $names, 'entries' => $entries];
    }

    public function testEveryTableTheExportPromisesIsInTheArchive(): void
    {
        // The existing case checks five of the eight entries. The three it
        // leaves out -- group membership, layers and participants -- are
        // exactly the three that could be dropped without it noticing, and
        // each is a relationship that cannot be reconstructed from the others:
        // an export missing them restores a tenant whose groups are empty and
        // whose events have no attendees.
        $archive = $this->readArchive($this->exportService->export($this->tenantFor('every-co')));

        self::assertSame(
            [
                'users.json',
                'events.json',
                'categories.json',
                'groups.json',
                'group_members.json',
                'layers.json',
                'participants.json',
                'events.ics',
            ],
            $archive['names'],
        );
    }

    public function testTheJsonIsWrittenForAPersonToRead(): void
    {
        // JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR. Combined with & instead of
        // | the flags cancel: the output collapses to one line and an encoding
        // failure returns false rather than raising, which would put the
        // string "false" in the archive.
        $archive = $this->readArchive($this->exportService->export($this->tenantFor('pretty-co')));

        self::assertStringContainsString("\n", $archive['entries']['users.json']);
        self::assertStringContainsString('    ', $archive['entries']['users.json']);
    }

    // ----------------------------------------------------------- the ICS

    private function seedEvent(\App\Tenant\Tenant $tenant, int $id, string $name, int $date): void
    {
        $this->dbManager->getConnection($tenant)
            ->prepare(
                'INSERT INTO webcal_entry (cal_id, cal_name, cal_date, cal_create_by, cal_duration, cal_type, cal_access)
                 VALUES (:id, :name, :date, :by, 60, \'E\', \'P\')',
            )
            ->execute(['id' => $id, 'name' => $name, 'date' => $date, 'by' => 'admin']);
    }

    public function testTheIcsCarriesTheEventsAndNotJustAWrapper(): void
    {
        // Nothing read events.ics, so the whole loop that builds it could be
        // skipped -- the guard inverted, or the fetch loop emptied -- and the
        // export would still contain a well-formed, entirely empty calendar.
        $tenant = $this->tenantFor('ics-co');
        $this->seedEvent($tenant, 41, 'Quarterly review', 20260615);
        $this->seedEvent($tenant, 42, 'Retro', 20260616);

        $ics = $this->readArchive($this->exportService->export($tenant))['entries']['events.ics'];

        self::assertStringContainsString("UID:wctng-41@webcalendar\r\n", $ics);
        self::assertStringContainsString("SUMMARY:Quarterly review\r\n", $ics);
        self::assertStringContainsString("DTSTART:20260615\r\n", $ics);
        self::assertStringContainsString('UID:wctng-42@webcalendar', $ics);
        self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'), 'both events, once each');
        self::assertSame(2, substr_count($ics, 'END:VEVENT'));
    }

    public function testTheIcsOpensWithTheHeaderACalendarClientExpects(): void
    {
        // Three header lines, and a client rejects the file if any is missing.
        $ics = $this->readArchive($this->exportService->export($this->tenantFor('header-co')))['entries']['events.ics'];

        self::assertStringStartsWith(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WebCalendar//WCTNG Export//EN\r\n",
            $ics,
        );
        self::assertStringEndsWith("\r\nEND:VCALENDAR", $ics);
    }

    public function testAnEventNameWithLineBreaksDoesNotBreakTheIcs(): void
    {
        // A newline inside SUMMARY would end the property early and make every
        // line after it unparseable, so they are flattened to spaces.
        $tenant = $this->tenantFor('multiline-co');
        $this->seedEvent($tenant, 7, "Planning\r\nand review", 20260615);

        $ics = $this->readArchive($this->exportService->export($tenant))['entries']['events.ics'];

        self::assertStringContainsString('SUMMARY:Planning  and review', $ics);
        self::assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
    }

    // --------------------------------------------------- what it leaves behind

    public function testTheTemporaryArchiveIsNotLeftOnDisk(): void
    {
        // The export is assembled in a temp file and returned as a string; the
        // file is of no further use, and one is created per export.
        $before = glob(sys_get_temp_dir() . '/wctng_export_*') ?: [];

        $this->exportService->export($this->tenantFor('tidy-co'));

        $after = glob(sys_get_temp_dir() . '/wctng_export_*') ?: [];
        self::assertSame($before, $after, 'the export left its scratch file behind');
    }
}
