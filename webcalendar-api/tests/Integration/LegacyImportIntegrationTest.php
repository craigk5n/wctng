<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\LegacyImportService;

/**
 * Tests the legacy import service using a mock legacy SQLite database.
 */
final class LegacyImportIntegrationTest extends IntegrationTestCase
{
    private \PDO $legacyPdo;
    private LegacyImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock legacy database
        $this->legacyPdo = new \PDO('sqlite::memory:');
        $this->legacyPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createLegacySchema();
        $this->seedLegacyData();

        $this->service = new LegacyImportService($this->factory);
    }

    private function createLegacySchema(): void
    {
        $this->legacyPdo->exec('
            CREATE TABLE webcal_user (
                cal_login VARCHAR(60) PRIMARY KEY,
                cal_passwd VARCHAR(255),
                cal_firstname VARCHAR(60),
                cal_lastname VARCHAR(60),
                cal_email VARCHAR(75),
                cal_is_admin CHAR(1) DEFAULT "N"
            )
        ');

        $this->legacyPdo->exec('
            CREATE TABLE webcal_entry (
                cal_id INTEGER PRIMARY KEY AUTOINCREMENT,
                cal_create_by VARCHAR(60) NOT NULL,
                cal_date INT NOT NULL,
                cal_time INT DEFAULT -1,
                cal_duration INT DEFAULT 0,
                cal_name VARCHAR(80) NOT NULL,
                cal_description TEXT,
                cal_location VARCHAR(100),
                cal_access CHAR(1) DEFAULT "P",
                cal_type CHAR(1) DEFAULT "E",
                cal_uid VARCHAR(255),
                cal_status VARCHAR(20),
                cal_mod_date INT,
                cal_mod_time INT
            )
        ');

        $this->legacyPdo->exec('
            CREATE TABLE webcal_entry_user (
                cal_id INT NOT NULL,
                cal_login VARCHAR(60) NOT NULL,
                cal_status CHAR(1) DEFAULT "A",
                PRIMARY KEY (cal_id, cal_login)
            )
        ');

        $this->legacyPdo->exec('
            CREATE TABLE webcal_categories (
                cat_id INTEGER PRIMARY KEY AUTOINCREMENT,
                cat_name VARCHAR(80) NOT NULL,
                cat_color VARCHAR(16)
            )
        ');

        $this->legacyPdo->exec('
            CREATE TABLE webcal_entry_categories (
                cal_id INT NOT NULL,
                cat_id INT NOT NULL,
                PRIMARY KEY (cal_id, cat_id)
            )
        ');

        $this->legacyPdo->exec('
            CREATE TABLE webcal_user_pref (
                cal_login VARCHAR(60) NOT NULL,
                cal_setting VARCHAR(50) NOT NULL,
                cal_value VARCHAR(100),
                PRIMARY KEY (cal_login, cal_setting)
            )
        ');

        $this->legacyPdo->exec('
            CREATE TABLE webcal_entry_repeats (
                cal_id INT PRIMARY KEY,
                cal_type VARCHAR(20),
                cal_frequency INT DEFAULT 1,
                cal_end INT
            )
        ');
    }

    private function seedLegacyData(): void
    {
        // Users
        $this->legacyPdo->exec("INSERT INTO webcal_user (cal_login, cal_firstname, cal_lastname, cal_email, cal_is_admin) VALUES ('legacyuser', 'Legacy', 'User', 'legacy@test.com', 'N')");
        $this->legacyPdo->exec("INSERT INTO webcal_user (cal_login, cal_firstname, cal_lastname, cal_email, cal_is_admin) VALUES ('legacyadmin', 'Legacy', 'Admin', 'legadmin@test.com', 'Y')");

        // Events
        $this->legacyPdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_time, cal_duration, cal_name, cal_description, cal_location, cal_access, cal_type) VALUES ('legacyuser', 20260615, 100000, 60, 'Legacy Meeting', 'A meeting from the old system', 'Room 101', 'P', 'E')");
        $this->legacyPdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_time, cal_duration, cal_name, cal_description, cal_location, cal_access, cal_type) VALUES ('legacyuser', 20260616, -1, 0, 'All Day Event', '', '', 'P', 'E')");
        $this->legacyPdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_time, cal_duration, cal_name, cal_access, cal_type) VALUES ('legacyadmin', 20260617, 140000, 30, 'Private Event', 'R', 'E')");
        // Event with existing UID
        $this->legacyPdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_time, cal_duration, cal_name, cal_uid) VALUES ('legacyuser', 20260618, 90000, 60, 'UID Event', 'existing-uid@legacy')");

        // Categories
        $this->legacyPdo->exec("INSERT INTO webcal_categories (cat_name, cat_color) VALUES ('Work', '#3788d8')");
        $this->legacyPdo->exec("INSERT INTO webcal_categories (cat_name) VALUES ('Personal')");

        // Participants
        $this->legacyPdo->exec("INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (1, 'legacyuser', 'A')");
        $this->legacyPdo->exec("INSERT INTO webcal_entry_user (cal_id, cal_login, cal_status) VALUES (1, 'legacyadmin', 'W')");

        // Preferences
        $this->legacyPdo->exec("INSERT INTO webcal_user_pref VALUES ('legacyuser', 'STARTVIEW', 'week')");
        $this->legacyPdo->exec("INSERT INTO webcal_user_pref VALUES ('legacyuser', 'TIMEZONE', 'America/New_York')");
        $this->legacyPdo->exec("INSERT INTO webcal_user_pref VALUES ('legacyuser', 'LANGUAGE', 'English')");
    }

    public function testImportsUsers(): void
    {
        $stats = $this->service->import($this->legacyPdo);

        $this->assertSame(2, $stats['users']['imported']);
        $this->assertSame(0, $stats['users']['errors']);

        // Verify users exist in WCTNG
        $user = $this->factory->getUserService()->getUserByLogin('legacyuser');
        $this->assertNotNull($user);
        $this->assertSame('Legacy', $user->firstName());
        $this->assertSame('legacy@test.com', $user->email());
    }

    public function testImportsEvents(): void
    {
        $stats = $this->service->import($this->legacyPdo);

        $this->assertSame(4, $stats['events']['imported']);
        $this->assertSame(0, $stats['events']['errors']);
    }

    public function testGeneratesUidsForLegacyEvents(): void
    {
        $this->service->import($this->legacyPdo);

        // Event without UID should get legacy-{id}@imported
        $event = $this->factory->getEventRepository()->findByUid('legacy-1@imported');
        $this->assertNotNull($event);
        $this->assertSame('Legacy Meeting', $event->name());
    }

    public function testPreservesExistingUids(): void
    {
        $this->service->import($this->legacyPdo);

        $event = $this->factory->getEventRepository()->findByUid('existing-uid@legacy');
        $this->assertNotNull($event);
        $this->assertSame('UID Event', $event->name());
    }

    public function testImportsCategories(): void
    {
        $stats = $this->service->import($this->legacyPdo);

        $this->assertSame(2, $stats['categories']['imported']);
    }

    public function testImportsPreferences(): void
    {
        $stats = $this->service->import($this->legacyPdo);

        // STARTVIEW and TIMEZONE should be imported (LANGUAGE mapped to locale)
        $this->assertGreaterThanOrEqual(2, $stats['preferences']['imported']);
    }

    public function testIdempotentOnRerun(): void
    {
        $stats1 = $this->service->import($this->legacyPdo);
        $this->assertSame(4, $stats1['events']['imported']);

        // Second run — everything should be skipped
        $stats2 = $this->service->import($this->legacyPdo);
        $this->assertSame(0, $stats2['events']['imported']);
        $this->assertSame(4, $stats2['events']['skipped']);
        $this->assertSame(0, $stats2['users']['imported']);
        $this->assertSame(2, $stats2['users']['skipped']);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $stats = $this->service->import($this->legacyPdo, true);

        // Should report what would be imported
        $this->assertSame(2, $stats['users']['imported']);
        $this->assertSame(4, $stats['events']['imported']);

        // But nothing should actually be in the database
        $user = $this->factory->getUserService()->getUserByLogin('legacyuser');
        $this->assertNull($user);
    }

    public function testSchemaProbing(): void
    {
        $this->service->import($this->legacyPdo);
        $columnMap = $this->service->getColumnMap();

        $this->assertArrayHasKey('webcal_entry', $columnMap);
        $this->assertContains('cal_id', $columnMap['webcal_entry']);
        $this->assertContains('cal_name', $columnMap['webcal_entry']);
        $this->assertContains('cal_uid', $columnMap['webcal_entry']);
    }

    public function testHandlesMinimalSchema(): void
    {
        // Create a minimal legacy DB without optional columns
        $minimalPdo = new \PDO('sqlite::memory:');
        $minimalPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $minimalPdo->exec('
            CREATE TABLE webcal_user (
                cal_login VARCHAR(60) PRIMARY KEY
            )
        ');
        $minimalPdo->exec('
            CREATE TABLE webcal_entry (
                cal_id INTEGER PRIMARY KEY AUTOINCREMENT,
                cal_create_by VARCHAR(60) NOT NULL,
                cal_date INT NOT NULL,
                cal_name VARCHAR(80) NOT NULL,
                cal_duration INT DEFAULT 0
            )
        ');

        $minimalPdo->exec("INSERT INTO webcal_user VALUES ('minuser')");
        $minimalPdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_name, cal_duration) VALUES ('minuser', 20260615, 'Minimal Event', 60)");

        $stats = $this->service->import($minimalPdo);
        $this->assertSame(1, $stats['users']['imported']);
        $this->assertSame(1, $stats['events']['imported']);
    }
}
