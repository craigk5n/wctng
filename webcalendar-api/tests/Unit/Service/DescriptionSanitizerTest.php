<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DescriptionSanitizer;
use PHPUnit\Framework\TestCase;

final class DescriptionSanitizerTest extends TestCase
{
    private DescriptionSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new DescriptionSanitizer();
    }

    // --- Plain text passes through ---

    public function testPlainTextUnchanged(): void
    {
        $input = 'Just a plain text description with no HTML.';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testEmptyStringUnchanged(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }

    // --- Allowed tags preserved ---

    public function testAllowedFormattingTagsPreserved(): void
    {
        $input = '<p>Hello <strong>world</strong> and <em>universe</em></p>';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testBoldItalicUnderlinePreserved(): void
    {
        $input = '<b>bold</b> <i>italic</i> <u>underline</u>';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testListsPreserved(): void
    {
        $input = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testOrderedListPreserved(): void
    {
        $input = '<ol><li>First</li><li>Second</li></ol>';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testHeadingsPreserved(): void
    {
        $input = '<h2>Section</h2><h3>Subsection</h3>';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testBlockquotePreserved(): void
    {
        $input = '<blockquote>A wise quote</blockquote>';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testCodePreserved(): void
    {
        $input = '<code>const x = 1;</code>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringContainsString('<code>', $result);
        $this->assertStringContainsString('</code>', $result);
        $this->assertStringContainsString('const x', $result);
    }

    public function testPrePreserved(): void
    {
        $input = '<pre>formatted text</pre>';
        $this->assertSame($input, $this->sanitizer->sanitize($input));
    }

    public function testBrPreserved(): void
    {
        $input = 'Line 1<br>Line 2';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringContainsString('<br', $result);
    }

    public function testLinkWithHrefPreserved(): void
    {
        $input = '<a href="https://example.com">Link</a>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('Link</a>', $result);
    }

    public function testMailtoLinkPreserved(): void
    {
        $input = '<a href="mailto:user@example.com">Email</a>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringContainsString('mailto:', $result);
        $this->assertStringContainsString('Email</a>', $result);
    }

    // --- Dangerous tags stripped ---

    public function testScriptTagStripped(): void
    {
        $input = '<p>Hello</p><script>alert("xss")</script>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('<p>Hello</p>', $result);
    }

    public function testStyleTagStripped(): void
    {
        $input = '<style>body{display:none}</style><p>Content</p>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringContainsString('<p>Content</p>', $result);
    }

    public function testIframeStripped(): void
    {
        $input = '<iframe src="https://evil.com"></iframe>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('<iframe', $result);
    }

    public function testImgStripped(): void
    {
        $input = '<img src="https://evil.com/track.png">';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('<img', $result);
    }

    // --- Dangerous attributes stripped ---

    public function testOnclickStripped(): void
    {
        $input = '<p onclick="alert(1)">Click me</p>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('Click me', $result);
    }

    public function testOnmouseoverStripped(): void
    {
        $input = '<a href="https://ok.com" onmouseover="steal()">Link</a>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('onmouseover', $result);
    }

    public function testStyleAttributeStripped(): void
    {
        $input = '<p style="background:url(evil)">Styled</p>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('style=', $result);
        $this->assertStringContainsString('Styled', $result);
    }

    // --- javascript: scheme blocked ---

    public function testJavascriptHrefBlocked(): void
    {
        $input = '<a href="javascript:alert(1)">Click</a>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    // --- Complex XSS payloads ---

    public function testNestedScriptInAttribute(): void
    {
        $input = '<div><img src=x onerror="alert(1)"></div>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    public function testSvgXss(): void
    {
        $input = '<svg onload="alert(1)"><circle r="10"></circle></svg>';
        $result = $this->sanitizer->sanitize($input);
        $this->assertStringNotContainsString('<svg', $result);
        $this->assertStringNotContainsString('onload', $result);
    }
}
