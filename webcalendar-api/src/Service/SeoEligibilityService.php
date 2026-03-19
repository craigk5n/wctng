<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Determines whether SEO pages should be rendered for a given user.
 *
 * Three-tier check:
 * 1. Admin: ENABLE_SEO_PAGES must be 'Y' (global toggle)
 * 2. User: public_calendar_enabled must be 'Y' (user opted into public calendar)
 * 3. User: seo_indexing_enabled must not be 'N' (user hasn't opted out of indexing; default is Y)
 */
final class SeoEligibilityService
{
    public function __construct(
        private readonly CoreServiceFactory $factory,
    ) {
    }

    /**
     * Returns whether SEO pages are globally enabled by the admin.
     */
    public function isSeoEnabledGlobally(): bool
    {
        $value = $this->factory->getConfigService()->getSetting('ENABLE_SEO_PAGES', 'N');
        return $value === 'Y';
    }

    /**
     * Returns the SEO eligibility status for a specific user.
     *
     * @return array{eligible: bool, noindex: bool, reason: string}
     *   - eligible: whether the SSR page should render at all
     *   - noindex: whether to add <meta name="robots" content="noindex">
     *   - reason: human-readable explanation
     */
    public function getUserSeoStatus(string $login): array
    {
        // Check admin global flag
        if (!$this->isSeoEnabledGlobally()) {
            return ['eligible' => false, 'noindex' => true, 'reason' => 'SEO pages disabled by admin'];
        }

        $prefs = $this->factory->getUserRepository()->getPreferences($login);

        $publicEnabled = false;
        $seoEnabled = true; // default Y

        foreach ($prefs as $pref) {
            if ($pref->key() === 'public_calendar_enabled' && $pref->value() === 'Y') {
                $publicEnabled = true;
            }
            if ($pref->key() === 'seo_indexing_enabled' && $pref->value() === 'N') {
                $seoEnabled = false;
            }
        }

        if (!$publicEnabled) {
            return ['eligible' => false, 'noindex' => true, 'reason' => 'User has not enabled public calendar'];
        }

        if (!$seoEnabled) {
            // Page renders but with noindex — accessible via direct link but not crawled
            return ['eligible' => true, 'noindex' => true, 'reason' => 'User opted out of search engine indexing'];
        }

        return ['eligible' => true, 'noindex' => false, 'reason' => 'Eligible for indexing'];
    }
}
