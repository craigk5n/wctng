<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Control;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantCrudTest extends WebTestCase
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

    public function testListTenants(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $client->request('GET', '/control/v1/tenants', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertIsArray($body['data']);
    }

    public function testCreateTenant(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $slug = 'crud-' . bin2hex(random_bytes(3));
        $client->request('POST', '/control/v1/tenants', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([
            'slug' => $slug,
            'name' => 'CRUD Test',
            'admin_email' => 'test@example.com',
            'plan' => 'pro',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $body = $this->decodeResponse($client);
        $this->assertSame($slug, $body['data']['slug']);
        $this->assertArrayHasKey('admin_password', $body['data']);
    }

    public function testGetTenantDetails(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $slug = 'detail-' . bin2hex(random_bytes(3));
        $client->request('POST', '/control/v1/tenants', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['slug' => $slug, 'name' => 'Detail Test']));
        $this->assertResponseStatusCodeSame(201);

        $client->request('GET', "/control/v1/tenants/{$slug}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame($slug, $body['data']['slug']);
        $this->assertSame('Detail Test', $body['data']['name']);
        $this->assertArrayHasKey('user_count', $body['data']);
        $this->assertArrayHasKey('event_count', $body['data']);
    }

    public function testUpdateTenant(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $slug = 'update-' . bin2hex(random_bytes(3));
        $client->request('POST', '/control/v1/tenants', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['slug' => $slug, 'name' => 'Before']));

        $client->request('PUT', "/control/v1/tenants/{$slug}", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['name' => 'After', 'plan' => 'enterprise']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('After', $body['data']['name']);
        $this->assertSame('enterprise', $body['data']['plan']);
    }

    public function testDeleteTenantRequiresConfirm(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $slug = 'nodelete-' . bin2hex(random_bytes(3));
        $client->request('POST', '/control/v1/tenants', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['slug' => $slug, 'name' => 'NoDelete']));

        // Without ?confirm=true
        $client->request('DELETE', "/control/v1/tenants/{$slug}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testDeleteTenantWithConfirm(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $slug = 'delme-' . bin2hex(random_bytes(3));
        $client->request('POST', '/control/v1/tenants', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['slug' => $slug, 'name' => 'Delete Me']));

        $client->request('DELETE', "/control/v1/tenants/{$slug}?confirm=true", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(204);

        // Verify it's gone
        $client->request('GET', "/control/v1/tenants/{$slug}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateTenantMissingSlug(): void
    {
        $client = static::createClient();
        $token = $this->getSuperAdminToken($client);

        $client->request('POST', '/control/v1/tenants', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['name' => 'No Slug']));

        $this->assertResponseStatusCodeSame(400);
    }
}
