<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Api\UnsubscribeController;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class UnsubscribeControllerIntegrationTest extends IntegrationTestCase
{
    private UnsubscribeController $controller;
    private string $appSecret = 'test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new UnsubscribeController($this->factory->getUserRepository(), $this->appSecret);
    }

    public function testUnsubscribeWithValidToken(): void
    {
        // Set some preferences first
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('REMINDER_MINUTES', '30'));
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('daily_agenda_enabled', 'Y'));

        $token = UnsubscribeController::generateToken('alice', $this->appSecret);
        $response = $this->controller->unsubscribe($token);

        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $this->assertStringContainsString('Unsubscribed', $html);

        // Verify preferences were updated
        $prefs = $this->factory->getUserRepository()->getPreferences('alice');
        $prefMap = [];
        foreach ($prefs as $p) {
            $prefMap[$p->key()] = $p->value();
        }
        $this->assertSame('0', $prefMap['REMINDER_MINUTES'] ?? '');
        $this->assertSame('N', $prefMap['daily_agenda_enabled'] ?? '');
        $this->assertSame('N', $prefMap['EMAIL_INVITATION'] ?? '');
        $this->assertSame('N', $prefMap['EMAIL_UPDATE'] ?? '');
    }

    public function testUnsubscribeWithInvalidToken(): void
    {
        $response = $this->controller->unsubscribe('invalid-token-here');

        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $this->assertStringContainsString('Invalid', $html);
    }

    public function testTokenIsDeterministic(): void
    {
        $token1 = UnsubscribeController::generateToken('alice', $this->appSecret);
        $token2 = UnsubscribeController::generateToken('alice', $this->appSecret);
        $this->assertSame($token1, $token2);
    }

    public function testDifferentUsersGetDifferentTokens(): void
    {
        $aliceToken = UnsubscribeController::generateToken('alice', $this->appSecret);
        $adminToken = UnsubscribeController::generateToken('admin', $this->appSecret);
        $this->assertNotSame($aliceToken, $adminToken);
    }

    public function testDifferentSecretsProduceDifferentTokens(): void
    {
        $token1 = UnsubscribeController::generateToken('alice', 'secret1');
        $token2 = UnsubscribeController::generateToken('alice', 'secret2');
        $this->assertNotSame($token1, $token2);
    }
}
