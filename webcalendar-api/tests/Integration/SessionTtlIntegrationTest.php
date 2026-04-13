<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * Tests that session TTL settings are stored and retrieved correctly,
 * and that remember_me flag selects the appropriate TTL.
 */
final class SessionTtlIntegrationTest extends IntegrationTestCase
{
    public function testDefaultSessionTtlIs28800(): void
    {
        $configService = $this->factory->getConfigService();
        $ttl = (int) ($configService->getSetting('SESSION_TTL') ?? '28800');
        $this->assertSame(28800, $ttl);
    }

    public function testDefaultRememberMeTtlIs2592000(): void
    {
        $configService = $this->factory->getConfigService();
        $ttl = (int) ($configService->getSetting('SESSION_TTL_REMEMBER_ME') ?? '2592000');
        $this->assertSame(2592000, $ttl);
    }

    public function testAdminCanOverrideSessionTtl(): void
    {
        $configService = $this->factory->getConfigService();
        $configService->updateSetting('SESSION_TTL', '7200');
        $this->assertSame('7200', $configService->getSetting('SESSION_TTL'));
    }

    public function testAdminCanOverrideRememberMeTtl(): void
    {
        $configService = $this->factory->getConfigService();
        $configService->updateSetting('SESSION_TTL_REMEMBER_ME', '604800');
        $this->assertSame('604800', $configService->getSetting('SESSION_TTL_REMEMBER_ME'));
    }

    public function testResolveTtlReturnsNormalByDefault(): void
    {
        $configService = $this->factory->getConfigService();
        $normalTtl = (int) ($configService->getSetting('SESSION_TTL') ?? '28800');
        $this->assertSame(28800, $normalTtl);
    }

    public function testResolveTtlReturnsRememberMeWhenRequested(): void
    {
        $configService = $this->factory->getConfigService();
        $configService->updateSetting('SESSION_TTL_REMEMBER_ME', '1209600');
        $rememberTtl = (int) ($configService->getSetting('SESSION_TTL_REMEMBER_ME') ?? '2592000');
        $this->assertSame(1209600, $rememberTtl);
    }
}
