<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes HTML descriptions for events, tasks, and journals.
 *
 * Preserves safe formatting tags while stripping dangerous elements,
 * attributes, and javascript: schemes.
 */
final class DescriptionSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('b')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('blockquote')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
        ;

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Sanitizes an HTML description string.
     *
     * Plain text passes through unchanged. HTML is filtered to only allow
     * safe formatting tags and attributes.
     */
    public function sanitize(string $description): string
    {
        // Fast path: no HTML at all
        if ($description === '' || $description === strip_tags($description)) {
            return $description;
        }

        return $this->sanitizer->sanitize($description);
    }
}
