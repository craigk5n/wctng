<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\SeoEligibilityService;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

final class SeoEligibilityIntegrationTest extends IntegrationTestCase
{
    private SeoEligibilityService $seoService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seoService = new SeoEligibilityService($this->factory);
    }

    public function testSeoDisabledByDefaultGlobally(): void
    {
        $this->assertFalse($this->seoService->isSeoEnabledGlobally());
    }

    public function testSeoEnabledWhenAdminTurnsItOn(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->assertTrue($this->seoService->isSeoEnabledGlobally());
    }

    public function testUserNotEligibleWhenGloballyDisabled(): void
    {
        // Admin hasn't enabled SEO
        $status = $this->seoService->getUserSeoStatus('alice');
        $this->assertFalse($status['eligible']);
        $this->assertStringContainsString('disabled by admin', $status['reason']);
    }

    public function testUserNotEligibleWhenPublicCalendarOff(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');

        // Alice hasn't enabled public calendar
        $status = $this->seoService->getUserSeoStatus('alice');
        $this->assertFalse($status['eligible']);
        $this->assertStringContainsString('public calendar', $status['reason']);
    }

    public function testUserEligibleWhenBothEnabled(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));

        $status = $this->seoService->getUserSeoStatus('alice');
        $this->assertTrue($status['eligible']);
        $this->assertFalse($status['noindex']);
    }

    public function testUserOptedOutOfIndexing(): void
    {
        $this->factory->getConfigService()->updateSetting('ENABLE_SEO_PAGES', 'Y');
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('public_calendar_enabled', 'Y'));
        $this->factory->getUserRepository()->savePreference('alice', new UserPreference('seo_indexing_enabled', 'N'));

        $status = $this->seoService->getUserSeoStatus('alice');
        $this->assertTrue($status['eligible']); // page still renders
        $this->assertTrue($status['noindex']); // but with noindex
        $this->assertStringContainsString('opted out', $status['reason']);
    }
}
