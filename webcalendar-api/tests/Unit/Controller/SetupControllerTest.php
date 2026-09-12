<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\SetupController;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\User;

/**
 * The first-run installer.
 *
 * /api/v2/setup/ has its own firewall and is PUBLIC_ACCESS, so install() is
 * reachable by anyone who can reach the site. The only thing standing between
 * an anonymous caller and an administrator account is the check that setup has
 * already been done -- and nothing executed a line of this controller.
 */
final class SetupControllerTest extends TestCase
{
    private \PDO $pdo;
    private CoreServiceFactory $factory;
    private SetupController $controller;

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

        $this->factory = new CoreServiceFactory($this->pdo, 'setup_test_secret_32_characters!');
        $this->controller = new SetupController($this->factory->getUserService(), $this->factory->getUserRepository());
    }

    /** @param array<string, mixed> $body */
    private function install(array $body): JsonResponse
    {
        return $this->controller->install(
            Request::create('/api/v2/setup/install', 'POST', [], [], [], [], (string) json_encode($body)),
        );
    }

    private function needsSetup(): bool
    {
        /** @var array{data: array{needs_setup: bool}} $body */
        $body = json_decode((string) $this->controller->status()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['data']['needs_setup'];
    }

    /** @return list<string> */
    private function adminLogins(): array
    {
        $rows = $this->pdo->query("SELECT cal_login FROM webcal_user WHERE cal_is_admin = 'Y' ORDER BY cal_login")
            ?->fetchAll(\PDO::FETCH_COLUMN);

        return \is_array($rows) ? $rows : [];
    }

    // ------------------------------------------------------------- status

    public function testAFreshInstallationSaysItNeedsSetup(): void
    {
        self::assertTrue($this->needsSetup());
    }

    public function testInstallingTheFirstAdminClosesSetup(): void
    {
        $this->install(['username' => 'admin', 'password' => 'a-long-password', 'email' => 'admin@example.com']);

        self::assertFalse($this->needsSetup());
    }

    /** @return iterable<string, array{string}> */
    public static function administratorNames(): iterable
    {
        // The account does not have to be called "admin". Asking for that
        // login by name is what left every other installation open.
        yield 'the conventional name' => ['admin'];
        yield 'a person' => ['craig'];
        yield 'a role' => ['owner'];
        yield 'an address-derived login' => ['alice.smith'];
    }

    #[DataProvider('administratorNames')]
    public function testSetupIsClosedByAnAdministratorWhateverTheyAreCalled(string $username): void
    {
        // With this open, /api/v2/setup/install stays reachable by anyone --
        // and it is a public route -- so an anonymous caller can give
        // themselves an administrator account on a running system.
        $response = $this->install([
            'username' => $username,
            'password' => 'a-long-password',
            'email' => $username . '@example.com',
        ]);
        self::assertSame(200, $response->getStatusCode());

        self::assertFalse($this->needsSetup(), "setup is still open after {$username} was made an administrator");
    }

    #[DataProvider('administratorNames')]
    public function testASecondInstallIsRefusedWhateverTheFirstAdminWasCalled(string $username): void
    {
        $this->install([
            'username' => $username,
            'password' => 'a-long-password',
            'email' => $username . '@example.com',
        ]);

        $second = $this->install([
            'username' => 'attacker',
            'password' => 'another-password',
            'email' => 'attacker@example.com',
        ]);

        self::assertSame(400, $second->getStatusCode());
        self::assertSame([$username], $this->adminLogins(), 'no second administrator should exist');
    }

    public function testAnOrdinaryUserDoesNotCountAsSetupBeingDone(): void
    {
        // Only an administrator closes setup. A database holding nothing but
        // ordinary accounts still needs one.
        $admin = new User('seed-admin', 'Seed', 'Admin', 'seed@example.com', true, true);
        $this->factory->getUserService()->createUser(
            new User('bob', 'Bob', 'Jones', 'bob@example.com', false, true),
            $admin,
        );

        self::assertTrue($this->needsSetup());
    }

    // ------------------------------------------------------------ install

    public function testTheInstalledAdminCanSignInWithThePasswordItWasGiven(): void
    {
        $this->install(['username' => 'admin', 'password' => 'correct horse battery', 'email' => 'admin@example.com']);

        $hash = $this->factory->getUserRepository()->getPasswordHash('admin');
        self::assertIsString($hash);
        self::assertTrue(password_verify('correct horse battery', $hash));
        self::assertSame(['admin'], $this->adminLogins());
    }

    public function testTheInstalledAccountIsAnAdministrator(): void
    {
        $this->install(['username' => 'admin', 'password' => 'a-long-password', 'email' => 'admin@example.com']);

        $user = $this->factory->getUserService()->getUserByLogin('admin');
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertTrue($user->isEnabled());
        self::assertSame('admin@example.com', $user->email());
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $response = $this->controller->install(
            Request::create('/api/v2/setup/install', 'POST', [], [], [], [], 'not json'),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->adminLogins());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function incompleteBodies(): iterable
    {
        $full = ['username' => 'admin', 'password' => 'a-long-password', 'email' => 'admin@example.com'];

        foreach (['username', 'password', 'email'] as $field) {
            $missing = $full;
            unset($missing[$field]);
            yield "no {$field}" => [$missing, $field];

            $blank = $full;
            $blank[$field] = '';
            yield "an empty {$field}" => [$blank, $field];

            $wrongType = $full;
            $wrongType[$field] = 42;
            yield "a {$field} that is not a string" => [$wrongType, $field];
        }
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('incompleteBodies')]
    public function testEveryFieldIsRequired(array $body, string $field): void
    {
        $response = $this->install($body);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString($field, (string) $response->getContent());
        self::assertSame([], $this->adminLogins(), 'nothing should have been created');
    }
}
