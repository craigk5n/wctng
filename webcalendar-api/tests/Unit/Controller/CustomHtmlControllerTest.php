<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\CustomHtmlController;
use App\Security\WebCalendarUser;
use App\Service\CustomHtmlProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use WebCalendar\Core\Application\Service\ConfigService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\ConfigRepositoryInterface;

/**
 * Administrator-defined header, trailer and stylesheet.
 *
 * Nothing executed a line of it. The whole feature is "store markup an
 * administrator wrote and put it on a page", and the only thing standing
 * between those two halves is a sanitizer -- so what it lets through is the
 * behaviour, not an implementation detail. The pages it reaches include the
 * SSR event pages, which are public and crawlable.
 */
final class CustomHtmlControllerTest extends TestCase
{
    /** @var array<string, string> */
    private array $settings = [];

    private function configService(): ConfigService
    {
        $repo = $this->createMock(ConfigRepositoryInterface::class);
        $repo->method('get')->willReturnCallback(fn(string $key): ?string => $this->settings[$key] ?? null);
        $repo->method('set')->willReturnCallback(function (string $key, string $value): void {
            $this->settings[$key] = $value;
        });
        $repo->method('getAll')->willReturnCallback(fn(): array => $this->settings);

        return new ConfigService($repo);
    }

    private function controller(): CustomHtmlController
    {
        return new CustomHtmlController($this->configService());
    }

    private static function admin(): WebCalendarUser
    {
        return new WebCalendarUser(new User('sysop', 'Sys', 'Op', 'sysop@x.com', true, true), null);
    }

    private static function ordinaryUser(): WebCalendarUser
    {
        return new WebCalendarUser(new User('bob', 'Bob', 'J', 'bob@x.com', false, true), null);
    }

    private static function request(mixed $body): Request
    {
        $content = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return Request::create('/api/v2/admin/custom-html', 'PUT', [], [], [], [], $content);
    }

    /** @return array<string, mixed> */
    private static function payload(JsonResponse $response): array
    {
        $body = $response->getContent();
        self::assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function put(array $body): JsonResponse
    {
        return $this->controller()->adminUpdate(self::request($body), self::admin());
    }

    // ------------------------------------------------------------------ access

    #[DataProvider('callersWithoutAccess')]
    public function testTheAdministratorRoutesAreAdministratorsOnly(\Closure $call, ?WebCalendarUser $caller): void
    {
        $response = $call($this->controller(), $caller);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->settings, 'nothing may be written by a caller who was refused');
    }

    /** @return iterable<string, array{\Closure, WebCalendarUser|null}> */
    public static function callersWithoutAccess(): iterable
    {
        $calls = [
            'get' => static fn(CustomHtmlController $c, ?WebCalendarUser $u): JsonResponse => $c->adminGet($u),
            'update' => static fn(CustomHtmlController $c, ?WebCalendarUser $u): JsonResponse
                => $c->adminUpdate(self::request(['header_html' => '<p>hi</p>']), $u),
        ];

        foreach ($calls as $name => $call) {
            yield "{$name}, anonymous" => [$call, null];
            yield "{$name}, not an admin" => [$call, self::ordinaryUser()];
        }
    }

    public function testAnyoneCanReadWhatThePagesWillShow(): void
    {
        // The SPA needs these before anybody has signed in.
        $this->settings = ['CUSTOM_HEADER_HTML' => '<p>Welcome</p>'];

        $data = self::payload($this->controller()->publicGet())['data'];

        $this->assertSame('<p>Welcome</p>', $data['header_html']);
    }

    public function testNothingConfiguredReadsAsEmptyRatherThanMissing(): void
    {
        $data = self::payload($this->controller()->adminGet(self::admin()))['data'];

        $this->assertSame(['header_html' => '', 'trailer_html' => '', 'custom_css' => ''], $data);
    }

    // ------------------------------------------------------------------ writing

