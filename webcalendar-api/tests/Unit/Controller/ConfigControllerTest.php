<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ConfigController;
use App\Service\CoreServiceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests ConfigController using a real SQLite DB since CoreServiceFactory is final.
 */
final class ConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private CoreServiceFactory $factory;
    private ConfigController $controller;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create webcal_config table
        $this->pdo->exec('CREATE TABLE webcal_config (cal_setting VARCHAR(60) PRIMARY KEY, cal_value VARCHAR(100))');

        $this->factory = new CoreServiceFactory($this->pdo, 'test_secret');
        $this->controller = new ConfigController($this->factory);
    }

    public function testGetFeaturesReturnsDefaults(): void
    {
        $response = $this->controller->getFeatures();
        $this->assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);
        $features = $body['data'];

        $this->assertSame('Y', $features['ALLOW_HTML_DESCRIPTION']);
        $this->assertSame('N', $features['DISABLE_LOCATION_FIELD']);
        $this->assertSame('N', $features['DISABLE_URL_FIELD']);
        $this->assertSame('N', $features['DISABLE_PRIORITY_FIELD']);
        $this->assertSame('N', $features['DISABLE_PARTICIPANTS_FIELD']);
    }

    public function testGetFeaturesReturnsStoredValues(): void
    {
        // Set a config value
        $this->factory->getConfigService()->updateSetting('ALLOW_HTML_DESCRIPTION', 'N');

        $response = $this->controller->getFeatures();
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame('N', $body['data']['ALLOW_HTML_DESCRIPTION']);
        // Others still defaults
        $this->assertSame('N', $body['data']['DISABLE_LOCATION_FIELD']);
    }

    public function testGetConfigRequiresAuth(): void
    {
        $response = $this->controller->getConfig(null);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateConfigRequiresAuth(): void
    {
        $request = Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $response = $this->controller->updateConfig($request, null);
        $this->assertSame(403, $response->getStatusCode());
    }
}
