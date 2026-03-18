<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\CustomHtmlSanitizer;

final class CustomHtmlControllerIntegrationTest extends IntegrationTestCase
{
    private CustomHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new CustomHtmlSanitizer();
    }

    public function testSanitizeHtmlStripsScript(): void
    {
        $input = '<div class="header">Hello</div><script>alert("xss")</script>';
        $result = $this->sanitizer->sanitizeHtml($input);

        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('class="header"', $result);
        $this->assertStringNotContainsString('script', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    public function testSanitizeHtmlStripsIframe(): void
    {
        $input = '<div>Content</div><iframe src="https://evil.com"></iframe>';
        $result = $this->sanitizer->sanitizeHtml($input);

        $this->assertStringContainsString('Content', $result);
        $this->assertStringNotContainsString('iframe', $result);
    }

    public function testSanitizeHtmlAllowsStructuralElements(): void
    {
        $input = '<header class="top"><nav id="main-nav"><a href="https://example.com">Home</a></nav></header>';
        $result = $this->sanitizer->sanitizeHtml($input);

        $this->assertStringContainsString('<header', $result);
        $this->assertStringContainsString('<nav', $result);
        $this->assertStringContainsString('<a href="https://example.com"', $result);
    }

    public function testSanitizeHtmlAllowsImages(): void
    {
        $input = '<img src="https://example.com/logo.png" alt="Logo" width="100">';
        $result = $this->sanitizer->sanitizeHtml($input);

        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('src="https://example.com/logo.png"', $result);
        $this->assertStringContainsString('alt="Logo"', $result);
    }

    public function testSanitizeHtmlStripsEventHandlers(): void
    {
        $input = '<div onclick="alert(1)">Click me</div>';
        $result = $this->sanitizer->sanitizeHtml($input);

        $this->assertStringContainsString('Click me', $result);
        $this->assertStringNotContainsString('onclick', $result);
    }

    public function testSanitizeCssStripsExpression(): void
    {
        $input = '.header { width: expression(document.body.clientWidth); }';
        $result = $this->sanitizer->sanitizeCss($input);

        $this->assertStringNotContainsString('expression(', $result);
        $this->assertStringContainsString('blocked', $result);
    }

    public function testSanitizeCssStripsJavascriptUrl(): void
    {
        $input = '.bg { background: url(javascript:alert(1)); }';
        $result = $this->sanitizer->sanitizeCss($input);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testSanitizeCssStripsImport(): void
    {
        $input = '@import url("https://evil.com/inject.css"); .foo { color: red; }';
        $result = $this->sanitizer->sanitizeCss($input);

        // @import directive should be neutralized (blocked in a comment)
        $this->assertStringContainsString('blocked', $result);
        $this->assertStringContainsString('color: red', $result);
        // Original @import should not be executable
        $this->assertStringNotContainsString('@import url', $result);
    }

    public function testSanitizeCssAllowsSafeStyles(): void
    {
        $input = '.header { background: #3788d8; color: white; padding: 1rem; font-size: 16px; }';
        $result = $this->sanitizer->sanitizeCss($input);

        $this->assertSame($input, $result);
    }

    public function testConfigServiceStoresAndRetrievesValues(): void
    {
        $configService = $this->factory->getConfigService();

        $configService->updateSetting('CUSTOM_HEADER_HTML', '<div>Header</div>');
        $configService->updateSetting('CUSTOM_TRAILER_HTML', '<footer>Footer</footer>');
        $configService->updateSetting('CUSTOM_CSS', '.foo { color: red; }');

        $this->assertSame('<div>Header</div>', $configService->getSetting('CUSTOM_HEADER_HTML'));
        $this->assertSame('<footer>Footer</footer>', $configService->getSetting('CUSTOM_TRAILER_HTML'));
        $this->assertSame('.foo { color: red; }', $configService->getSetting('CUSTOM_CSS'));
    }
}
