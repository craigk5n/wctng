<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Provides custom HTML/CSS values for SSR page rendering.
 */
final class CustomHtmlProvider
{
    public function __construct(
        private readonly CoreServiceFactory $factory,
    ) {
    }

    public function getHeaderHtml(): string
    {
        return $this->factory->getConfigService()->getSetting('CUSTOM_HEADER_HTML') ?? '';
    }

    public function getTrailerHtml(): string
    {
        return $this->factory->getConfigService()->getSetting('CUSTOM_TRAILER_HTML') ?? '';
    }

    public function getCustomCss(): string
    {
        return $this->factory->getConfigService()->getSetting('CUSTOM_CSS') ?? '';
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
