<?php

declare(strict_types=1);

namespace Tomvondracek\LlmsTxt\Twig;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Stringable;
use Tomvondracek\LlmsTxt\SiteUrl;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Turns field HTML into single-line plain text for non-HTML outputs such as llms.txt.
 * Links are kept as Markdown links with absolute URLs, so their targets survive.
 *
 * Filters:
 *  - `html_to_text`: rich text or any field value as one line of plain text; with a maximum
 *    length, shortened at a word boundary (links then keep only their text, so no link is cut)
 *  - `markdown_label`: the text only (links too keep only their text), safe as the [label] of a
 *    Markdown link
 *  - `markdown_url`: a URL field as an absolute URL for the (url) of a Markdown link, or ''
 */
final class HtmlToTextExtension extends AbstractExtension
{
    /**
     * Elements whose content is never visible text, or not text a reader would want: a full
     * document's <title>, form controls, embedded graphics and media fallbacks.
     */
    private const SKIPPED = [
        'script' => true,
        'style' => true,
        'template' => true,
        'iframe' => true,
        'object' => true,
        'noscript' => true,
        'head' => true,
        'title' => true,
        'svg' => true,
        'math' => true,
        'select' => true,
        'datalist' => true,
        'textarea' => true,
        'audio' => true,
        'video' => true,
        'canvas' => true,
    ];

    /**
     * Elements that separate words, so their content must not merge with its neighbours. The text is
     * single-line, so the value is the punctuation put after the element's text when more text follows
     * and it has no punctuation of its own — otherwise `<li>Adults 600 CZK</li><li>Kids free</li>` would
     * read "Adults 600 CZK Kids free". Nothing is added at the very end, so a one-paragraph title stays as-is.
     */
    private const BLOCKS = [
        'p' => '.',
        'div' => '.',
        'h1' => ':',
        'h2' => ':',
        'h3' => ':',
        'h4' => ':',
        'h5' => ':',
        'h6' => ':',
        'ul' => '.',
        'ol' => '.',
        'li' => ';',
        'dl' => '.',
        'dt' => ':',
        'dd' => ';',
        'table' => '.',
        'tr' => '.',
        'td' => ';',
        'th' => ';',
        'blockquote' => '.',
        'pre' => '.',
        'figure' => '.',
        'figcaption' => '.',
        'section' => '.',
        'article' => '.',
        'address' => '.',
        'hr' => '.',
    ];

    /**
     * A web address typed without its scheme: host labels, an alphabetic top-level domain that is
     * not a common file extension (`page.html` is a file, not a host), an optional port and path.
     */
    private const BARE_HOST = '~^(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+(?!(?:html?|php|aspx?|jsp|pdf|jpe?g|png|gif|webp|svg|docx?|xlsx?|pptx?|txt|csv)(?:[:/?#]|$))\p{L}{2,}(?::\d{1,5})?(?:[/?#]\S*)?$~iu';

    private const EMAIL = '~^[^\s@/:<>()\[\]]+@(?:[\p{L}\p{N}-]+\.)+\p{L}{2,}$~u';

