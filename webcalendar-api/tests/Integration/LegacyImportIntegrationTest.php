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

    public function testImports19xSchemaWithAllColumns(): void
    {
        // Simulate a 1.9.x schema which has ALL columns including cal_uid, cal_url, cal_sequence, etc.
        $v19Pdo = new \PDO('sqlite::memory:');
        $v19Pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $v19Pdo->exec('
            CREATE TABLE webcal_user (
                cal_login VARCHAR(60) PRIMARY KEY,
                cal_passwd VARCHAR(255),
                cal_firstname VARCHAR(60),
                cal_lastname VARCHAR(60),
                cal_email VARCHAR(75),
                cal_is_admin CHAR(1) DEFAULT "N",
                cal_enabled CHAR(1) DEFAULT "Y",
                cal_telephone VARCHAR(60),
                cal_address VARCHAR(75),
                cal_title VARCHAR(75),
                cal_birthday INT,
                cal_last_login INT
            )
        ');

        $v19Pdo->exec('
            CREATE TABLE webcal_entry (
                cal_id INTEGER PRIMARY KEY AUTOINCREMENT,
                cal_group_id INT,
                cal_ext_for_id INT,
                cal_create_by VARCHAR(60) NOT NULL,
                cal_date INT NOT NULL,
                cal_time INT DEFAULT -1,
                cal_mod_date INT,
                cal_mod_time INT,
                cal_duration INT DEFAULT 0,
                cal_due_date INT,
                cal_due_time INT,
                cal_location VARCHAR(100),
                cal_url VARCHAR(255),
                cal_completed INT,
                cal_priority INT DEFAULT 5,
                cal_type CHAR(1) DEFAULT "E",
                cal_access CHAR(1) DEFAULT "P",
                cal_name VARCHAR(80) NOT NULL,
                cal_description TEXT,
                cal_uid VARCHAR(255),
                cal_sequence INT DEFAULT 0,
                cal_transp VARCHAR(11) DEFAULT "OPAQUE",
                cal_status VARCHAR(20)
            )
        ');

        $v19Pdo->exec('CREATE TABLE webcal_entry_user (cal_id INT, cal_login VARCHAR(60), cal_status CHAR(1), PRIMARY KEY (cal_id, cal_login))');
        $v19Pdo->exec('CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY AUTOINCREMENT, cat_name VARCHAR(80), cat_color VARCHAR(16), cat_owner VARCHAR(60))');
        $v19Pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INT, cat_id INT, cat_order INT, cat_owner VARCHAR(60), PRIMARY KEY (cal_id, cat_id, cat_order, cat_owner))');
        $v19Pdo->exec('CREATE TABLE webcal_user_pref (cal_login VARCHAR(60), cal_setting VARCHAR(50), cal_value VARCHAR(100), PRIMARY KEY (cal_login, cal_setting))');
        $v19Pdo->exec('CREATE TABLE webcal_entry_repeats (cal_id INT PRIMARY KEY, cal_type VARCHAR(20), cal_frequency INT, cal_end INT, cal_byday VARCHAR(100), cal_bymonth VARCHAR(50), cal_bymonthday VARCHAR(100), cal_count INT)');

        // Seed with 1.9.x-style data
        $v19Pdo->exec("INSERT INTO webcal_user VALUES ('craig', '\$2y\$10\$hash', 'Craig', 'Knudsen', 'craig@example.com', 'Y', 'Y', '', '', '', NULL, NULL)");
        $v19Pdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_time, cal_duration, cal_name, cal_description, cal_location, cal_url, cal_access, cal_type, cal_uid, cal_sequence, cal_status) VALUES ('craig', 20260701, 140000, 60, 'v1.9 Meeting', 'Full 1.9.x event', 'Conference Room B', 'https://meet.example.com', 'P', 'E', 'v19-meeting@webcalendar', 3, NULL)");
        $v19Pdo->exec("INSERT INTO webcal_categories VALUES (1, 'Business', '#0066cc', NULL)");
        $v19Pdo->exec("INSERT INTO webcal_user_pref VALUES ('craig', 'STARTVIEW', 'month')");

        $stats = $this->service->import($v19Pdo);

        $this->assertSame(1, $stats['users']['imported']);
        $this->assertSame(1, $stats['events']['imported']);
        $this->assertSame(1, $stats['categories']['imported']);

        // Verify the event preserved its UID
        $event = $this->factory->getEventRepository()->findByUid('v19-meeting@webcalendar');
        $this->assertNotNull($event);
        $this->assertSame('v1.9 Meeting', $event->name());
        $this->assertSame('Conference Room B', $event->location());
        $this->assertSame('Full 1.9.x event', $event->description());

        // Verify schema detected all columns
        $columnMap = $this->service->getColumnMap();
        $this->assertContains('cal_uid', $columnMap['webcal_entry']);
        $this->assertContains('cal_url', $columnMap['webcal_entry']);
        $this->assertContains('cal_sequence', $columnMap['webcal_entry']);
        $this->assertContains('cal_status', $columnMap['webcal_entry']);
        $this->assertContains('cal_transp', $columnMap['webcal_entry']);
    }

    public function testImports190SchemaWithoutCatStatus(): void
    {
        // v1.9.0 has categories WITHOUT cat_status, cat_icon_mime (added in 1.9.11)
        // and users WITHOUT cal_api_token (added in 1.9.13)
        $v190Pdo = new \PDO('sqlite::memory:');
        $v190Pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $v190Pdo->exec('CREATE TABLE webcal_user (cal_login VARCHAR(60) PRIMARY KEY, cal_passwd VARCHAR(255), cal_firstname VARCHAR(60), cal_lastname VARCHAR(60), cal_email VARCHAR(75), cal_is_admin CHAR(1) DEFAULT "N", cal_enabled CHAR(1) DEFAULT "Y")');
        $v190Pdo->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY AUTOINCREMENT, cal_create_by VARCHAR(60) NOT NULL, cal_date INT NOT NULL, cal_time INT DEFAULT -1, cal_duration INT DEFAULT 0, cal_name VARCHAR(80) NOT NULL, cal_description TEXT, cal_location VARCHAR(100), cal_url VARCHAR(100), cal_access CHAR(1) DEFAULT "P", cal_type CHAR(1) DEFAULT "E", cal_uid VARCHAR(255))');
        $v190Pdo->exec('CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY AUTOINCREMENT, cat_name VARCHAR(80) NOT NULL, cat_color VARCHAR(8), cat_owner VARCHAR(25))');
        $v190Pdo->exec('CREATE TABLE webcal_entry_user (cal_id INT, cal_login VARCHAR(60), cal_status CHAR(1), PRIMARY KEY (cal_id, cal_login))');
        $v190Pdo->exec('CREATE TABLE webcal_user_pref (cal_login VARCHAR(60), cal_setting VARCHAR(50), cal_value VARCHAR(100), PRIMARY KEY (cal_login, cal_setting))');
        $v190Pdo->exec('CREATE TABLE webcal_entry_repeats (cal_id INT PRIMARY KEY, cal_type VARCHAR(20))');
        $v190Pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INT, cat_id INT, cat_order INT, cat_owner VARCHAR(25), PRIMARY KEY (cal_id, cat_id, cat_order, cat_owner))');

        $v190Pdo->exec("INSERT INTO webcal_user VALUES ('alice190', '\$2y\$10\$hash', 'Alice', 'V190', 'alice190@example.com', 'N', 'Y')");
        $v190Pdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_time, cal_duration, cal_name, cal_url) VALUES ('alice190', 20260801, 90000, 30, 'v1.9.0 Event', 'http://example.com')");
        // Note: no cat_status column — should not fail
        $v190Pdo->exec("INSERT INTO webcal_categories VALUES (1, 'Meetings', '#FF0000', 'alice190')");

        $stats = $this->service->import($v190Pdo);
        $this->assertSame(1, $stats['users']['imported']);
        $this->assertSame(1, $stats['events']['imported']);
        $this->assertSame(1, $stats['categories']['imported']);

        // Verify cat_status was NOT in the schema probe
        $columnMap = $this->service->getColumnMap();
        $this->assertNotContains('cat_status', $columnMap['webcal_categories']);
        // Verify cal_api_token was NOT in the schema probe
        $this->assertNotContains('cal_api_token', $columnMap['webcal_user']);
    }

    public function testImports1911SchemaWithCatStatus(): void
    {
        // v1.9.11+ added cat_status, cat_icon_mime, cat_icon_blob to webcal_categories
        $v1911Pdo = new \PDO('sqlite::memory:');
        $v1911Pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $v1911Pdo->exec('CREATE TABLE webcal_user (cal_login VARCHAR(60) PRIMARY KEY, cal_passwd VARCHAR(255), cal_firstname VARCHAR(60), cal_lastname VARCHAR(60), cal_email VARCHAR(75), cal_is_admin CHAR(1) DEFAULT "N", cal_enabled CHAR(1) DEFAULT "Y")');
        $v1911Pdo->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY AUTOINCREMENT, cal_create_by VARCHAR(60) NOT NULL, cal_date INT NOT NULL, cal_time INT DEFAULT -1, cal_duration INT DEFAULT 0, cal_name VARCHAR(80) NOT NULL, cal_description TEXT, cal_location VARCHAR(100), cal_url VARCHAR(255), cal_access CHAR(1) DEFAULT "P", cal_type CHAR(1) DEFAULT "E", cal_uid VARCHAR(255), cal_status VARCHAR(20))');
        $v1911Pdo->exec('CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY AUTOINCREMENT, cat_name VARCHAR(80) NOT NULL, cat_color VARCHAR(8), cat_owner VARCHAR(25) NOT NULL DEFAULT "", cat_status CHAR(1) DEFAULT "A", cat_icon_mime VARCHAR(32), cat_icon_blob BLOB)');
        $v1911Pdo->exec('CREATE TABLE webcal_entry_user (cal_id INT, cal_login VARCHAR(60), cal_status CHAR(1), PRIMARY KEY (cal_id, cal_login))');
        $v1911Pdo->exec('CREATE TABLE webcal_user_pref (cal_login VARCHAR(60), cal_setting VARCHAR(50), cal_value VARCHAR(100), PRIMARY KEY (cal_login, cal_setting))');
        $v1911Pdo->exec('CREATE TABLE webcal_entry_repeats (cal_id INT PRIMARY KEY, cal_type VARCHAR(20))');
        $v1911Pdo->exec('CREATE TABLE webcal_entry_categories (cal_id INT, cat_id INT, cat_order INT, cat_owner VARCHAR(25), PRIMARY KEY (cal_id, cat_id, cat_order, cat_owner))');

        $v1911Pdo->exec("INSERT INTO webcal_user VALUES ('bob1911', '\$2y\$10\$hash', 'Bob', 'V1911', 'bob1911@example.com', 'N', 'Y')");
        $v1911Pdo->exec("INSERT INTO webcal_entry (cal_create_by, cal_date, cal_time, cal_duration, cal_name) VALUES ('bob1911', 20260901, 100000, 60, 'v1.9.11 Event')");
        // Active category
        $v1911Pdo->exec("INSERT INTO webcal_categories (cat_name, cat_color, cat_owner, cat_status) VALUES ('Active Cat', '#00FF00', '', 'A')");
        // Disabled category
        $v1911Pdo->exec("INSERT INTO webcal_categories (cat_name, cat_color, cat_owner, cat_status) VALUES ('Disabled Cat', '#999999', '', 'D')");

        $stats = $this->service->import($v1911Pdo);
        $this->assertSame(1, $stats['users']['imported']);
        $this->assertSame(1, $stats['events']['imported']);
        // Both categories imported (disabled one too, with enabled=false)
        $this->assertSame(2, $stats['categories']['imported']);

        // Verify cat_status WAS detected
        $columnMap = $this->service->getColumnMap();
        $this->assertContains('cat_status', $columnMap['webcal_categories']);
    }

    public function testLegacyCategoryIconBlobsAreDroppedOnImport(): void
    {
        // Legacy v1.9.11+ stored category icons as MIME-typed blobs.
        // The rewrite uses single-emoji icons instead, so the blobs must
        // be dropped silently with a count reported in the stats so the
        // admin knows to re-pick icons in the Category admin page.
        $legacy = new \PDO('sqlite::memory:');
        $legacy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $legacy->exec('CREATE TABLE webcal_user (cal_login VARCHAR(60) PRIMARY KEY, cal_passwd VARCHAR(255), cal_firstname VARCHAR(60), cal_lastname VARCHAR(60), cal_email VARCHAR(75), cal_is_admin CHAR(1) DEFAULT "N", cal_enabled CHAR(1) DEFAULT "Y")');
        $legacy->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY AUTOINCREMENT, cal_create_by VARCHAR(60) NOT NULL, cal_date INT NOT NULL, cal_time INT DEFAULT -1, cal_duration INT DEFAULT 0, cal_name VARCHAR(80) NOT NULL)');
        $legacy->exec('CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY AUTOINCREMENT, cat_name VARCHAR(80) NOT NULL, cat_color VARCHAR(8), cat_owner VARCHAR(25) NOT NULL DEFAULT "", cat_status CHAR(1) DEFAULT "A", cat_icon_mime VARCHAR(32), cat_icon_blob BLOB)');
        $legacy->exec('CREATE TABLE webcal_entry_user (cal_id INT, cal_login VARCHAR(60), cal_status CHAR(1), PRIMARY KEY (cal_id, cal_login))');
        $legacy->exec('CREATE TABLE webcal_user_pref (cal_login VARCHAR(60), cal_setting VARCHAR(50), cal_value VARCHAR(100), PRIMARY KEY (cal_login, cal_setting))');
        $legacy->exec('CREATE TABLE webcal_entry_repeats (cal_id INT PRIMARY KEY, cal_type VARCHAR(20))');
        $legacy->exec('CREATE TABLE webcal_entry_categories (cal_id INT, cat_id INT, cat_order INT, cat_owner VARCHAR(25), PRIMARY KEY (cal_id, cat_id, cat_order, cat_owner))');

        $legacy->exec("INSERT INTO webcal_user VALUES ('icon_user', '\$2y\$10\$hash', 'Icon', 'User', 'iconuser@example.com', 'N', 'Y')");

        // Two categories have icon blobs, one doesn't.
        $legacy->exec("INSERT INTO webcal_categories (cat_name, cat_color, cat_owner, cat_status, cat_icon_mime, cat_icon_blob) VALUES ('Birthday', '#ff0000', '', 'A', 'image/png', 'FAKE_PNG_BYTES')");
        $legacy->exec("INSERT INTO webcal_categories (cat_name, cat_color, cat_owner, cat_status, cat_icon_mime, cat_icon_blob) VALUES ('Holiday', '#00ff00', '', 'A', 'image/gif', 'FAKE_GIF_BYTES')");
        $legacy->exec("INSERT INTO webcal_categories (cat_name, cat_color, cat_owner, cat_status) VALUES ('Meeting', '#0000ff', '', 'A')");

        $stats = $this->service->import($legacy);

        $this->assertSame(3, $stats['categories']['imported']);
        $this->assertSame(2, $stats['categories']['icons_dropped']);
    }

    public function testImportWithoutIconColumnsReportsZeroDropped(): void
    {
        // Legacy schemas predating v1.9.11 have no cat_icon_* columns; the
        // import must not count anything as dropped and must not error.
        $legacy = new \PDO('sqlite::memory:');
        $legacy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $legacy->exec('CREATE TABLE webcal_user (cal_login VARCHAR(60) PRIMARY KEY, cal_passwd VARCHAR(255), cal_firstname VARCHAR(60), cal_lastname VARCHAR(60), cal_email VARCHAR(75), cal_is_admin CHAR(1) DEFAULT "N", cal_enabled CHAR(1) DEFAULT "Y")');
        $legacy->exec('CREATE TABLE webcal_entry (cal_id INTEGER PRIMARY KEY AUTOINCREMENT, cal_create_by VARCHAR(60) NOT NULL, cal_date INT NOT NULL, cal_duration INT DEFAULT 0, cal_name VARCHAR(80) NOT NULL)');
        $legacy->exec('CREATE TABLE webcal_categories (cat_id INTEGER PRIMARY KEY AUTOINCREMENT, cat_name VARCHAR(80) NOT NULL, cat_color VARCHAR(8), cat_owner VARCHAR(25))');
        $legacy->exec('CREATE TABLE webcal_entry_user (cal_id INT, cal_login VARCHAR(60), cal_status CHAR(1), PRIMARY KEY (cal_id, cal_login))');
        $legacy->exec('CREATE TABLE webcal_user_pref (cal_login VARCHAR(60), cal_setting VARCHAR(50), cal_value VARCHAR(100), PRIMARY KEY (cal_login, cal_setting))');
        $legacy->exec('CREATE TABLE webcal_entry_repeats (cal_id INT PRIMARY KEY, cal_type VARCHAR(20))');
        $legacy->exec('CREATE TABLE webcal_entry_categories (cal_id INT, cat_id INT, cat_order INT, cat_owner VARCHAR(25), PRIMARY KEY (cal_id, cat_id, cat_order, cat_owner))');

        $legacy->exec("INSERT INTO webcal_user VALUES ('olduser', '\$2y\$10\$hash', 'Old', 'User', 'olduser@example.com', 'N', 'Y')");
        $legacy->exec("INSERT INTO webcal_categories VALUES (1, 'Plain', '#ff0000', 'olduser')");

        $stats = $this->service->import($legacy);

        $this->assertSame(1, $stats['categories']['imported']);
        $this->assertSame(0, $stats['categories']['icons_dropped']);
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
