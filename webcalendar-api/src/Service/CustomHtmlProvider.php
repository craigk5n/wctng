<?php

declare(strict_types=1);

namespace App\Service;

use WebCalendar\Core\Application\Service\ConfigService;

/**
 * Provides custom HTML/CSS values for SSR page rendering.
 */
final class CustomHtmlProvider
{
    public function __construct(
        private readonly ConfigService $configService,
    ) {}

    public function getHeaderHtml(): string
    {
        return $this->configService->getSetting('CUSTOM_HEADER_HTML') ?? '';
    }

    public function getTrailerHtml(): string
    {
        return $this->configService->getSetting('CUSTOM_TRAILER_HTML') ?? '';
    }

    public function getCustomCss(): string
    {
        return $this->configService->getSetting('CUSTOM_CSS') ?? '';
    }

    /**
     * Returns HTML for custom CSS injection in SSR <head>.
     */
    public function getCssStyleTag(): string
    {
        $css = $this->getCustomCss();
        if ($css === '') {
            return '';
        }

        // Again here, not only where the value was sanitized on its way in:
        // rows stored before that check existed are still served from this
        // method, and this is the one place that knows the CSS is about to
        // become the body of a <style> element.
        $css = preg_replace('#</style#i', '/* blocked */', $css) ?? '';

        return '<style>' . $css . '</style>';
    }
}