    public function __construct(
        private readonly SiteUrl $siteUrl,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('html_to_text', $this->htmlToText(...)),
            new TwigFilter('markdown_label', $this->markdownLabel(...)),
            new TwigFilter('markdown_url', $this->markdownUrl(...)),
        ];
    }

    /**
     * Accepts anything Twig may hand over (strings, Twig Markup, Bolt fields). Text-type
     * Bolt fields are entity-encoded by Bolt's sanitiser too, so they need this as well.
     */
    public function htmlToText(mixed $html, ?int $maxLength = null): string
    {
        // With a maximum length, the rest of a long text is not even read.
        $text = $this->text($html, $maxLength === null, $maxLength);

        return $maxLength === null ? $text : $this->shorten($text, $maxLength);
    }

    /**
     * Like html_to_text, made safe for the [label] part of a Markdown link. Takes the raw field:
     * passing text that html_to_text already decoded would decode entities twice (`&lt;` → tag).
     */
    public function markdownLabel(mixed $text): string
    {
        return $this->label($this->text($text, false));
    }

    /**
     * Turns a URL field (raw, so entities are decoded once) into an absolute URL usable as the (url)
     * part of a Markdown link, or '' if it is not a link a reader can follow. Unlike links inside
     * rich text, a bare host such as `www.example.com` counts as a web address here, and an e-mail
     * address as a mailto: link: URL fields are typed in by editors, who often leave out the scheme.
     */
    public function markdownUrl(mixed $url): string
    {
        $url = is_scalar($url) || $url instanceof Stringable ? (string) $url : '';

        return $this->absoluteUrl(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true) ?? '';
    }

    private function text(mixed $html, bool $links, ?int $maxLength = null): string
    {
        $html = is_scalar($html) || $html instanceof Stringable ? (string) $html : '';

        // Plain text, such as most titles, needs no parsing.
        if (! str_contains($html, '<') && ! str_contains($html, '&')) {
            return PlainTextBuffer::collapse($html);
        }

        // libxml drops everything after a </body> or </html> in the field.
        $html = preg_replace('~</(?:body|html)\s*>~i', '', $html) ?? $html;

        $document = new DOMDocument();
        $useInternalErrors = libxml_use_internal_errors(true);
        // Only ASCII reaches libxml, so neither its Latin-1 default nor a <meta charset> in the
        // field can garble the text. LIBXML_PARSEHUGE lifts the nesting limit of 256 levels.
        $document->loadHTML(
            '<body>' . mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8') . '</body>',
            LIBXML_NONET | LIBXML_PARSEHUGE
        );
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        // The parser always creates the <body> element.
        $buffer = new PlainTextBuffer($maxLength);
        $this->walk($document->getElementsByTagName('body')->item(0) ?? $document, $buffer, $links);

        return $buffer->toString();
    }

    private function walk(DOMNode $node, PlainTextBuffer $buffer, bool $links): void
    {
        foreach ($node->childNodes as $child) {
            if ($buffer->isFull()) {
                return;
            }

            if ($child instanceof DOMText) {
                $buffer->write($child->data);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = mb_strtolower($child->tagName);
            if (isset(self::SKIPPED[$tag])) {
                continue;
            }

            if ($tag === 'br') {
                // A line break inside a paragraph ends the line's clause, like a list item.
                $buffer->setPending($buffer->pending() ?? ';');
                $buffer->space();

                continue;
            }

            if ($tag === 'img') {
                // An image's alt text is the text a reader would get instead of the picture.
                $buffer->write($child->getAttribute('alt'));

                continue;
            }

            if ($tag === 'a' && $links) {
                $inner = new PlainTextBuffer();
                $this->walk($child, $inner, true);
                $buffer->write($this->markdownLink($child->getAttribute('href'), $inner->toString()));

                continue;
            }

            $punctuation = self::BLOCKS[$tag] ?? null;
            if ($punctuation === null) {
                $this->walk($child, $buffer, $links);

                continue;
            }

            // A block starts a new clause, and ends one once it has text.
            $before = $buffer->pending();
            $writes = $buffer->writes();
            $buffer->space();
            $buffer->setPending($before ?? ';');
            $this->walk($child, $buffer, $links);
            $buffer->space();

            if ($buffer->writes() > $writes) {
                $buffer->setPending($punctuation);
            } else {
                // A rule has no text of its own but still separates what is around it.
                $buffer->setPending($tag === 'hr' ? ($before ?? $punctuation) : $before);
            }
        }
    }

    private function markdownLink(string $href, string $text): string
    {
        $href = mb_trim($href);

        // `mailto:x@y.cz` labelled `x@y.cz` (or `tel:` labelled with the number) reads fine as-is.
        if (preg_match('~^(mailto|tel):(.*)$~i', $href, $match) === 1 && PlainTextBuffer::collapse($match[2]) === $text) {
            return $text;
        }

        $href = $this->absoluteUrl($href, false);
        if ($href === null) {
            return $text;
        }

        return $text === '' ? '<' . $href . '>' : sprintf('[%s](%s)', $this->label($text), $href);
    }

    /**
     * Absolute, Markdown-safe form of `$href`, or null when there is no page it could point to.
     */
    private function absoluteUrl(string $href, bool $bareHostIsWeb): ?string
    {
        $href = mb_trim($href);

        if ($bareHostIsWeb && preg_match(self::BARE_HOST, $href) === 1) {
            // Before the scheme check, which would read `www.example.com:8080` as scheme and path.
            $href = 'https://' . $href;
        } elseif ($bareHostIsWeb && preg_match(self::EMAIL, $href) === 1) {
            $href = 'mailto:' . $href;
        } elseif (preg_match('~^([a-z][a-z0-9+.-]*):~i', $href, $match) === 1) {
            // Anything but these (javascript:, data:, …) is not a link a reader can follow.
            if (! in_array(mb_strtolower($match[1]), ['http', 'https', 'mailto', 'tel'], true)) {
                return null;
            }
        } elseif (str_starts_with($href, '/')) {
            // Site-relative links are useless outside the page, so make them absolute.
            $origin = $this->siteUrl->origin();
            if ($origin === null) {
                return null;
            }
            $href = str_starts_with($href, '//') ? mb_strstr($origin, '//', true) . $href : $origin . $href;
        } else {
            // Empty, fragment-only (#anchor) or page-relative: there is no page to resolve it against.
            return null;
        }

        // Spaces, parentheses and angle brackets would end the (url) or <autolink> early.
        return preg_replace_callback('/[\x00-\x20()<>]/', static fn (array $match): string => rawurlencode($match[0]), $href) ?? $href;
    }

    /**
     * Makes text safe as the [label] of a Markdown link. Square brackets become round ones rather than
     * being backslash-escaped, because simple llms.txt parsers match the label as `\[[^\]]+\]` and
     * would keep the backslashes. With no brackets left, only a backslash at the very end could still
     * escape the closing bracket, so it is dropped.
     */
    private function label(string $text): string
    {
        return mb_rtrim(strtr($text, ['[' => '(', ']' => ')']), '\\');
    }

    /**
     * Cuts text longer than `$maxLength` characters at the last word boundary and
     * ends it with an ellipsis, which counts towards the length.
     */
    private function shorten(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        if ($maxLength < 1) {
            return '';
        }

        $cut = mb_substr($text, 0, $maxLength - 1);
        // The cut ends a word when the next character is a space or punctuation.
        $endsWord = preg_match('/^[\s.,;:!?…)\]]/u', mb_substr($text, $maxLength - 1, 1)) === 1;
        $lastSpace = mb_strrpos($cut, ' ');
        if (! $endsWord && $lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return mb_rtrim($cut, " \t.,;:") . '…';
    }
}
