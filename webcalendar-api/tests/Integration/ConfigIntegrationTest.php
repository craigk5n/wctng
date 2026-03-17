<?php

declare(strict_types=1);

namespace App\Tests\Integration;

final class ConfigIntegrationTest extends IntegrationTestCase
{
    public function testConfigGetSetRoundTrip(): void
    {
        $configService = $this->factory->getConfigService();

        // Default: not set
        $this->assertNull($configService->getSetting('ALLOW_HTML_DESCRIPTION'));

        // Set
        $configService->updateSetting('ALLOW_HTML_DESCRIPTION', 'N');

        // Get
        $this->assertSame('N', $configService->getSetting('ALLOW_HTML_DESCRIPTION'));

        // Update
        $configService->updateSetting('ALLOW_HTML_DESCRIPTION', 'Y');
        $this->assertSame('Y', $configService->getSetting('ALLOW_HTML_DESCRIPTION'));
    }

    public function testGetAllSettings(): void
    {
        $configService = $this->factory->getConfigService();

        $configService->updateSetting('SETTING_A', 'value_a');
        $configService->updateSetting('SETTING_B', 'value_b');

        $all = $configService->getAllSettings();
        $this->assertSame('value_a', $all['SETTING_A']);
        $this->assertSame('value_b', $all['SETTING_B']);
    }
}
