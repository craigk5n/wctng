<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\SecurityAuditController;
use App\Security\WebCalendarUser;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\User;

/**
 * The security audit an administrator reads to decide the site is safe.
 *
 * Nothing executed a line of it. That matters differently here than in a
 * controller that enforces something: this one only reports, so a check stuck
 * on "pass" does not break anything visibly -- it tells an operator the
 * installation is fine when it is not, and they stop looking.
 */
final class SecurityAuditControllerTest extends TestCase
{
    private const string NOW = '2026-04-01T12:00:00+00:00';
    private const string STRONG_SECRET = 'a-genuinely-long-random-app-secret-value';

    private \PDO $pdo;
    private CoreServiceFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(
            __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql',
        );
        self::assertIsString($schema);
        foreach (preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException) {
                    // Not every statement applies to this SQLite build.
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'audit_test_secret_32_characters!');
    }

    private function controller(
        string $appSecret = self::STRONG_SECRET,
        string $environment = 'prod',
    ): SecurityAuditController {
        return new SecurityAuditController(
            $this->factory->getConfigService(),
            $this->pdo,
            $appSecret,
            $environment,
            new \Psr\Log\NullLogger(),
            new MockClock(self::NOW),
        );
    }

    private static function admin(string $login = 'admin'): WebCalendarUser
    {
        return new WebCalendarUser(
            new User($login, 'Ad', 'Min', $login . '@example.com', true, true),
            null,
        );
    }

    private static function ordinaryUser(): WebCalendarUser
    {
        return new WebCalendarUser(
            new User('bob', 'Bob', 'Jones', 'bob@example.com', false, true),
            null,
        );
    }

    /** Adds a user row directly, with the password hash the audit reads. */
    private function seedUser(string $login, string $password, bool $isAdmin): void
    {
        $this->pdo->prepare(
            "INSERT INTO webcal_user (cal_login, cal_lastname, cal_firstname, cal_email, cal_passwd, cal_is_admin, cal_enabled)
             VALUES (:login, 'Last', 'First', :email, :hash, :admin, 'Y')",
        )->execute([
            'login' => $login,
            'email' => $login . '@example.com',
            'hash' => password_hash($password, \PASSWORD_DEFAULT),
            'admin' => $isAdmin ? 'Y' : 'N',
        ]);
    }

    /** @return list<array{category: string, name: string, status: string, detail: string}> */
    private function checks(JsonResponse $response): array
    {
        /** @var array{data: array{checks: list<array{category: string, name: string, status: string, detail: string}>}} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['data']['checks'];
    }

    /** @return array{category: string, name: string, status: string, detail: string} */
    private function check(JsonResponse $response, string $name): array
    {
        foreach ($this->checks($response) as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        self::fail("the audit reported no check called \"{$name}\"");
    }

    private function audit(
        ?WebCalendarUser $user = null,
        string $appSecret = self::STRONG_SECRET,
        string $environment = 'prod',
        bool $secure = false,
    ): JsonResponse {
        $request = Request::create(($secure ? 'https' : 'http') . '://cal.example/api/v2/admin/security-audit');

        return $this->controller($appSecret, $environment)->__invoke($request, $user ?? self::admin());
    }

    // ---------------------------------------------------------- who may read it

    public function testAnAnonymousCallerIsRefused(): void
    {
        // Called directly rather than through the helper below, whose
        // ?? default would quietly turn a null user into an administrator --
        // which is exactly how this test first passed against a 200.
        $response = $this->controller()->__invoke(
            Request::create('http://cal.example/api/v2/admin/security-audit'),
            null,
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnOrdinaryUserIsRefused(): void
    {
        // The report names weak secrets, missing TLS and stale backups. It is
        // a map of where to attack, so it is for administrators only.
        $response = $this->controller()->__invoke(
            Request::create('http://cal.example/api/v2/admin/security-audit'),
            self::ordinaryUser(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('APP_SECRET', (string) $response->getContent());
    }

    public function testAnAdministratorGetsTheReport(): void
    {
        self::assertSame(200, $this->audit()->getStatusCode());
    }

    // ------------------------------------------------------------ the envelope

    public function testEveryCheckIsReportedInTheSameShape(): void
    {
        // The client groups by category and colours by status, so a check
        // missing either is one an operator never sees.
        $checks = $this->checks($this->audit());
        self::assertNotEmpty($checks);

        foreach ($checks as $check) {
            self::assertNotSame('', $check['category'], 'a check with no category cannot be grouped');
            self::assertNotSame('', $check['name']);
            self::assertContains($check['status'], ['pass', 'warn', 'fail', 'info'], $check['name']);
            self::assertNotSame('', $check['detail'], $check['name'] . ' says nothing about what to do');
        }
    }

    public function testTheSummaryCountsTheChecksItReturned(): void
    {
        // The summary is what the dashboard shows; if it disagreed with the
        // list, a failing check could be reported as a clean bill of health.
        /** @var array{data: array{checks: list<array{status: string}>, summary: array<string, int>}} $body */
        $body = json_decode((string) $this->audit()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $statuses = array_count_values(array_column($body['data']['checks'], 'status'));

        self::assertSame(\count($body['data']['checks']), $body['data']['summary']['total']);
        self::assertSame($statuses['pass'] ?? 0, $body['data']['summary']['pass']);
        self::assertSame($statuses['warn'] ?? 0, $body['data']['summary']['warn']);
        self::assertSame($statuses['fail'] ?? 0, $body['data']['summary']['fail']);
    }

    // ------------------------------------------------------- individual checks

    public function testTheDefaultAdminPasswordIsReportedAsAFailure(): void
    {
        $this->seedUser('admin', 'admin', isAdmin: true);

        self::assertSame('fail', $this->check($this->audit(), 'Default admin password')['status']);
    }

    public function testAChangedAdminPasswordPasses(): void
    {
        $this->seedUser('admin', 'something-else-entirely', isAdmin: true);

        self::assertSame('pass', $this->check($this->audit(), 'Default admin password')['status']);
    }

    public function testAnAdministratorNotCalledAdminIsStillCheckedForTheDefaultPassword(): void
    {
        // Looking up the login "admin" by name misses every installation whose
        // administrator is called something else -- and web setup lets the
        // operator choose both the name and the password. Such a site was told
        // its password "has been changed from the default" while the account
        // could still be opened with "admin".
        $this->seedUser('craig', 'admin', isAdmin: true);

        self::assertSame('fail', $this->check($this->audit(), 'Default admin password')['status']);
    }

    public function testAnOrdinaryUserWithAWeakPasswordIsNotAnAdminFinding(): void
    {
        // The check is about administrator accounts. A non-admin using a poor
        // password is a different matter and must not be reported as this one.
        $this->seedUser('bob', 'admin', isAdmin: false);

        self::assertSame('pass', $this->check($this->audit(), 'Default admin password')['status']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function appSecrets(): iterable
    {
        yield 'a strong random value' => [self::STRONG_SECRET, 'pass'];
        yield 'too short' => ['short', 'fail'];
        yield 'the change_me placeholder' => ['change_me', 'fail'];
        yield 'the symfony default' => ['ThisTokenIsNotSoSecretChangeIt', 'fail'];
        yield 'the documented placeholder' => ['your_app_secret_here', 'fail'];
    }

    #[DataProvider('appSecrets')]
    public function testTheAppSecretIsJudgedOnItsStrength(string $secret, string $expected): void
    {
        self::assertSame($expected, $this->check($this->audit(appSecret: $secret), 'APP_SECRET strength')['status']);
    }

    public function testAnInsecureConnectionIsReported(): void
    {
        self::assertSame('warn', $this->check($this->audit(secure: false), 'HTTPS / TLS')['status']);
    }

    public function testAnHttpsConnectionPasses(): void
    {
        self::assertSame('pass', $this->check($this->audit(secure: true), 'HTTPS / TLS')['status']);
    }

    public function testASqliteDatabaseNeedsNoConnectionEncryption(): void
    {
        $check = $this->check($this->audit(), 'Database connection encryption');

        self::assertContains($check['status'], ['pass', 'warn']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function adminCounts(): iterable
    {
        yield 'one administrator' => [1, 'pass'];
        yield 'three, the most it tolerates' => [3, 'pass'];
        yield 'four is worth a look' => [4, 'warn'];
    }

    #[DataProvider('adminCounts')]
    public function testTooManyAdministratorsIsWorthAWarning(int $count, string $expected): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->seedUser('admin' . $i, 'a-strong-password', isAdmin: true);
        }

        $check = $this->check($this->audit(), 'Admin user count');
        self::assertSame($expected, $check['status']);
        self::assertStringContainsString((string) $count, $check['detail']);
    }

    public function testPublicCalendarsAreCountedAndReported(): void
    {
        $this->pdo->exec(
            "INSERT INTO webcal_user_pref (cal_login, cal_setting, cal_value)
             VALUES ('bob', 'public_calendar_enabled', 'Y'), ('carol', 'public_calendar_enabled', 'Y')",
        );

        $check = $this->check($this->audit(), 'Public calendars enabled');

        self::assertSame('info', $check['status'], 'sharing a calendar is a choice, not a fault');
        self::assertStringContainsString('2 user(s)', $check['detail']);
    }

    // ------------------------------------------- the checks driven by config

    /** @return iterable<string, array{string, string}> */
    public static function environments(): iterable
    {
        yield 'production' => ['prod', 'pass'];
        yield 'development' => ['dev', 'warn'];
        yield 'test' => ['test', 'pass'];
    }

    #[DataProvider('environments')]
    public function testTheEnvironmentIsReported(string $environment, string $expected): void
    {
        $check = $this->check($this->audit(environment: $environment), 'Application environment');

        self::assertSame($expected, $check['status']);
        self::assertStringContainsString($environment, $check['detail']);
    }

    #[DataProvider('environments')]
    public function testErrorVerbosityFollowsTheEnvironment(string $environment, string $expected): void
    {
        // Only prod passes here: anything else may put an exception message in
        // front of whoever triggered it.
        $expectedForErrors = $environment === 'prod' ? 'pass' : 'warn';

        self::assertSame(
            $expectedForErrors,
            $this->check($this->audit(environment: $environment), 'Error message verbosity')['status'],
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function jwtLifetimes(): iterable
    {
        yield 'an hour' => ['3600', 'pass', '1 hours'];
        yield 'a day' => ['86400', 'pass', '24 hours'];
        yield 'ninety minutes' => ['5400', 'pass', '1.5 hours'];
        yield 'a week' => ['604800', 'warn', '168 hours'];
    }

    #[DataProvider('jwtLifetimes')]
    public function testALongLivedTokenIsWorthAWarning(string $ttl, string $expected, string $hours): void
    {
        // A token that lives for a week is a week of access for anyone who
        // steals one, so the audit is meant to notice.
        $previous = $_ENV['JWT_TTL'] ?? null;
        $_ENV['JWT_TTL'] = $ttl;

        try {
            $check = $this->check($this->audit(), 'JWT token lifetime');
            self::assertSame($expected, $check['status']);
            // The detail converts the seconds to hours, which is the figure an
            // operator actually reads before deciding.
            self::assertStringContainsString($ttl . ' seconds', $check['detail']);
            self::assertStringContainsString($hours, $check['detail']);
        } finally {
            if ($previous === null) {
                unset($_ENV['JWT_TTL']);
            } else {
                $_ENV['JWT_TTL'] = $previous;
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function databaseUrls(): iterable
    {
        yield 'sqlite needs no encryption' => ['sqlite:///var/data.db', 'pass'];
        yield 'mysql with ssl' => ['mysql://u:p@db:3306/wc?sslmode=require', 'pass'];
        yield 'mysql with a ca file' => ['mysql://u:p@db:3306/wc?ssl_ca=/etc/ca.pem', 'pass'];
        yield 'mysql over the wire in the clear' => ['mysql://u:p@db:3306/wc', 'warn'];
    }

    #[DataProvider('databaseUrls')]
    public function testTheDatabaseConnectionIsJudgedOnItsUrl(string $url, string $expected): void
    {
        $previous = $_ENV['DATABASE_URL'] ?? null;
        $_ENV['DATABASE_URL'] = $url;

        try {
            self::assertSame(
                $expected,
                $this->check($this->audit(), 'Database connection encryption')['status'],
            );
        } finally {
            if ($previous === null) {
                unset($_ENV['DATABASE_URL']);
            } else {
                $_ENV['DATABASE_URL'] = $previous;
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function configDrivenChecks(): iterable
    {
        // Each of these is a setting an administrator can turn on, and the
        // audit's job is to say which way it is set. A check stuck on one
        // answer would say the same thing whatever the site is configured to.
        yield 'html descriptions on' => ['ALLOW_HTML_DESCRIPTION', 'Y', 'info'];
        yield 'html descriptions off' => ['ALLOW_HTML_DESCRIPTION', 'N', 'pass'];
        yield 'seo pages on' => ['ENABLE_SEO_PAGES', 'Y', 'info'];
        yield 'seo pages off' => ['ENABLE_SEO_PAGES', 'N', 'pass'];
    }

    #[DataProvider('configDrivenChecks')]
    public function testASettingIsReportedTheWayItIsSet(string $setting, string $value, string $expected): void
    {
        $names = [
            'ALLOW_HTML_DESCRIPTION' => 'Rich text / HTML descriptions',
            'ENABLE_SEO_PAGES' => 'Public SEO event pages',
        ];
        $this->pdo->prepare('INSERT INTO webcal_config (cal_setting, cal_value) VALUES (:k, :v)')
            ->execute(['k' => $setting, 'v' => $value]);

        self::assertSame($expected, $this->check($this->audit(), $names[$setting])['status']);
    }

    public function testCustomHtmlIsReportedOnlyWhenSomeIsSet(): void
    {
        // Administrator-authored markup is rendered into every page, so the
        // audit points at it when there is any.
        $clean = $this->check($this->audit(), 'Custom HTML/CSS injection');
        self::assertSame('pass', $clean['status']);

        $this->pdo->prepare('INSERT INTO webcal_config (cal_setting, cal_value) VALUES (:k, :v)')
            ->execute(['k' => 'CUSTOM_HEADER_HTML', 'v' => '<script>hello()</script>']);

        self::assertSame('info', $this->check($this->audit(), 'Custom HTML/CSS injection')['status']);
    }

    public function testAStaleBackupIsCountedAndAFreshOneIsNot(): void
    {
        // checkBackupFiles() reads a fixed directory under var/, which other
        // suites also write to, so this counts the difference it makes rather
        // than assuming the directory starts empty.
        $dir = \dirname(__DIR__, 3) . '/var/backups';
        $made = !is_dir($dir) && @mkdir($dir, 0o777, true);
        if (!is_dir($dir) || !is_writable($dir)) {
            // The path is hardcoded in the check, and this directory is shared
            // with other suites -- it is routinely left owned by whichever
            // user last ran them.
            self::markTestSkipped('var/backups is not writable by this user');
        }
        $stale = $dir . '/webcalendar-backup-audit-stale.sql';
        $fresh = $dir . '/webcalendar-backup-audit-fresh.sql';
        $at = (new \DateTimeImmutable(self::NOW))->getTimestamp();

        try {
            $before = $this->staleBackupCount();

            // A day old: inside the seven-day window, so it changes nothing.
            file_put_contents($fresh, 'x');
            touch($fresh, $at - 86400);
            self::assertSame($before, $this->staleBackupCount(), 'a recent backup is not stale');

            // Eight days old: past the cutoff.
            file_put_contents($stale, 'x');
            touch($stale, $at - (8 * 86400));

            self::assertSame($before + 1, $this->staleBackupCount());
            self::assertSame('warn', $this->check($this->audit(), 'Old backup files')['status']);
        } finally {
            @unlink($stale);
            @unlink($fresh);
            if ($made) {
                @rmdir($dir);
            }
        }
    }

    private function staleBackupCount(): int
    {
        $detail = $this->check($this->audit(), 'Old backup files')['detail'];

        return preg_match('/^(\d+) backup file/', $detail, $m) === 1 ? (int) $m[1] : 0;
    }

}
