<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use WebCalendar\Core\Application\Contract\HtmlSanitizerInterface;

/**
 * Sanitizes HTML descriptions for events, tasks, and journals.
 *
 * Preserves safe formatting tags while stripping dangerous elements,
 * attributes, and javascript: schemes.
 */
final class DescriptionSanitizer implements HtmlSanitizerInterface
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
    #[\Override]
    public function sanitize(string $html): string
    {
        // Fast path: no HTML at all
        if ($html === '' || $html === strip_tags($html)) {
            return $html;
        }

        return $this->sanitizer->sanitize($html);
    }
}
