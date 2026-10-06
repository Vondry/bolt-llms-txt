<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests\Twig;

use Bolt\Canonical;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tomvondracek\LlmsTxt\SiteUrl;
use Tomvondracek\LlmsTxt\Twig\HtmlToTextExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Markup;

final class HtmlToTextExtensionTest extends TestCase
{
    private function extension(?Request $request = null, ?string $canonicalHomepage = null): HtmlToTextExtension
    {
        $requestStack = new RequestStack([$request ?? Request::create('https://example.com/llms.txt')]);

        $canonical = self::createStub(Canonical::class);
        $canonical->method('get')
            ->willReturn($canonicalHomepage);

        return new HtmlToTextExtension(new SiteUrl($canonical, $requestStack));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function htmlToTextCases(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'null' => [null, ''];
        yield 'non-stringable' => [['array'], ''];
        yield 'plain text keeps as-is' => ['Hello world', 'Hello world'];
        yield 'entities decoded once' => ['Rock &amp; Roll &lt;b&gt;', 'Rock & Roll <b>'];
        yield 'UTF-8' => ['<p>Kuřim – Zámek</p>', 'Kuřim – Zámek'];
        yield 'whitespace collapsed' => ["<p>  a \n\t b&nbsp;c </p>", 'a b c'];
        yield 'paragraphs get a full stop' => ['<p>One</p><p>Two</p>', 'One. Two'];
        yield 'existing punctuation kept' => ['<p>One!</p><p>Two?</p>', 'One! Two?'];
        yield 'list items get semicolons' => ['<ul><li>Adults 600 CZK</li><li>Kids free</li></ul>', 'Adults 600 CZK; Kids free'];
        yield 'headings get a colon' => ['<h2>Tickets</h2><p>From 300 CZK</p>', 'Tickets: From 300 CZK'];
        yield 'line breaks become clause breaks' => ['Line one<br>Line two<br />Line three', 'Line one; Line two; Line three'];
        yield 'closing quote counts as punctuation' => ['<p>„Ahoj.“</p><p>Next</p>', '„Ahoj.“ Next'];
        yield 'text with own punctuation after block' => ['<p>A</p>, more', 'A, more'];
        yield 'empty blocks ignored' => ['<p></p><p>Text</p><p> </p>', 'Text'];
        yield 'rule separates' => ['First<hr>Second', 'First. Second'];
        yield 'script and style dropped' => ['<p>Hi</p><script>alert(1)</script><style>p{}</style>', 'Hi'];
        yield 'image alt kept' => ['<p>Logo <img src="x.png" alt="ACME"></p>', 'Logo ACME'];
        yield 'absolute link' => ['<a href="https://example.org/a">Docs</a>', '[Docs](https://example.org/a)'];
        yield 'site-relative link made absolute' => ['<a href="/about">About</a>', '[About](https://example.com/about)'];
        yield 'protocol-relative link' => ['<a href="//cdn.example.org/x">CDN</a>', '[CDN](https://cdn.example.org/x)'];
        yield 'link without text' => ['<a href="https://example.org"></a>', '<https://example.org>'];
        yield 'mailto labelled with the address' => ['<a href="mailto:info@example.com">info@example.com</a>', 'info@example.com'];
        yield 'mailto with other label' => ['<a href="mailto:info@example.com">Write us</a>', '[Write us](mailto:info@example.com)'];
        yield 'javascript link dropped' => ['<a href="javascript:alert(1)">Click</a>', 'Click'];
        yield 'fragment link dropped' => ['<a href="#top">Top</a>', 'Top'];
        yield 'brackets in link label' => ['<a href="https://example.org">[draft] notes</a>', '[(draft) notes](https://example.org)'];
        yield 'parentheses and spaces in URL escaped' => ['<a href="https://example.org/a (b)">X</a>', '[X](https://example.org/a%20%28b%29)'];
        yield 'angle brackets in URL escaped' => ['<a href="https://example.org/a&gt;b"></a>', '<https://example.org/a%3Eb>'];
        yield 'uppercase scheme' => ['<a href="HTTPS://EXAMPLE.ORG">X</a>', '[X](HTTPS://EXAMPLE.ORG)'];
        yield 'tel labelled with the number' => ['<a href="tel:+420123456789">+420123456789</a>', '+420123456789'];
        yield 'empty link to nowhere' => ['<p>A <a href="javascript:void(0)"></a>B</p>', 'A B'];
        yield 'bare host in rich text is not a link' => ['<a href="www.example.org">X</a>', 'X'];
        yield 'trailing backslash in label dropped' => ['<a href="https://example.org">C:\\</a>', '[C:](https://example.org)'];
        yield 'block inside a link' => ['<a href="https://example.org"><p>One</p><p>Two</p></a>', '[One. Two](https://example.org)'];
        yield 'comment dropped' => ['a<!-- x -->b', 'ab'];
        yield 'rule after a block' => ['<p>A</p><hr><p>B</p>', 'A. B'];
        yield 'empty rule at the start' => ['<hr><p>A</p>', 'A'];
        yield 'block starting with punctuation' => ['<p>A</p><p>, b</p>', 'A, b'];
        yield 'line break inside inline element' => ['<span>A<br></span>B', 'A; B'];
        yield 'nested lists' => ['<ul><li>A<ul><li>A1</li><li>A2</li></ul></li><li>B</li></ul>', 'A; A1; A2; B'];
        yield 'table' => ['<table><tr><th>Name</th><th>Price</th></tr><tr><td>Adult</td><td>600</td></tr></table>', 'Name; Price. Adult; 600'];
        yield 'svg, math and form controls dropped' => ['Hi <svg><text>Icon</text></svg><math><mi>x</mi></math><select><option>One</option></select><textarea>T</textarea>X', 'Hi X'];
        yield 'media fallback dropped' => ['<video>Your browser cannot play this.</video>Text', 'Text'];
        yield 'full document: title dropped' => ['<html><head><title>T</title></head><body><p>X</p></body></html>', 'X'];
        yield 'text after </body> kept' => ['a</body><p>b</p>', 'a; b'];
        yield 'meta charset cannot garble UTF-8' => ['<meta charset="iso-8859-1"><p>Kuřim</p>', 'Kuřim'];
        yield 'deeply nested' => [str_repeat('<div>', 600) . 'deep' . str_repeat('</div>', 600), 'deep'];
        yield 'plain text with whitespace' => ["  Plain\n text  ", 'Plain text'];
    }

    #[DataProvider('htmlToTextCases')]
    public function testHtmlToText(mixed $html, string $expected): void
    {
        self::assertSame($expected, $this->extension()->htmlToText($html));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function maxLengthCases(): iterable
    {
        yield 'short text unchanged' => ['<p>Short</p>', 20, 'Short'];
        yield 'exact length unchanged' => ['Twelve chars', 12, 'Twelve chars'];
        yield 'cut at a word boundary' => ['<p>The quick brown fox jumps</p>', 15, 'The quick…'];
        yield 'cut right before a space' => ['The quick brown fox', 10, 'The quick…'];
        yield 'punctuation before the cut dropped' => ['<p>One, two. Three four</p>', 9, 'One, two…'];
        yield 'blocks still separated' => ['<p>First</p><p>Second paragraph here</p>', 16, 'First. Second…'];
        yield 'links keep only their text' => ['<p>See <a href="https://example.org/a/long/path">the docs</a> now</p>', 200, 'See the docs now'];
        yield 'one long word' => ['Supercalifragilistic', 8, 'Superca…'];
        yield 'max 0' => ['Hello', 0, ''];
        yield 'max 1' => ['Hello', 1, '…'];
        yield 'cut ending on punctuation' => ['Hello, world', 6, 'Hello…'];
        yield 'long text stops early' => [str_repeat('<p>Lorem ipsum dolor sit amet.</p>', 1000), 30, 'Lorem ipsum dolor sit amet…'];
    }

    #[DataProvider('maxLengthCases')]
    public function testHtmlToTextWithMaxLength(string $html, int $maxLength, string $expected): void
    {
        $text = $this->extension()->htmlToText($html, $maxLength);

        self::assertSame($expected, $text);
        self::assertLessThanOrEqual($maxLength, mb_strlen($text));
    }

    /**
     * With a maximum length, the conversion stops reading once it has enough text; the result must be
     * the same as shortening the whole text (plain text skips the parser and is shortened as a whole).
     */
    public function testStoppingEarlyGivesTheSameResultAsShorteningTheWholeText(): void
    {
        $html = '<h2>Tickets</h2><p>Adults <b>600 CZK</b>, kids free.</p><ul><li>Mon–Fri 9–17</li><li>Sat 10–14</li></ul>'
            . '<p>„Quoted.“ Then<br>a break</p><hr><p>Last paragraph with some more words in it</p>';
        $whole = $this->extension()->markdownLabel($html);
        self::assertStringNotContainsString('<', $whole);
        self::assertStringNotContainsString('&', $whole);

        for ($maxLength = 1; $maxLength <= mb_strlen($whole) + 1; ++$maxLength) {
            self::assertSame($this->extension()->htmlToText($whole, $maxLength), $this->extension()->htmlToText($html, $maxLength), "max {$maxLength}");
        }
    }

    public function testAcceptsStringables(): void
    {
        self::assertSame('Bold', $this->extension()->htmlToText(new Markup('<b>Bold</b>', 'UTF-8')));
    }

    public function testSiteRelativeLinkWithoutRequestIsDropped(): void
    {
        $extension = new HtmlToTextExtension(new SiteUrl(self::createStub(Canonical::class), new RequestStack()));

        self::assertSame('About', $extension->htmlToText('<a href="/about">About</a>'));
    }

    public function testMarkdownLabel(): void
    {
        self::assertSame('Band (live) &', $this->extension()->markdownLabel('Band [live] &amp;'));
        self::assertSame('&lt;b&gt;', $this->extension()->markdownLabel('&amp;lt;b&amp;gt;'), 'decoded once only');
        self::assertSame('Tickets at Ticketportal', $this->extension()->markdownLabel('Tickets at <a href="https://ticketportal.cz">Ticketportal</a>'), 'links keep only their text');
        self::assertSame('C:', $this->extension()->markdownLabel('C:\\'), 'a trailing backslash would escape the closing bracket');
        self::assertSame('', $this->extension()->markdownLabel(null));
    }

    public function testSiteRelativeLinksUseTheCanonicalHost(): void
    {
        $extension = $this->extension(Request::create('https://www.example.com:8443/llms.txt'), 'https://example.com/');

        self::assertSame('[About](https://example.com/about)', $extension->htmlToText('<a href="/about">About</a>'));
        self::assertSame('[CDN](https://cdn.example.org/x)', $extension->htmlToText('<a href="//cdn.example.org/x">CDN</a>'));
    }

    public function testSiteRelativeLinksKeepTheCanonicalPort(): void
    {
        $extension = $this->extension(null, 'http://localhost:8000/');

        self::assertSame('[About](http://localhost:8000/about)', $extension->htmlToText('<a href="/about">About</a>'));
    }

    public function testLongTextIsConvertedInLinearTime(): void
    {
        $html = '<ul>' . str_repeat('<li>Lorem ipsum dolor sit amet</li>', 20000) . '</ul>';

        $start = microtime(true);
        $text = $this->extension()->htmlToText($html);
        $seconds = microtime(true) - $start;

        self::assertSame(20000 * 27 - 1 + 19999, mb_strlen($text));
        // Quadratic code took minutes for this; linear code well under a second, even with coverage.
        self::assertLessThan(5, $seconds);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function markdownUrlCases(): iterable
    {
        yield 'https' => ['https://example.org/page', 'https://example.org/page'];
        yield 'bare host gets https' => ['www.band.cz', 'https://www.band.cz'];
        yield 'bare host with path' => ['band.cz/tour?x=1', 'https://band.cz/tour?x=1'];
        yield 'site-relative' => ['/tickets', 'https://example.com/tickets'];
        yield 'entities decoded' => ['https://example.org/?a=1&amp;b=2', 'https://example.org/?a=1&b=2'];
        yield 'empty' => ['', ''];
        yield 'not a URL' => ['just text', ''];
        yield 'javascript' => ['javascript:alert(1)', ''];
        yield 'null' => [null, ''];
        yield 'surrounding whitespace' => ['  https://example.org  ', 'https://example.org'];
        yield 'file name is not a host' => ['page.html', ''];
        yield 'number is not a host' => ['3.14', ''];
        yield 'version is not a host' => ['v1.2', ''];
        yield 'IDN host' => ['www.příklad.cz', 'https://www.příklad.cz'];
        yield 'host with port' => ['www.example.com:8080/x', 'https://www.example.com:8080/x'];
        yield 'host with file path' => ['example.com/page.html', 'https://example.com/page.html'];
        yield 'e-mail address' => ['info@band.cz', 'mailto:info@band.cz'];
        yield 'mailto' => ['mailto:info@band.cz', 'mailto:info@band.cz'];
        yield 'angle brackets escaped' => ['https://example.org/?q=<x>', 'https://example.org/?q=%3Cx%3E'];
        yield 'protocol-relative' => ['//cdn.example.org/x', 'https://cdn.example.org/x'];
        yield 'fragment' => ['#top', ''];
    }

    #[DataProvider('markdownUrlCases')]
    public function testMarkdownUrl(mixed $url, string $expected): void
    {
        self::assertSame($expected, $this->extension()->markdownUrl($url));
    }

    public function testFiltersAreRegisteredInTwig(): void
    {
        $twig = new Environment(new ArrayLoader([
            'llms.txt.twig' => '[{{ title|markdown_label }}]({{ url|markdown_url }}): {{ body|html_to_text }}',
        ]));
        $twig->addExtension($this->extension());

        self::assertSame(
            '[A (b)](https://example.org): One; Two',
            $twig->render('llms.txt.twig', [
                'title' => 'A [b]',
                'url' => 'example.org',
                'body' => '<ul><li>One</li><li>Two</li></ul>',
            ])
        );
    }
}
