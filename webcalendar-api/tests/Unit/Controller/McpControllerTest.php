<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\McpController;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class McpControllerTest extends TestCase
{
    private \PDO $pdo;
    private CoreServiceFactory $factory;
    private McpController $controller;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Load schema from vendor (installed via Composer from GitHub)
        $schemaPath = realpath(__DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql');
        if ($schemaPath !== false) {
            $schema = file_get_contents($schemaPath);
            if ($schema !== false) {
                $clean = (string) preg_replace('/--[^\n]*/', '', $schema);
                /** @var string[] $stmts */
                $stmts = preg_split('/;\s*\n/', $clean) ?? [];
                foreach ($stmts as $s) {
                    $s = trim($s);
                    if ($s !== '') {
                        try {
                            $this->pdo->exec($s);
                        } catch (\PDOException) {
                        }
                    }
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'test_secret');

        // Create admin user with API token
        $admin = new User('admin', 'Admin', 'User', 'admin@test.com', true, true);
        $this->factory->getUserService()->createUser($admin, $admin);
        $this->factory->getUserRepository()->savePreference('admin', new UserPreference('api_token', 'test-token-123'));

        $this->controller = new McpController(
            $this->factory->getEventService(),
            $this->factory->getUserService(),
            $this->factory->getBookingService(),
            $this->factory->getEventRepository(),
            $this->factory->getUserRepository(),
        );
    }

    public function testToolsListReturnsToolDefinitions(): void
    {
        $request = $this->makeRequest('tools/list', [], 'test-token-123');
        $response = $this->controller->handle($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayHasKey('tools', $body['result']);
        $this->assertGreaterThan(5, \count($body['result']['tools']));

        $toolNames = array_column($body['result']['tools'], 'name');
        $this->assertContains('list_events', $toolNames);
        $this->assertContains('create_event', $toolNames);
        $this->assertContains('search_events', $toolNames);
    }

    public function testAuthenticationRequired(): void
    {
        $request = $this->makeRequest('tools/list', []);
        $response = $this->controller->handle($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32000, $body['error']['code']);
    }

    public function testCreateAndGetEvent(): void
    {
        $request = $this->makeRequest('tools/call', [
            'name' => 'create_event',
            'arguments' => [
                'title' => 'MCP Test Event',
                'start_date' => '20260601',
                'start_time' => '100000',
                'duration' => 60,
                'description' => 'Created via MCP',
            ],
        ], 'test-token-123');

        $response = $this->controller->handle($request);
        $body = json_decode((string) $response->getContent(), true);

        $this->assertTrue($body['result']['created']);
        $this->assertSame('MCP Test Event', $body['result']['event']['title']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $request = $this->makeRequest('nonexistent', [], 'test-token-123');
        $response = $this->controller->handle($request);

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(-32601, $body['error']['code']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function makeRequest(string $method, array $params, string $token = ''): Request
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ], \JSON_THROW_ON_ERROR);

        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== '') {
            $headers['HTTP_X_API_TOKEN'] = $token;
        }

        return Request::create('/api/v2/mcp', 'POST', [], [], [], $headers, $body);
    }
}
