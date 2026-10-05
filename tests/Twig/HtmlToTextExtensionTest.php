<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tomvondracek\LlmsTxt\Twig\HtmlToTextExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Markup;

final class HtmlToTextExtensionTest extends TestCase
{
    private function extension(): HtmlToTextExtension
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://example.com/llms.txt'));

        return new HtmlToTextExtension($requestStack);
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
    }

    #[DataProvider('maxLengthCases')]
    public function testHtmlToTextWithMaxLength(string $html, int $maxLength, string $expected): void
    {
        $text = $this->extension()->htmlToText($html, $maxLength);

        self::assertSame($expected, $text);
        self::assertLessThanOrEqual($maxLength, mb_strlen($text));
    }

    public function testAcceptsStringables(): void
    {
        self::assertSame('Bold', $this->extension()->htmlToText(new Markup('<b>Bold</b>', 'UTF-8')));
    }

    public function testSiteRelativeLinkWithoutRequestIsDropped(): void
    {
        self::assertSame('About', (new HtmlToTextExtension(new RequestStack()))->htmlToText('<a href="/about">About</a>'));
    }

    public function testMarkdownLabel(): void
    {
        self::assertSame('Band (live) &', $this->extension()->markdownLabel('Band [live] &amp;'));
        self::assertSame('&lt;b&gt;', $this->extension()->markdownLabel('&amp;lt;b&amp;gt;'), 'decoded once only');
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
