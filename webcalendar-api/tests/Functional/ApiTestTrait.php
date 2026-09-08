<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Shared helpers for functional API tests.
 *
 * Test classes using this trait should call `$this->cleanupTestData()`
 * in their `tearDown()` method to remove users and events created during the test.
 */
trait ApiTestTrait
{
    /** @var list<string> Logins created during this test, cleaned up in tearDown */
    private array $createdUsers = [];

    /** @var list<int> Event IDs created during this test, cleaned up in tearDown */
    private array $createdEvents = [];

    private function loginAndGetToken(KernelBrowser $client, string $username = 'admin', string $password = 'admin'): string
    {
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => $username, 'password' => $password]));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{data: array{token: string}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['token'];
    }

    /**
     * Creates a test user via the API and registers it for automatic cleanup.
     */
    private function createTestUser(KernelBrowser $client, string $token, string $login, string $email = '', string $password = 'Pass123!'): void
    {
        if ($email === '') {
            $email = $login . '@example.com';
        }

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'login' => $login,
            'password' => $password,
            'email' => $email,
        ]));

        $this->createdUsers[] = $login;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function createTestEvent(KernelBrowser $client, string $token, array $eventData = []): int
    {
        $defaults = [
            'title' => 'Test Event',
            'start_date' => '20260315',
            'start_time' => '100000',
            'duration' => 60,
        ];

        $client->request('POST', '/api/v2/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(array_merge($defaults, $eventData)));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{data: array{id: int}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        $id = $body['data']['id'];
        $this->createdEvents[] = $id;

        return $id;
    }

    /**
     * Deletes all users and events created during this test via direct PDO.
     * Safe to call from tearDown() — does not boot a new kernel.
     */
    private function cleanupTestData(): void
    {
        if ($this->createdUsers === [] && $this->createdEvents === []) {
            return;
        }

        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '';
        if ($dsn === '') {
            return;
        }

        // Parse DATABASE_URL: mysql://user:pass@host:port/dbname
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return;
        }

        $dbname = ltrim($parts['path'] ?? '', '/');
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 3306;
        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? '';

        try {
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$dbname}",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException) {
            return;
        }

        foreach ($this->createdEvents as $eventId) {
            $pdo->prepare('DELETE FROM webcal_entry_user WHERE cal_id = :id')->execute(['id' => $eventId]);
            $pdo->prepare('DELETE FROM webcal_entry_repeats WHERE cal_id = :id')->execute(['id' => $eventId]);
            $pdo->prepare('DELETE FROM webcal_entry_repeats_not WHERE cal_id = :id')->execute(['id' => $eventId]);
            $pdo->prepare('DELETE FROM webcal_entry_categories WHERE cal_id = :id')->execute(['id' => $eventId]);
            $pdo->prepare('DELETE FROM webcal_site_extras WHERE cal_id = :id')->execute(['id' => $eventId]);
            $pdo->prepare('DELETE FROM webcal_reminders WHERE cal_id = :id')->execute(['id' => $eventId]);
            $pdo->prepare('DELETE FROM webcal_entry_log WHERE cal_entry_id = :id')->execute(['id' => $eventId]);
            $pdo->prepare('DELETE FROM webcal_entry WHERE cal_id = :id')->execute(['id' => $eventId]);
        }
        $this->createdEvents = [];

        foreach ($this->createdUsers as $login) {
            $pdo->prepare('DELETE FROM webcal_user_pref WHERE cal_login = :login')->execute(['login' => $login]);
            $pdo->prepare('DELETE FROM webcal_user_layers WHERE cal_login = :login OR cal_layeruser = :login')->execute(['login' => $login]);
            $pdo->prepare('DELETE FROM webcal_entry_user WHERE cal_login = :login')->execute(['login' => $login]);
            $pdo->prepare('DELETE FROM webcal_group_user WHERE cal_login = :login')->execute(['login' => $login]);
            $pdo->prepare('DELETE FROM webcal_access_user WHERE cal_login = :login OR cal_other_user = :login')->execute(['login' => $login]);
            $pdo->prepare('DELETE FROM webcal_access_function WHERE cal_login = :login')->execute(['login' => $login]);
            $pdo->prepare('DELETE FROM webcal_entry_log WHERE cal_login = :login')->execute(['login' => $login]);
            $pdo->prepare('DELETE FROM webcal_user WHERE cal_login = :login')->execute(['login' => $login]);
        }
        $this->createdUsers = [];
    }

    /**
     * Registers an externally-created user login for cleanup.
     */
    private function trackUser(string $login): void
    {
        $this->createdUsers[] = $login;
    }

    /**
     * Registers an externally-created event ID for cleanup.
     */
    private function trackEvent(int $id): void
    {
        $this->createdEvents[] = $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        $body = json_decode($content, true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
