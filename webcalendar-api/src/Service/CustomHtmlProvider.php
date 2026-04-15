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
    ) {
    }

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

        return '<style>' . $css . '</style>';
    }
}
