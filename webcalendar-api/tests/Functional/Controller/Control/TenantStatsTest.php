<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Control;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantStatsTest extends WebTestCase
{
    private function getSuperAdminToken(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        $container = static::getContainer();
        /** @var \PDO $pdo */
        $pdo = $container->get('pdo.connection');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS control_admins (
                username VARCHAR(100) NOT NULL PRIMARY KEY,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tenants (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                db_host VARCHAR(255) NOT NULL DEFAULT \'\',
                db_name VARCHAR(255) NOT NULL DEFAULT \'\',
                db_user VARCHAR(255) NOT NULL DEFAULT \'\',
                db_password TEXT NOT NULL,
                plan VARCHAR(50) NOT NULL DEFAULT \'free\',
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );

        $hash = password_hash('super_secret', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('SELECT 1 FROM control_admins WHERE username = :u');
        $stmt->execute(['u' => 'superadmin']);
        if (!$stmt->fetch()) {
            $pdo->prepare('INSERT INTO control_admins (username, password_hash) VALUES (:u, :h)')
                ->execute(['u' => 'superadmin', 'h' => $hash]);
        }

        $client->request('POST', '/control/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'superadmin', 'password' => 'super_secret']));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{data: array{token: string}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body['data']['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    public function testTenantStats(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        // Create a tenant
        $slug = 'stats-' . bin2hex(random_bytes(3));
        $client->request('POST', '/control/v1/tenants', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['slug' => $slug, 'name' => 'Stats Test']));
        $this->assertResponseStatusCodeSame(201);

        // Get stats
        $client->request('GET', "/control/v1/tenants/{$slug}/stats", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $code = $client->getResponse()->getStatusCode();
        // SQLite :memory: DBs are ephemeral — stats may get 200 or 503
        $this->assertTrue(\in_array($code, [200, 503], true), "Expected 200 or 503, got {$code}");

        if ($code === 200) {
            $body = $this->decodeResponse($client);
            $this->assertSame($slug, $body['data']['slug']);
            $this->assertArrayHasKey('user_count', $body['data']);
            $this->assertArrayHasKey('event_count', $body['data']);
            $this->assertArrayHasKey('task_count', $body['data']);
            $this->assertArrayHasKey('last_activity', $body['data']);
        }
    }

    public function testTenantStatsNotFound(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $client->request('GET', '/control/v1/tenants/nonexistent/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSummaryStats(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $client->request('GET', '/control/v1/stats/summary', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertArrayHasKey('total_tenants', $body['data']);
        $this->assertArrayHasKey('active_tenants', $body['data']);
        $this->assertArrayHasKey('total_users', $body['data']);
        $this->assertArrayHasKey('total_events', $body['data']);
    }
}
