<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Tests\Functional\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerUpdateTest extends WebTestCase
{
    use ApiTestTrait;

    /**
     * admin is a fixture shared by the whole suite, and two tests here edit
     * its profile: one because updating your own profile as an admin is the
     * behaviour under test, the other to check a partial update leaves the
     * other fields alone. Neither used to put it back, so whichever ran last
     * left its name behind -- in the database, and for every test in every
     * later class and later run. Captured and restored rather than hardcoded,
     * so this keeps working if the fixture changes.
     *
     * @var array{cal_firstname: string, cal_lastname: string, cal_email: string}|null
     */
    private ?array $adminProfile = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->testPdo();
        if ($pdo === null) {
            return;
        }

        $row = $pdo
            ->query("SELECT cal_firstname, cal_lastname, cal_email FROM webcal_user WHERE cal_login = 'admin'")
            ?->fetch(\PDO::FETCH_ASSOC);

        if (\is_array($row)) {
            /** @var array{cal_firstname: string, cal_lastname: string, cal_email: string} $row */
            $this->adminProfile = $row;
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreAdminProfile();
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function restoreAdminProfile(): void
    {
        if ($this->adminProfile === null) {
            return;
        }

        $this->testPdo()?->prepare(
            'UPDATE webcal_user SET cal_firstname = :first, cal_lastname = :last, cal_email = :email
             WHERE cal_login = \'admin\'',
        )->execute([
            'first' => $this->adminProfile['cal_firstname'],
            'last' => $this->adminProfile['cal_lastname'],
            'email' => $this->adminProfile['cal_email'],
        ]);

        $this->adminProfile = null;
    }

    /**
     * @return array{login: string, password: string, admin_token: string}
     */
    private function createRegularUser(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $adminToken): array
    {
        $login = 'regular_' . bin2hex(random_bytes(4));

        $client->request('POST', '/api/v2/users', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode([
            'login' => $login,
            'password' => 'RegularPass1!',
            'email' => $login . '@example.com',
            'firstname' => 'Regular',
            'lastname' => 'User',
        ]));

        $this->trackUser($login);

        return ['login' => $login, 'password' => 'RegularPass1!', 'admin_token' => $adminToken];
    }

    // --- Update Profile ---

    public function testAdminUpdateOwnProfile(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/admin', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['firstname' => 'SuperAdmin']));

        $this->assertResponseIsSuccessful();
        $body = $this->decodeResponse($client);
        $this->assertSame('SuperAdmin', $body['data']['firstname']);
    }

    public function testUpdateNonexistentUserReturns404(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/nobody_xyz', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['firstname' => 'X']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/api/v2/users/admin', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['firstname' => 'X']));

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testUpdatePreservesUnchangedFields(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        // Get current state
        $client->request('GET', '/api/v2/users/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        $before = $this->decodeResponse($client);

        // Update only firstname
        $client->request('PUT', '/api/v2/users/admin', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['firstname' => 'Changed']));

        $body = $this->decodeResponse($client);
        $this->assertSame('Changed', $body['data']['firstname']);
        $this->assertSame($before['data']['lastname'], $body['data']['lastname']);
        $this->assertSame($before['data']['email'], $body['data']['email']);
    }

    // --- Change Password ---

    public function testAdminChangeOwnPassword(): void
    {
        $client = static::createClient();
        $adminToken = $this->loginAndGetToken($client);
        $userInfo = $this->createRegularUser($client, $adminToken);

        $client->request('PUT', "/api/v2/users/{$userInfo['login']}/password", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
        ], (string) json_encode([
            'new_password' => 'NewAdminPass1!',
        ]));

        $this->assertResponseIsSuccessful();

        // Verify new password works
        $client->request('POST', '/api/v2/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'username' => $userInfo['login'],
            'password' => 'NewAdminPass1!',
        ]));

        $this->assertResponseIsSuccessful();
    }

    public function testChangePasswordRequiresNewPassword(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/admin/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode([]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('PUT', '/api/v2/users/admin/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['new_password' => 'X']));

        $code = $client->getResponse()->getStatusCode();
        $this->assertTrue(\in_array($code, [401, 403], true));
    }

    public function testChangePasswordForNonexistentUser(): void
    {
        $client = static::createClient();
        $token = $this->loginAndGetToken($client);

        $client->request('PUT', '/api/v2/users/nobody_xyz/password', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode(['new_password' => 'X']));

        $this->assertResponseStatusCodeSame(404);
    }
}