    public function testEachFieldIsStoredUnderItsOwnSetting(): void
    {
        $response = $this->put([
            'header_html' => '<p>Top</p>',
            'trailer_html' => '<p>Bottom</p>',
            'custom_css' => 'body{color:red}',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<p>Top</p>', $this->settings['CUSTOM_HEADER_HTML']);
        $this->assertSame('<p>Bottom</p>', $this->settings['CUSTOM_TRAILER_HTML']);
        $this->assertSame('body{color:red}', $this->settings['CUSTOM_CSS']);
        $this->assertSame(
            ['header_html' => '<p>Top</p>', 'trailer_html' => '<p>Bottom</p>', 'custom_css' => 'body{color:red}'],
            self::payload($response)['data'],
        );
    }

    #[DataProvider('fieldsLeftAlone')]
    public function testAFieldThatWasNotSentIsLeftAsItWas(array $body, string $untouched): void
    {
        $this->settings = [
            'CUSTOM_HEADER_HTML' => '<p>Top</p>',
            'CUSTOM_TRAILER_HTML' => '<p>Bottom</p>',
            'CUSTOM_CSS' => 'body{color:red}',
        ];
        $before = $this->settings[$untouched];

        $this->put($body);

        $this->assertSame($before, $this->settings[$untouched]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function fieldsLeftAlone(): iterable
    {
        yield 'only the header sent' => [['header_html' => '<p>New</p>'], 'CUSTOM_TRAILER_HTML'];
        yield 'only the css sent' => [['custom_css' => 'p{}'], 'CUSTOM_HEADER_HTML'];
        yield 'a header of the wrong type' => [['header_html' => ['<p>x</p>']], 'CUSTOM_HEADER_HTML'];
        yield 'a css of the wrong type' => [['custom_css' => 42], 'CUSTOM_CSS'];
    }

    #[DataProvider('bodiesThatAreNotObjects')]
    public function testABodyThatIsNotAnObjectIsRefused(string $body): void
    {
        $response = $this->controller()->adminUpdate(self::request($body), self::admin());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->settings);
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNotObjects(): iterable
    {
        yield 'a bare string' => ['"just a string"'];
        yield 'not json at all' => ['<html>'];
        yield 'empty' => [''];
    }

    // --------------------------------------------------------- what is stripped

    #[DataProvider('markupThatMustNotSurvive')]
    public function testMarkupThatCouldRunIsStrippedFromTheHtml(string $html, string $mustNotAppear): void
    {
        $this->put(['header_html' => $html, 'trailer_html' => $html]);

        $this->assertStringNotContainsString($mustNotAppear, $this->settings['CUSTOM_HEADER_HTML']);
        $this->assertStringNotContainsString($mustNotAppear, $this->settings['CUSTOM_TRAILER_HTML']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function markupThatMustNotSurvive(): iterable
    {
        yield 'a script element' => ['<p>ok</p><script>alert(1)</script>', '<script'];
        yield 'an inline handler' => ['<div onclick="alert(1)">x</div>', 'onclick'];
        yield 'an image handler' => ['<img src=x onerror=alert(1)>', 'onerror'];
        yield 'a javascript link' => ['<a href="javascript:alert(1)">go</a>', 'javascript:'];
        yield 'an iframe' => ['<iframe src="//evil.example"></iframe>', '<iframe'];
    }

    /**
     * The allow-list is the contract of this feature in both directions: what
     * it keeps out is the security half, and what it lets through is the half
     * an administrator notices. An element or an attribute quietly dropping
     * off the list breaks somebody's banner with nothing to show for it.
     */
    #[DataProvider('markupAnAdministratorIsAllowed')]
    public function testTheMarkupAnAdministratorIsAllowedSurvives(string $html, string $expected): void
    {
        $this->put(['header_html' => $html]);

        $this->assertStringContainsString($expected, $this->settings['CUSTOM_HEADER_HTML']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function markupAnAdministratorIsAllowed(): iterable
    {
        yield 'a paragraph' => ['<p>Hi</p>', '<p>Hi</p>'];
        yield 'emphasis' => ['<strong>a</strong><em>b</em><b>c</b><i>d</i><u>e</u>', '<strong>a</strong>'];
        yield 'a span with a class' => ['<span class="x">a</span>', 'class="x"'];
        yield 'a span with a style' => ['<span style="color:red">a</span>', 'style="color:red"'];
        yield 'a div with a class' => ['<div class="x">a</div>', 'class="x"'];
        yield 'a div with an id' => ['<div id="x">a</div>', 'id="x"'];
        yield 'a div with a style' => ['<div style="color:red">a</div>', 'style="color:red"'];
        yield 'a header with a class' => ['<header class="x">a</header>', '<header class="x">'];
        yield 'a header with an id' => ['<header id="x">a</header>', 'id="x"'];
        yield 'a footer with a class' => ['<footer class="x">a</footer>', '<footer class="x">'];
        yield 'a footer with an id' => ['<footer id="x">a</footer>', 'id="x"'];
        yield 'a nav with a class' => ['<nav class="x">a</nav>', '<nav class="x">'];
        yield 'a nav with an id' => ['<nav id="x">a</nav>', 'id="x"'];
        yield 'a section with a class' => ['<section class="x">a</section>', '<section class="x">'];
        yield 'a section with an id' => ['<section id="x">a</section>', 'id="x"'];
        yield 'an unordered list' => ['<ul class="x"><li>a</li></ul>', '<ul class="x">'];
        yield 'an ordered list' => ['<ol class="x"><li>a</li></ol>', '<ol class="x">'];
        yield 'a first heading' => ['<h1 class="x">a</h1>', '<h1 class="x">'];
        yield 'a second heading' => ['<h2 class="x">a</h2>', '<h2 class="x">'];
        yield 'a third heading' => ['<h3 class="x">a</h3>', '<h3 class="x">'];
        yield 'a fourth heading' => ['<h4 class="x">a</h4>', '<h4 class="x">'];
        yield 'a link' => ['<a href="https://x/y" class="c" target="_blank" rel="noopener">go</a>', 'href="https://x/y"'];
        yield 'a link class' => ['<a href="https://x/y" class="c">go</a>', 'class="c"'];
        yield 'a link target' => ['<a href="https://x/y" target="_blank">go</a>', 'target="_blank"'];
        yield 'a link rel' => ['<a href="https://x/y" rel="noopener">go</a>', 'rel="noopener"'];
        // The @ comes back as an entity, which is the sanitizer being careful
        // rather than the scheme being dropped.
        yield 'a mailto link' => ['<a href="mailto:a@x.com">mail</a>', 'href="mailto:a&#64;x.com"'];
        yield 'an image' => ['<img src="https://x/i.png" alt="a" width="10" height="20" class="c">', 'src="https://x/i.png"'];
        yield 'an image alt' => ['<img src="https://x/i.png" alt="a">', 'alt="a"'];
        yield 'an image size' => ['<img src="https://x/i.png" width="10" height="20">', 'width="10"'];
        yield 'a line break' => ['a<br>b', '<br'];
    }

    /**
     * A <style> element ends at the first "</style" whatever the CSS around it
     * says, because the HTML parser does not read CSS. The CSS field was
     * filtered with a blocklist that never looked for it, so an administrator
     * could close the element and open a script after it -- on the SSR event
     * pages, which are public and crawlable. That is precisely what the HTML
     * half of this feature exists to prevent.
     */
    #[DataProvider('cssThatClosesTheStyleElement')]
    public function testCssCannotCloseTheStyleElementItIsPutIn(string $css): void
    {
        $this->put(['custom_css' => $css]);

        $tag = (new CustomHtmlProvider($this->configService()))->getCssStyleTag();

        // What a browser reads as the element's content: up to the first
        // "</style". Nothing may be left over after it.
        $this->assertSame(1, preg_match('#^<style>(.*)</style>$#s', $tag), 'the tag is not a single style element');
        $this->assertDoesNotMatchRegularExpression('#</style#i', (string) preg_replace('#</style>$#', '', $tag));
    }

    /** @return iterable<string, array{string}> */
    public static function cssThatClosesTheStyleElement(): iterable
    {
        yield 'closing and opening a script' => ['body{}</style><script>alert(1)</script><style>'];
        yield 'shouted' => ['body{}</STYLE><script>alert(1)</script>'];
        yield 'mixed case' => ['body{}</StYlE><img src=x onerror=alert(1)>'];
        yield 'inside a comment' => ['/* </style><script>alert(1)</script> */'];
        yield 'inside a string' => ['a:before{content:"</style><script>alert(1)</script>"}'];
    }

    /**
     * The two guards are separate on purpose, and each has to hold on its own.
     * This one is the stored value: what an administrator gets back in the
     * editor, and what any future consumer of the setting reads.
     */
    #[DataProvider('cssThatClosesTheStyleElement')]
    public function testTheStoredStylesheetCannotCloseAStyleElementEither(string $css): void
    {
        $this->put(['custom_css' => $css]);

        $this->assertDoesNotMatchRegularExpression('#</style#i', $this->settings['CUSTOM_CSS']);
    }

    /**
     * And this one is the emission: a row written before any of this existed
     * never went through the sanitizer, and it is still served from here.
     */
    #[DataProvider('cssThatClosesTheStyleElement')]
    public function testAStylesheetStoredBeforeAnyOfThisIsStillSafeToEmit(string $css): void
    {
        // Straight into the setting, the way an older release left it.
        $this->settings['CUSTOM_CSS'] = $css;

        $tag = (new CustomHtmlProvider($this->configService()))->getCssStyleTag();

        $this->assertSame(1, preg_match('#^<style>(.*)</style>$#s', $tag), 'the tag is not a single style element');
        $this->assertDoesNotMatchRegularExpression('#</style#i', (string) preg_replace('#</style>$#', '', $tag));
    }

    public function testTheStyleTagCarriesTheStylesheet(): void
    {
        $this->settings['CUSTOM_CSS'] = 'body{color:red}';

        $this->assertSame(
            '<style>body{color:red}</style>',
            (new CustomHtmlProvider($this->configService()))->getCssStyleTag(),
        );
    }

    #[DataProvider('whatTheProviderReadsBack')]
    public function testTheProviderReadsEachSettingBack(string $setting, string $method): void
    {
        // The SSR pages take their header and trailer through here, not
        // through the controller.
        $this->settings[$setting] = '<p>Configured</p>';

        $provider = new CustomHtmlProvider($this->configService());

        $this->assertSame('<p>Configured</p>', $provider->{$method}());
    }

    /** @return iterable<string, array{string, string}> */
    public static function whatTheProviderReadsBack(): iterable
    {
        yield 'the header' => ['CUSTOM_HEADER_HTML', 'getHeaderHtml'];
        yield 'the trailer' => ['CUSTOM_TRAILER_HTML', 'getTrailerHtml'];
    }

    public function testOrdinaryStylesheetsAreLeftAlone(): void
    {
        $css = 'body { color: #333; } .x > .y { margin: 0 } @media (max-width: 30em) { p { display: none } }';

        $this->put(['custom_css' => $css]);

        $this->assertSame($css, $this->settings['CUSTOM_CSS']);
    }

    #[DataProvider('cssConstructsThatAreBlocked')]
    public function testTheOldFashionedCssAttacksAreStillBlocked(string $css, string $marker): void
    {
        // Each of these is commented out rather than deleted, so the marker
        // going in is what says the construct stopped being one.
        $this->put(['custom_css' => $css]);

        $this->assertStringContainsString($marker, $this->settings['CUSTOM_CSS']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function cssConstructsThatAreBlocked(): iterable
    {
        yield 'expression' => ['width: expression(alert(1))', '/* blocked */('];
        yield 'shouted expression' => ['width: EXPRESSION (alert(1))', '/* blocked */('];
        yield 'an import' => ['@import url(//evil.example/x.css);', '/* @import blocked */'];
        yield 'a behavior' => ['behavior: url(x.htc)', '/* behavior blocked */:'];
        yield 'a binding' => ['-moz-binding: url(x.xml)', '/* binding blocked */:'];
        yield 'a javascript url' => ['background: url(javascript:alert(1))', 'url(/* blocked */'];
    }

    public function testEmptyValuesStayEmptyRatherThanBecomingATag(): void
    {
        $this->put(['custom_css' => '   ', 'header_html' => '   ']);

        $this->assertSame('', $this->settings['CUSTOM_CSS']);
        $this->assertSame('', $this->settings['CUSTOM_HEADER_HTML']);
        $this->assertSame('', (new CustomHtmlProvider($this->configService()))->getCssStyleTag());
    }
}
