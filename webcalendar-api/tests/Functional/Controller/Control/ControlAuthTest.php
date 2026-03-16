<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Control;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ControlAuthTest extends WebTestCase
{
    private function ensureSuperAdmin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        // Create a super admin directly in the control DB
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

        $hash = password_hash('super_secret', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('SELECT 1 FROM control_admins WHERE username = :u');
        $stmt->execute(['u' => 'superadmin']);
        if (!$stmt->fetch()) {
            $pdo->prepare('INSERT INTO control_admins (username, password_hash) VALUES (:u, :h)')
                ->execute(['u' => 'superadmin', 'h' => $hash]);
        }
    }

    private function loginSuperAdmin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        $this->ensureSuperAdmin($client);

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

    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        $this->ensureSuperAdmin($client);

        $client->request('POST', '/control/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'superadmin', 'password' => 'super_secret']));

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array<string, mixed> $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);

        $this->assertArrayHasKey('token', $body['data']);
        $this->assertSame('super_admin', $body['data']['user']['role']);
    }

    public function testLoginWithInvalidPassword(): void
    {
        $client = static::createClient();
        $this->ensureSuperAdmin($client);

        $client->request('POST', '/control/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'superadmin', 'password' => 'wrong']));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginWithUnknownUser(): void
    {
        $client = static::createClient();
        $this->ensureSuperAdmin($client);

        $client->request('POST', '/control/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'nobody', 'password' => 'pass']));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testControlPlaneRouteRequiresAuth(): void
    {
        $client = static::createClient();

        // Try accessing a control plane route without auth
        $client->request('GET', '/control/v1/tenants');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRegularTenantJwtCannotAccessControlPlane(): void
    {
        $client = static::createClient();

        // Login as a regular tenant user
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['username' => 'admin', 'password' => 'admin']));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{data: array{token: string}} $body */
        $body = json_decode($content, true);
        self::assertIsArray($body);
        $tenantToken = $body['data']['token'];

        // Try accessing control plane with tenant token
        $client->request('GET', '/control/v1/tenants', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tenantToken,
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminJwtAccessesControlPlane(): void
    {
        $client = static::createClient();
        $token = $this->loginSuperAdmin($client);

        // Access a control plane route — will 404 because the tenant CRUD
        // controller doesn't exist yet, but should NOT be 401/403
        $client->request('GET', '/control/v1/tenants', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $code = $client->getResponse()->getStatusCode();
        // Should not be auth error (401/403) — 404 is expected since route doesn't exist yet
        $this->assertNotSame(401, $code);
        $this->assertNotSame(403, $code);
    }
}
