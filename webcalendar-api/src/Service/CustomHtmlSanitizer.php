<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes custom header/trailer HTML and CSS for admin-defined page customization.
 * More permissive than DescriptionSanitizer — allows structural elements but strips scripts.
 */
final class CustomHtmlSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            // Text formatting
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('b')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('span', ['class', 'style'])
            // Structural
            ->allowElement('div', ['class', 'id', 'style'])
            ->allowElement('header', ['class', 'id'])
            ->allowElement('footer', ['class', 'id'])
            ->allowElement('nav', ['class', 'id'])
            ->allowElement('section', ['class', 'id'])
            // Lists
            ->allowElement('ul', ['class'])
            ->allowElement('ol', ['class'])
            ->allowElement('li')
            // Headings
            ->allowElement('h1', ['class'])
            ->allowElement('h2', ['class'])
            ->allowElement('h3', ['class'])
            ->allowElement('h4', ['class'])
            // Links and images
            ->allowElement('a', ['href', 'class', 'target', 'rel'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'class'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes(['https', 'http'])
        ;

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Sanitizes custom HTML — strips script, iframe, event handlers.
     */
    public function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        return $this->sanitizer->sanitize($html);
    }

    /**
     * Sanitizes custom CSS — strips dangerous constructs.
     */
    public function sanitizeCss(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        // Strip dangerous CSS constructs
        $css = (string) preg_replace('/expression\s*\(/i', '/* blocked */(', $css);
        $css = (string) preg_replace('/url\s*\(\s*["\']?\s*javascript:/i', 'url(/* blocked */', $css);
        $css = (string) preg_replace('/@import\b/i', '/* @import blocked */', $css);
        $css = (string) preg_replace('/behavior\s*:/i', '/* behavior blocked */:', $css);
        $css = (string) preg_replace('/-moz-binding\s*:/i', '/* binding blocked */:', $css);

        return $css;
    }
}
